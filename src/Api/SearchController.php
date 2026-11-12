<?php

declare(strict_types=1);

namespace Rpo\Api;

use Rpo\Http\JsonResponse;
use Rpo\Http\Request;

/**
 * GET /search?identifier={IČO} – entities with the given IČO (exact match), ordered by id.
 * One IČO may have several records (e.g. a terminated entity and a new one).
 *
 * Several IČO in one call: repeat the parameter (identifier=A&identifier=B or identifier[]=…).
 * A single IČO keeps the original response ("results" is a list); two or more return
 * "results" as an object keyed by IČO in request order, with [] for IČO that were not found.
 */
final readonly class SearchController
{
    private const int MAX_URI_LENGTH = 4096;
    private const int MAX_IDENTIFIERS = 100;

    public function __construct(private \PDO $pdo, private string $license, private int $limit)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $icos = $request->all('identifier');
        if ($icos === null) {
            return JsonResponse::error(400, 'No parameters specified in search request', $request->uri);
        }
        $icos = array_map('trim', $icos);
        if (in_array('', $icos, true)) {
            return JsonResponse::error(400, 'Request not understood', $request->uri);
        }
        $icos = array_values(array_unique($icos));
        if (strlen($request->uri) > self::MAX_URI_LENGTH || count($icos) > self::MAX_IDENTIFIERS) {
            return JsonResponse::error(400, 'Invalid input parameter', $request->uri);
        }

        return JsonResponse::ok([
            'results' => count($icos) === 1 ? $this->findOne($icos[0]) : $this->findMany($icos),
            'lastUpdate' => DataVersion::lastUpdate($this->pdo),
            'license' => $this->license,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function findOne(string $ico): array
    {
        $stmt = $this->pdo->prepare('SELECT ' . CompanyJson::COLUMNS . " FROM company WHERE ico = ? ORDER BY id LIMIT {$this->limit}");
        $stmt->execute([$ico]);

        return array_map(CompanyJson::fromRow(...), $stmt->fetchAll());
    }

    /**
     * Records grouped by IČO, at most $limit per IČO. Returned as an object so that
     * json_encode always produces {"IČO": [...]}, even for keys such as "0".
     *
     * @param list<string> $icos
     */
    private function findMany(array $icos): object
    {
        $placeholders = implode(',', array_fill(0, count($icos), '?'));
        $stmt = $this->pdo->prepare('SELECT ' . CompanyJson::COLUMNS . " FROM company WHERE ico IN ({$placeholders}) ORDER BY id");
        $stmt->execute($icos);

        $grouped = array_fill_keys($icos, []);
        foreach ($stmt->fetchAll() as $row) {
            $ico = $row['ico'];
            if (array_key_exists($ico, $grouped) && count($grouped[$ico]) < $this->limit) {
                $grouped[$ico][] = CompanyJson::fromRow($row);
            }
        }

        return (object) $grouped;
    }
}
