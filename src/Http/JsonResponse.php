<?php

declare(strict_types=1);

namespace Rpo\Http;

/**
 * JSON response; error responses have the same shape as in the official RPO API (Micronaut).
 */
final readonly class JsonResponse
{
    private const int FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    /**
     * @param array<string, string> $headers
     */
    public function __construct(public int $status, public string $body, public array $headers = [])
    {
    }

    public static function error(int $status, string $message, string $href): self
    {
        return new self($status, json_encode([
            'message' => $message,
            '_links' => ['self' => ['href' => $href, 'templated' => false]],
        ], self::FLAGS));
    }

    public static function unauthorized(string $href): self
    {
        $error = self::error(401, 'Unauthorized', $href);

        return new self(401, $error->body, ['WWW-Authenticate' => 'Bearer realm="rpo"']);
    }

    public static function methodNotAllowed(): self
    {
        return new self(405, json_encode(['code' => 405, 'message' => 'Method Not Allowed'], self::FLAGS));
    }

    /**
     * @param array<mixed> $data
     */
    public static function ok(array $data): self
    {
        return new self(200, json_encode($data, self::FLAGS));
    }

    public function send(): void
    {
        http_response_code($this->status);
        header('Content-Type: application/json');
        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }
        header('Content-Length: ' . strlen($this->body));
        echo $this->body;
    }
}
