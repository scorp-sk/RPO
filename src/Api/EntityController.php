<?php

declare(strict_types=1);

namespace Rpo\Api;

use Rpo\Http\JsonResponse;
use Rpo\Http\Request;

/**
 * GET /entity/{id} – current name and registered address of an entity by its RPO record id.
 */
final readonly class EntityController
{
    public function __construct(private \PDO $pdo, private string $license)
    {
    }

    public function __invoke(Request $request, string $id): JsonResponse
    {
        if (!preg_match('/^\d{1,10}$/', $id) || (int) $id > 4294967295) {
            return JsonResponse::error(400, 'Invalid input parameter', $request->uri);
        }

        $stmt = $this->pdo->prepare('SELECT ' . CompanyJson::COLUMNS . ' FROM company WHERE id = ?');
        $stmt->execute([(int) $id]);
        $row = $stmt->fetch();
        if ($row === false) {
            return JsonResponse::error(404, 'No record found', $request->uri);
        }

        return JsonResponse::ok(CompanyJson::fromRow($row) + [
            'lastUpdate' => DataVersion::lastUpdate($this->pdo),
            'license' => $this->license,
        ]);
    }
}
