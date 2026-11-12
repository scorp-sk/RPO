<?php

declare(strict_types=1);

namespace Rpo\Api;

use Rpo\Http\JsonResponse;
use Rpo\Http\Request;

/**
 * GET /vat?vatId={IČ DPH} – is the IČ DPH in the list of registered VAT payers of the Financial
 * Administration of the SR? GET /vat?identifier={IČO} – the IČ DPH of a registered payer by IČO.
 *
 * Both parameters may repeat (up to 100 values). "results" always has one item per distinct
 * requested value, in request order.
 */
final readonly class VatController
{
    private const int MAX_URI_LENGTH = 4096;
    private const int MAX_VALUES = 100;
    private const string SOURCE = 'Finančná správa SR – Zoznam registrovaných platiteľov DPH';

    public function __construct(private \PDO $pdo)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $vatIds = $request->all('vatId');
        $icos = $request->all('identifier');
        if ($vatIds === null && $icos === null) {
            return JsonResponse::error(400, 'No parameters specified in search request', $request->uri);
        }
        if ($vatIds !== null && $icos !== null) {
            return JsonResponse::error(400, 'Invalid input parameter', $request->uri);
        }
        $values = array_map('trim', $vatIds ?? $icos);
        if (in_array('', $values, true)) {
            return JsonResponse::error(400, 'Request not understood', $request->uri);
        }
        if ($vatIds !== null) {
            $values = array_map(self::normalizeVatId(...), $values);
        }
        $values = array_values(array_unique($values));
        if (strlen($request->uri) > self::MAX_URI_LENGTH || count($values) > self::MAX_VALUES) {
            return JsonResponse::error(400, 'Invalid input parameter', $request->uri);
        }

        return JsonResponse::ok([
            'results' => $vatIds !== null ? $this->byVatId($values) : $this->byIco($values),
            'lastUpdate' => DataVersion::vatLastUpdate($this->pdo),
            'source' => self::SOURCE,
        ]);
    }

    /**
     * @param list<string> $vatIds
     * @return list<array<string, mixed>>
     */
    private function byVatId(array $vatIds): array
    {
        $wellFormed = array_values(array_filter($vatIds, fn ($v) => preg_match('/^SK\d{10}$/', $v) === 1));
        $found = $this->find('vat_id', $wellFormed);

        return array_map(fn ($v) => self::item($found[$v] ?? null, vatId: $v), $vatIds);
    }

    /**
     * @param list<string> $icos
     * @return list<array<string, mixed>>
     */
    private function byIco(array $icos): array
    {
        $found = $this->find('ico', $icos);

        return array_map(fn ($ico) => self::item($found[$ico] ?? null, ico: $ico), $icos);
    }

    /**
     * For a VAT group IČ DPH the row of the group itself (without IČO) wins over its members.
     *
     * @param list<string> $values
     * @return array<string, array{vat_id: string, ico: ?string, name: ?string}> keyed by $column
     */
    private function find(string $column, array $values): array
    {
        if ($values === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($values), '?'));
        $stmt = $this->pdo->prepare("SELECT vat_id, ico, name FROM vat_payer WHERE {$column} IN ({$placeholders}) ORDER BY ico IS NOT NULL, id");
        $stmt->execute($values);

        $found = [];
        foreach ($stmt->fetchAll() as $row) {
            $found[$row[$column]] ??= $row;
        }

        return $found;
    }

    /**
     * @param array{vat_id: string, ico: ?string, name: ?string}|null $row
     * @return array<string, mixed>
     */
    private static function item(?array $row, ?string $vatId = null, ?string $ico = null): array
    {
        return [
            'vatId' => $row['vat_id'] ?? $vatId,
            'valid' => $row !== null,
            'identifier' => $row['ico'] ?? $ico,
            'fullName' => $row['name'] ?? null,
        ];
    }

    /**
     * "sk 2020 317 068" → "SK2020317068"; ten digits without a prefix get "SK".
     */
    private static function normalizeVatId(string $value): string
    {
        $value = strtoupper((string) preg_replace('/\s+/', '', $value));

        return preg_match('/^\d{10}$/', $value) ? 'SK' . $value : $value;
    }
}
