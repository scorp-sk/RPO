<?php

declare(strict_types=1);

namespace Rpo\Vat;

use Rpo\Import\BucketClient;

/**
 * Imports the list of registered VAT payers (Zoznam registrovaných platiteľov DPH) published
 * by the Financial Administration of the SR as a zip with one XML file. The whole list is loaded
 * into the staging table vat_payer_new and atomically swapped with vat_payer.
 */
final class VatImporter
{
    private const string XML_ENTRY = 'ds_dphs.xml';
    private const int MAX_ROWS = 1000;

    /** A smaller list means a broken file; the live table is kept (the real list has ~320k payers). */
    private const int MIN_RECORDS = 100000;

    /** @var \Closure(string): void */
    private \Closure $log;

    /** @var \Closure(int): void */
    private \Closure $progress;

    /** @var list<array{string, ?string, ?string}> */
    private array $buffer = [];

    private int $skipped = 0;

    public function __construct(
        private readonly \PDO $pdo,
        private readonly string $url,
        private readonly string $tmpDir,
    ) {
        $this->log = static function (string $message): void {
        };
        $this->progress = static function (int $records): void {
        };
    }

    /**
     * @param \Closure(string): void $log
     * @param \Closure(int): void $progress called with the number of payers processed so far
     */
    public function setOutput(\Closure $log, \Closure $progress): void
    {
        $this->log = $log;
        $this->progress = $progress;
    }

    /**
     * Downloads the list and imports it unless that list date is already imported.
     *
     * @return int|null number of imported payers, null if nothing was imported
     */
    public function sync(bool $force = false, bool $dryRun = false): ?int
    {
        $path = $this->fetch();
        try {
            $reader = new \XMLReader();
            if (!$reader->open('zip://' . $path . '#' . self::XML_ENTRY)) {
                throw new \RuntimeException("Cannot open " . self::XML_ENTRY . " in {$path}");
            }
            try {
                $date = $this->readListDate($reader);
                if (!$force && $this->isImported($date)) {
                    ($this->log)("VAT payer list {$date} is up to date");

                    return null;
                }
                if ($dryRun) {
                    ($this->log)("Would import VAT payer list {$date}");

                    return null;
                }

                return $this->import($reader, $date);
            } finally {
                $reader->close();
            }
        } finally {
            @unlink($path);
        }
    }

    private function import(\XMLReader $reader, string $date): int
    {
        ($this->log)("VAT payer list {$date}");

        $this->pdo->exec('DROP TABLE IF EXISTS vat_payer_new');
        $this->pdo->exec('CREATE TABLE vat_payer_new LIKE vat_payer');

        $this->skipped = 0;
        $count = 0;
        while ($reader->read() && !($reader->nodeType === \XMLReader::ELEMENT && $reader->name === 'ITEM')) {
        }
        while ($reader->nodeType === \XMLReader::ELEMENT && $reader->name === 'ITEM') {
            $item = simplexml_load_string($reader->readOuterXml());
            if ($item === false) {
                throw new \RuntimeException('Invalid ITEM in the VAT payer list');
            }
            $this->add($item);
            if (++$count % 1000 === 0) {
                ($this->progress)($count);
            }
            $reader->next('ITEM');
        }
        $this->flush();
        ($this->progress)($count);

        $imported = (int) $this->pdo->query('SELECT COUNT(*) FROM vat_payer_new')->fetchColumn();
        ($this->log)("    {$imported} rows, {$this->skipped} skipped (no or invalid IČ DPH, duplicate IČO)");
        if ($imported < self::MIN_RECORDS) {
            $this->pdo->exec('DROP TABLE IF EXISTS vat_payer_new');
            throw new \RuntimeException("VAT payer list {$date} has only {$imported} payers, keeping the current data");
        }

        $this->pdo->exec('RENAME TABLE vat_payer TO vat_payer_old, vat_payer_new TO vat_payer');
        $this->pdo->exec('DROP TABLE IF EXISTS vat_payer_old');
        $this->pdo->prepare('REPLACE INTO vat_import (list_date, records, imported_at) VALUES (?, ?, UTC_TIMESTAMP())')
            ->execute([$date, $imported]);
        ($this->log)("VAT payer list {$date} activated");

        return $imported;
    }

    private function add(\SimpleXMLElement $item): void
    {
        $vatId = strtoupper(trim((string) $item->IC_DPH));
        if (!preg_match('/^SK\d{10}$/', $vatId)) {
            $this->skipped++;

            return;
        }
        $ico = trim((string) $item->ICO);
        $name = trim((string) $item->NAZOV_DS);
        $this->buffer[] = [$vatId, $ico === '' ? null : $ico, $name === '' ? null : mb_substr($name, 0, 255)];

        if (count($this->buffer) >= self::MAX_ROWS) {
            $this->flush();
        }
    }

    /**
     * INSERT IGNORE: a repeated IČO keeps its first occurrence.
     */
    private function flush(): void
    {
        if ($this->buffer === []) {
            return;
        }
        $sql = 'INSERT IGNORE INTO vat_payer_new (vat_id, ico, name) VALUES '
            . implode(',', array_fill(0, count($this->buffer), '(?,?,?)'));
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_merge(...$this->buffer));
        $this->skipped += count($this->buffer) - $stmt->rowCount();
        $this->buffer = [];
    }

    /**
     * Reads DatumAktualizacieZoznamu (ddmmyyyy) at the start of the file.
     */
    private function readListDate(\XMLReader $reader): string
    {
        while ($reader->read()) {
            if ($reader->nodeType === \XMLReader::ELEMENT && $reader->name === 'DatumAktualizacieZoznamu') {
                $value = trim($reader->readString());
                if (!preg_match('/^(\d{2})(\d{2})(\d{4})$/', $value, $m) || !checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
                    throw new \RuntimeException("Invalid DatumAktualizacieZoznamu: {$value}");
                }

                return "{$m[3]}-{$m[2]}-{$m[1]}";
            }
            if ($reader->nodeType === \XMLReader::ELEMENT && $reader->name === 'ITEM') {
                break;
            }
        }
        throw new \RuntimeException('DatumAktualizacieZoznamu not found in the VAT payer list');
    }

    private function isImported(string $date): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM vat_import WHERE list_date = ?');
        $stmt->execute([$date]);

        return $stmt->fetchColumn() !== false;
    }

    private function fetch(): string
    {
        if (!is_dir($this->tmpDir) && !mkdir($this->tmpDir, 0775, true) && !is_dir($this->tmpDir)) {
            throw new \RuntimeException("Cannot create {$this->tmpDir}");
        }
        $path = $this->tmpDir . '/vat-payers.zip';
        ($this->log)("Downloading {$this->url}");
        BucketClient::downloadUrl($this->url, $path);

        return $path;
    }
}
