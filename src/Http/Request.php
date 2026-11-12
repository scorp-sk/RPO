<?php

declare(strict_types=1);

namespace Rpo\Http;

final readonly class Request
{
    /** Query parameter carrying the access token (alternative to the Authorization: Bearer header). */
    private const string TOKEN_PARAM = 'token';

    /**
     * @param string $uri request URI without the token parameter (returned in error responses)
     * @param array<string, mixed> $query query parameters without the token parameter
     */
    public function __construct(
        public string $method,
        public string $path,
        public string $uri,
        public array $query,
        public ?string $token = null,
    ) {
    }

    public static function fromGlobals(): self
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $query = $_GET;
        $token = self::bearerToken();

        if (array_key_exists(self::TOKEN_PARAM, $query)) {
            $param = $query[self::TOKEN_PARAM];
            $token ??= is_string($param) && $param !== '' ? $param : null;
            unset($query[self::TOKEN_PARAM]);
            $uri = self::withoutTokenParam($uri);
        }

        return new self(
            method: strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            path: rawurldecode((string) (parse_url($uri, PHP_URL_PATH) ?? '/')),
            uri: $uri,
            query: $query,
            token: $token,
        );
    }

    /**
     * Boolean parameter: only "true" (case-insensitive) is accepted as true.
     */
    public function bool(string $name): bool
    {
        $value = $this->query[$name] ?? null;

        return is_string($value) && strtolower($value) === 'true';
    }

    /**
     * All values of a query parameter that may repeat (name=a&name=b or name[]=a&name[]=b),
     * in request order; null if the parameter is absent. $_GET keeps only the last value
     * of a repeated plain key, so the raw query string is parsed instead.
     *
     * @return list<string>|null
     */
    public function all(string $name): ?array
    {
        $values = null;
        foreach (explode('&', (string) parse_url($this->uri, PHP_URL_QUERY)) as $part) {
            if ($part === '') {
                continue;
            }
            [$key, $value] = array_pad(explode('=', $part, 2), 2, '');
            $key = urldecode($key);
            if ($key === $name || $key === $name . '[]') {
                $values[] = urldecode($value);
            }
        }

        return $values;
    }

    private static function bearerToken(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
        if ($header === null && function_exists('getallheaders')) {
            foreach (getallheaders() as $name => $value) {
                if (strcasecmp($name, 'Authorization') === 0) {
                    $header = $value;
                    break;
                }
            }
        }

        return is_string($header) && preg_match('/^Bearer\s+(\S+)$/i', trim($header), $m) ? $m[1] : null;
    }

    /**
     * Removes the token from the URI so it is never echoed back in responses.
     */
    private static function withoutTokenParam(string $uri): string
    {
        [$path, $queryString] = array_pad(explode('?', $uri, 2), 2, '');
        $parts = array_filter(
            explode('&', $queryString),
            fn ($part) => $part !== '' && !preg_match('/^' . self::TOKEN_PARAM . '(=|$)/', $part)
        );

        return $parts === [] ? $path : $path . '?' . implode('&', $parts);
    }
}
