<?php

declare(strict_types=1);

namespace Rpo\Api;

/**
 * Which day the data in the DB is from: the export date of the last imported RPO batch.
 */
final class DataVersion
{
    public static function lastUpdate(\PDO $pdo): ?string
    {
        $date = $pdo->query('SELECT MAX(export_date) FROM import_log')->fetchColumn();

        return $date === false || $date === null ? null : (string) $date;
    }

    /**
     * Date of the last imported list of registered VAT payers.
     */
    public static function vatLastUpdate(\PDO $pdo): ?string
    {
        $date = $pdo->query('SELECT MAX(list_date) FROM vat_import')->fetchColumn();

        return $date === false || $date === null ? null : (string) $date;
    }
}
