<?php

declare(strict_types=1);

namespace Rpo\Api;

use Rpo\Http\JsonResponse;
use Rpo\Http\Request;

/**
 * GET /search?identifier={IČO} – entities with the given IČO (exact match), ordered by id.
 * One IČO may have several records (e.g. a terminated entity and a new one).
 */
final readonly class SearchController
{
    private const int MAX_URI_LENGTH = 2000;

    public function __construct(private \PDO $pdo, private string $license, private int $limit)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        if (!array_key_exists('identifier', $request->query)) {
            return JsonResponse::error(400, 'No parameters specified in search request', $request->uri);
        }
        $ico = $request->query['identifier'];
        if (!is_string($ico) || trim($ico) === '') {
            return JsonResponse::error(400, 'Request not understood', $request->uri);
        }
        if (strlen($request->uri) > self::MAX_URI_LENGTH) {
            return JsonResponse::error(400, 'Invalid input parameter', $request->uri);
        }

        $stmt = $this->pdo->prepare('SELECT ' . CompanyJson::COLUMNS . " FROM company WHERE ico = ? ORDER BY id LIMIT {$this->limit}");
        $stmt->execute([trim($ico)]);

        return JsonResponse::ok([
            'results' => array_map(CompanyJson::fromRow(...), $stmt->fetchAll()),
            'lastUpdate' => DataVersion::lastUpdate($this->pdo),
            'license' => $this->license,
        ]);
    }
}
