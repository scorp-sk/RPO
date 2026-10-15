<?php

declare(strict_types=1);

namespace Rpo\Import;

/**
 * Batched writes of records (multi-row upsert) into the company table.
 * The suffix allows writing into the staging table (company_new) during the init import.
 */
final class RecordWriter
{
    private const int MAX_ROWS = 1000;

    /** @var array<int, CompanyRecord> */
    private array $buffer = [];

    public function __construct(
        private readonly \PDO $pdo,
        private readonly string $suffix = '',
    ) {
    }

    public function add(CompanyRecord $record): void
    {
        // The same id may occur several times in one batch – the last occurrence wins
        $this->buffer[$record->id] = $record;

        if (count($this->buffer) >= self::MAX_ROWS) {
            $this->flush();
        }
    }

    public function flush(): void
    {
        if ($this->buffer === []) {
            return;
        }

        $columns = CompanyRecord::COLUMNS;
        $row = '(' . implode(',', array_fill(0, count($columns), '?')) . ')';
        $updates = implode(',', array_map(fn ($c) => "{$c}=VALUES({$c})", array_slice($columns, 1)));
        $sql = "INSERT INTO company{$this->suffix} (" . implode(',', $columns) . ') VALUES '
            . implode(',', array_fill(0, count($this->buffer), $row))
            . " ON DUPLICATE KEY UPDATE {$updates}";

        $params = [];
        foreach ($this->buffer as $record) {
            array_push($params, ...$record->values());
        }
        $this->buffer = [];

        $this->pdo->prepare($sql)->execute($params);
    }
}
