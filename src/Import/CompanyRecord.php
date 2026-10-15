<?php

declare(strict_types=1);

namespace Rpo\Import;

/**
 * A row of the company table.
 */
final readonly class CompanyRecord
{
    public const array COLUMNS = [
        'id', 'ico', 'name', 'street', 'reg_number', 'building_number', 'postal_code', 'municipality', 'establishment', 'termination',
        'source_register_code', 'registration_office', 'registration_number',
    ];

    public function __construct(
        public int $id,
        public ?string $ico,
        public ?string $name,
        public ?string $street,
        public ?int $regNumber,
        public ?string $buildingNumber,
        public ?string $postalCode,
        public ?string $municipality,
        public ?string $establishment,
        public ?string $termination,
        public ?string $sourceRegisterCode,
        public ?string $registrationOffice,
        public ?string $registrationNumber,
    ) {
    }

    /**
     * Values in COLUMNS order.
     *
     * @return list<int|string|null>
     */
    public function values(): array
    {
        return [
            $this->id, $this->ico, $this->name, $this->street, $this->regNumber, $this->buildingNumber,
            $this->postalCode, $this->municipality, $this->establishment, $this->termination,
            $this->sourceRegisterCode, $this->registrationOffice, $this->registrationNumber,
        ];
    }
}
