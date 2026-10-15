<?php

declare(strict_types=1);

namespace Rpo\Import;

/**
 * Synchronizes the DB with the RPO export storage:
 *  1. the latest complete init batch is imported into staging tables (*_new)
 *     and atomically swapped with the live tables,
 *  2. then daily batches dated after the init batch are applied one by one.
 */
final class Importer
{
    private const array TABLES = ['company'];
    private const string INIT_PREFIX = 'batch-init/';
    private const string DAILY_PREFIX = 'batch-daily/';

    /** @var \Closure(string): void */
    private \Closure $log;

    /** @var \Closure(int): void */
    private \Closure $progress;

    public function __construct(
        private readonly \PDO $pdo,
        private readonly BucketClient $bucket,
        private readonly string $tmpDir,
        private readonly CompanyMapper $mapper = new CompanyMapper(),
    ) {
        $this->log = static function (string $message): void {
        };
        $this->progress = static function (int $records): void {
        };
    }

    /**
     * @param \Closure(string): void $log
     * @param \Closure(int): void $progress called with the number of records processed in the current file
     */
    public function setOutput(\Closure $log, \Closure $progress): void
    {
        $this->log = $log;
        $this->progress = $progress;
    }

    /**
     * Determines what needs to be imported.
     *
     * @return array{init: ?array{date: string, files: list<array{key: string, size: int, etag: string}>}, daily: list<array{key: string, size: int, etag: string, date: string}>, initDate: ?string}
     */
    public function plan(bool $forceInit = false): array
    {
        $appliedInit = $this->lastInitDate();
        $latest = $this->latestCompleteInit();

        $init = null;
        if ($latest !== null && ($forceInit || $appliedInit === null || $latest['date'] > $appliedInit)) {
            $init = $latest;
        }
        $initDate = $init['date'] ?? $appliedInit;
        if ($initDate === null) {
            throw new \RuntimeException('No complete init batch found in storage');
        }

        // After a new init batch, daily batches with a later date are applied again
        $applied = $init === null ? $this->appliedDailyKeys() : [];
        $daily = [];
        foreach ($this->bucket->list(self::DAILY_PREFIX) as $object) {
            if (!preg_match('#^batch-daily/actual_(\d{4}-\d{2}-\d{2})\.json\.gz$#', $object['key'], $m)) {
                continue;
            }
            if ($m[1] > $initDate && !isset($applied[$object['key']])) {
                $daily[] = $object + ['date' => $m[1]];
            }
        }
        usort($daily, fn ($a, $b) => strcmp($a['date'], $b['date']));

        return ['init' => $init, 'daily' => $daily, 'initDate' => $initDate];
    }

    public function sync(bool $forceInit = false): void
    {
        $plan = $this->plan($forceInit);

        if ($plan['init'] !== null) {
            $this->runInit($plan['init']['date'], $plan['init']['files']);
        } else {
            ($this->log)("Init batch {$plan['initDate']} is up to date");
        }

        foreach ($plan['daily'] as $object) {
            $this->runDaily($object);
        }
        if ($plan['daily'] === []) {
            ($this->log)('No new daily batches');
        }
    }

    /**
     * @param list<array{key: string, size: int, etag: string}> $files
     */
    public function runInit(string $date, array $files): void
    {
        ($this->log)("Init batch {$date}: " . count($files) . ' files');

        foreach (self::TABLES as $table) {
            $this->pdo->exec("DROP TABLE IF EXISTS {$table}_new");
            $this->pdo->exec("CREATE TABLE {$table}_new LIKE {$table}");
        }
        $this->pdo->exec('SET SESSION unique_checks = 0, foreign_key_checks = 0');

        $writer = new RecordWriter($this->pdo, '_new');
        $total = 0;
        $counts = [];
        foreach ($files as $i => $object) {
            ($this->log)(sprintf('[%d/%d] %s (%.1f MB)', $i + 1, count($files), $object['key'], $object['size'] / 1048576));
            $path = $this->fetch($object);
            try {
                $count = $this->importFile($path, $writer);
            } finally {
                @unlink($path);
            }
            $total += $count;
            $counts[$object['key']] = $count;
            ($this->log)("    {$count} records");
        }
        $writer->flush();
        $this->pdo->exec('SET SESSION unique_checks = 1, foreign_key_checks = 1');

        ($this->log)("Total {$total} records");

        $renames = [];
        foreach (self::TABLES as $table) {
            $renames[] = "{$table} TO {$table}_old, {$table}_new TO {$table}";
        }
        $this->pdo->exec('RENAME TABLE ' . implode(', ', $renames));
        foreach (self::TABLES as $table) {
            $this->pdo->exec("DROP TABLE IF EXISTS {$table}_old");
        }

        // Daily batches dated after the init batch must be re-applied on top of the new data
        $this->pdo->prepare("DELETE FROM import_log WHERE kind = 'daily' AND export_date > ?")->execute([$date]);
        foreach ($files as $object) {
            $this->logImport($object, 'init', $date, $counts[$object['key']] ?? 0);
        }
        ($this->log)("Init batch {$date} activated");
    }

    /**
     * @param array{key: string, size: int, etag: string, date: string} $object
     */
    public function runDaily(array $object): void
    {
        $path = $this->fetch($object);
        try {
            $this->importDailyFile($path, $object['date'], $object);
        } finally {
            @unlink($path);
        }
    }

    /**
     * Imports one daily file into the live tables (in a single transaction).
     *
     * @param array{key: string, size: int, etag: string}|null $object for the import_log entry
     */
    public function importDailyFile(string $path, string $fallbackDate, ?array $object = null): int
    {
        $exportDate = $this->peekExportDate($path) ?? $fallbackDate;
        ($this->log)('Daily batch ' . ($object['key'] ?? $path) . " (exportDate {$exportDate})");

        $this->pdo->beginTransaction();
        try {
            $writer = new RecordWriter($this->pdo);
            $count = $this->importFile($path, $writer);
            $writer->flush();
            if ($object !== null) {
                $this->logImport($object, 'daily', $exportDate, $count);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        ($this->log)("    {$count} records");

        return $count;
    }

    private function importFile(string $path, RecordWriter $writer): int
    {
        $reader = new BatchReader($path);
        $count = 0;
        foreach ($reader->records() as $raw) {
            $writer->add($this->mapper->map($raw));
            if (++$count % 1000 === 0) {
                ($this->progress)($count);
            }
        }
        ($this->progress)($count);

        return $count;
    }

    /**
     * @param array{key: string, size: int, etag: string} $object
     */
    private function fetch(array $object): string
    {
        if (!is_dir($this->tmpDir) && !mkdir($this->tmpDir, 0775, true) && !is_dir($this->tmpDir)) {
            throw new \RuntimeException("Cannot create {$this->tmpDir}");
        }
        $path = $this->tmpDir . '/' . basename($object['key']);
        $this->bucket->download($object['key'], $path, $object['size']);

        return $path;
    }

    private function peekExportDate(string $path): ?string
    {
        $handle = gzopen($path, 'rb');
        if ($handle === false) {
            return null;
        }
        $head = (string) gzread($handle, 64);
        gzclose($handle);

        return preg_match('/^\{"exportDate":"(\d{4}-\d{2}-\d{2})"/', $head, $m) ? $m[1] : null;
    }

    /**
     * The latest init batch whose files listed in _list.txt are all present in storage.
     *
     * @return array{date: string, files: list<array{key: string, size: int, etag: string}>}|null
     */
    private function latestCompleteInit(): ?array
    {
        $objects = $this->bucket->list(self::INIT_PREFIX);
        $dates = [];
        foreach (array_keys($objects) as $key) {
            if (preg_match('#^batch-init/init_(\d{4}-\d{2}-\d{2})_list\.txt$#', $key, $m)) {
                $dates[] = $m[1];
            }
        }
        rsort($dates);

        foreach ($dates as $date) {
            $listed = preg_split('/\s+/', trim($this->bucket->getText(self::INIT_PREFIX . "init_{$date}_list.txt"))) ?: [];
            $files = [];
            foreach ($listed as $name) {
                $key = self::INIT_PREFIX . $name;
                if (!isset($objects[$key])) {
                    ($this->log)("Init batch {$date} is incomplete (missing {$name}), skipping");
                    continue 2;
                }
                $files[] = $objects[$key];
            }
            if ($files !== []) {
                return ['date' => $date, 'files' => $files];
            }
        }

        return null;
    }

    private function lastInitDate(): ?string
    {
        $date = $this->pdo->query("SELECT MAX(export_date) FROM import_log WHERE kind = 'init'")->fetchColumn();

        return $date === null || $date === false ? null : (string) $date;
    }

    /**
     * @return array<string, true>
     */
    private function appliedDailyKeys(): array
    {
        $keys = $this->pdo->query("SELECT file_key FROM import_log WHERE kind = 'daily'")->fetchAll(\PDO::FETCH_COLUMN);

        return array_fill_keys($keys, true);
    }

    /**
     * @param array{key: string, size: int, etag: string} $object
     */
    private function logImport(array $object, string $kind, string $date, int $records): void
    {
        $this->pdo->prepare(
            'REPLACE INTO import_log (file_key, kind, export_date, etag, records, imported_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())'
        )->execute([$object['key'], $kind, $date, $object['etag'], $records]);
    }
}
