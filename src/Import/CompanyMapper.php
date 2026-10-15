<?php

declare(strict_types=1);

namespace Rpo\Import;

/**
 * Picks the current name, registered address, IČO (company ID) and source register entry
 * (register, court/office, registration number) from an exported RPO record, which holds the
 * full history. The current entry is the one without validTo; if there are several, the one
 * with the latest validFrom wins.
 */
final class CompanyMapper
{
    private const string UNKNOWN_VALUE = 'Neuvedené';

    public function map(object $raw): CompanyRecord
    {
        $address = $this->current($raw->addresses ?? []);
        $register = $raw->sourceRegister ?? null;

        // Terminated entities have no current IČO – the last known one is used so they stay searchable
        $ico = $this->current($raw->identifiers ?? [])->value
            ?? $this->latest($raw->identifiers ?? [])->value
            ?? null;

        return new CompanyRecord(
            id: (int) $raw->id,
            ico: is_string($ico) && $ico !== '' && strlen($ico) <= 32 ? $ico : null,
            name: $this->string($this->current($raw->fullNames ?? [])->value ?? null, 1024),
            street: $this->string($address->street ?? null, 255),
            regNumber: isset($address->regNumber) && is_int($address->regNumber) && $address->regNumber > 0 ? $address->regNumber : null,
            buildingNumber: $this->string($address->buildingNumber ?? null, 64),
            postalCode: $this->string(isset($address->postalCodes[0]) ? preg_replace('/\s+/', '', (string) $address->postalCodes[0]) : null, 16),
            municipality: $this->string($address->municipality->value ?? null, 255),
            establishment: $this->date($raw->establishment ?? null),
            termination: $this->date($raw->termination ?? null),
            sourceRegisterCode: $this->string($register->value->code ?? null, 16),
            registrationOffice: $this->string($this->current($register->registrationOffices ?? [])->value ?? null, 255),
            registrationNumber: $this->string($this->current($register->registrationNumbers ?? [])->value ?? null, 128),
        );
    }

    /**
     * The current entry (without validTo) with the latest validFrom.
     *
     * @param list<object> $entries
     */
    private function current(array $entries): ?object
    {
        return $this->latest(array_filter($entries, fn ($e) => !isset($e->validTo)));
    }

    /**
     * The entry with the latest validFrom (the placeholder value "Neuvedené" – "not stated" – is skipped).
     *
     * @param iterable<object> $entries
     */
    private function latest(iterable $entries): ?object
    {
        $best = null;
        foreach ($entries as $entry) {
            if (($entry->value ?? null) === self::UNKNOWN_VALUE) {
                continue;
            }
            if ($best === null || ($entry->validFrom ?? '') >= ($best->validFrom ?? '')) {
                $best = $entry;
            }
        }

        return $best;
    }

    private function string(mixed $value, int $maxLength): ?string
    {
        if (!is_string($value) || ($value = trim($value)) === '') {
            return null;
        }

        return mb_substr($value, 0, $maxLength);
    }

    private function date(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }
}
