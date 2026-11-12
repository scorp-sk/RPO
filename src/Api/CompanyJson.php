<?php

declare(strict_types=1);

namespace Rpo\Api;

/**
 * Converts a company table row into the response JSON object.
 * The row is selected as "SELECT COLUMNS FROM FROM", so it carries the IČ DPH of a registered VAT payer.
 */
final class CompanyJson
{
    public const string COLUMNS = 'c.id, c.ico, c.name, c.street, c.reg_number, c.building_number, c.postal_code, c.municipality,'
        . ' c.establishment, c.termination, c.source_register_code, c.registration_office, c.registration_number, v.vat_id';

    public const string FROM = 'company c LEFT JOIN vat_payer v ON v.ico = c.ico';

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function fromRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'identifier' => $row['ico'],
            'fullName' => $row['name'],
            'address' => [
                'street' => $row['street'],
                'regNumber' => $row['reg_number'] === null ? null : (int) $row['reg_number'],
                'buildingNumber' => $row['building_number'],
                'postalCode' => $row['postal_code'],
                'municipality' => $row['municipality'],
            ],
            'establishment' => $row['establishment'],
            'termination' => $row['termination'],
            'sourceRegister' => $row['source_register_code'] === null ? null : [
                'code' => $row['source_register_code'],
                'registrationOffice' => $row['registration_office'],
                'registrationNumber' => $row['registration_number'],
            ],
            'vatId' => $row['vat_id'],
        ];
    }
}
