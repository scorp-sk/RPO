<?php

declare(strict_types=1);

namespace Rpo\Api;

/**
 * Converts a company table row into the response JSON object.
 */
final class CompanyJson
{
    public const string COLUMNS = 'id, ico, name, street, reg_number, building_number, postal_code, municipality, establishment, termination,'
        . ' source_register_code, registration_office, registration_number';

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
        ];
    }
}
