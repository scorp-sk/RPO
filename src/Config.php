<?php

declare(strict_types=1);

namespace Rpo;

final readonly class Config
{
    /**
     * @param array{dsn: string, user: string, password: string} $db
     */
    public function __construct(
        public array $db,
        public string $bucketUrl,
        public string $varDir,
        public string $basePath,
        public int $searchLimit,
        public string $license,
        public string $timezone,
    ) {
    }

    /**
     * Loads the configuration and sets the time zone (affects the days in token statistics).
     */
    public static function load(string $file): self
    {
        if (!is_file($file)) {
            throw new \RuntimeException("Missing configuration {$file} (copy config.php.dist)");
        }
        $c = require $file;

        $config = new self(
            db: $c['db'],
            bucketUrl: rtrim($c['bucket_url'], '/') . '/',
            varDir: rtrim($c['var_dir'], '/'),
            basePath: rtrim($c['base_path'] ?? '/rpo/v1', '/'),
            searchLimit: (int) ($c['search_limit'] ?? 500),
            license: $c['license'],
            timezone: $c['timezone'] ?? 'Europe/Bratislava',
        );
        date_default_timezone_set($config->timezone);

        return $config;
    }
}
