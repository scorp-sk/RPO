<?php

declare(strict_types=1);

namespace Rpo\Auth;

/**
 * API user access tokens and their usage statistics.
 * A token is shown only when created; the DB stores only its SHA-256 hash.
 */
final readonly class TokenRepository
{
    private const string PREFIX = 'rpo_';
    private const int HINT_LENGTH = 12;

    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @return array{id: int, token: string}
     */
    public function create(string $name): array
    {
        $token = self::PREFIX . bin2hex(random_bytes(20));
        $this->pdo->prepare('INSERT INTO api_token (name, token_hash, token_hint, created_at) VALUES (?, ?, ?, ?)')
            ->execute([$name, self::hash($token), substr($token, 0, self::HINT_LENGTH), $this->now()]);

        return ['id' => (int) $this->pdo->lastInsertId(), 'token' => $token];
    }

    /**
     * Id of a valid (not revoked) token, or null.
     */
    public function authenticate(?string $token): ?int
    {
        if ($token === null || !str_starts_with($token, self::PREFIX)) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT id FROM api_token WHERE token_hash = ? AND revoked_at IS NULL');
        $stmt->execute([self::hash($token)]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    public function recordUse(int $id): void
    {
        $now = $this->now();
        $this->pdo->prepare(
            'INSERT INTO api_token_usage (token_id, day, requests) VALUES (?, ?, 1)
             ON DUPLICATE KEY UPDATE requests = requests + 1'
        )->execute([$id, substr($now, 0, 10)]);
        $this->pdo->prepare('UPDATE api_token SET last_used_at = ? WHERE id = ?')->execute([$now, $id]);
    }

    public function revoke(int $id): bool
    {
        $stmt = $this->pdo->prepare('UPDATE api_token SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL');
        $stmt->execute([$this->now(), $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * All tokens with a usage summary.
     *
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        $today = date('Y-m-d');
        $since = date('Y-m-d', strtotime('-29 days'));
        $stmt = $this->pdo->prepare(
            'SELECT t.id, t.name, t.token_hint, t.created_at, t.last_used_at, t.revoked_at,
                    COALESCE(SUM(CASE WHEN u.day = ? THEN u.requests END), 0) AS today,
                    COALESCE(SUM(CASE WHEN u.day >= ? THEN u.requests END), 0) AS last30,
                    COALESCE(SUM(u.requests), 0) AS total
             FROM api_token t LEFT JOIN api_token_usage u ON u.token_id = t.id
             GROUP BY t.id ORDER BY t.id'
        );
        $stmt->execute([$today, $since]);

        return $stmt->fetchAll();
    }

    /**
     * Number of requests per day (newest first).
     *
     * @return array<string, int> day => count
     */
    public function usage(int $id, int $days): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT day, requests FROM api_token_usage WHERE token_id = ? AND day >= ? ORDER BY day DESC'
        );
        $stmt->execute([$id, date('Y-m-d', strtotime('-' . max(0, $days - 1) . ' days'))]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_KEY_PAIR));
    }

    public function exists(int $id): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM api_token WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->fetchColumn() !== false;
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
