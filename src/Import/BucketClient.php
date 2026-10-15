<?php

declare(strict_types=1);

namespace Rpo\Import;

/**
 * Access to the public S3-compatible storage holding the RPO exports (no authentication).
 */
final class BucketClient
{
    private const int RETRIES = 3;

    public function __construct(private readonly string $bucketUrl)
    {
    }

    /**
     * Objects with the given prefix (S3 ListObjects v1, paginated via marker).
     *
     * @return array<string, array{key: string, size: int, etag: string}> sorted by key
     */
    public function list(string $prefix): array
    {
        $objects = [];
        $marker = '';
        do {
            $url = $this->bucketUrl . '?' . http_build_query(['prefix' => $prefix, 'marker' => $marker]);
            $xml = simplexml_load_string($this->get($url));
            if ($xml === false) {
                throw new \RuntimeException("Invalid storage listing: {$url}");
            }
            $lastKey = null;
            foreach ($xml->Contents as $item) {
                $key = (string) $item->Key;
                $objects[$key] = [
                    'key' => $key,
                    'size' => (int) $item->Size,
                    'etag' => trim((string) $item->ETag, '"'),
                ];
                $lastKey = $key;
            }
            $truncated = strtolower((string) $xml->IsTruncated) === 'true';
            $marker = (string) ($xml->NextMarker ?: $lastKey);
        } while ($truncated && $marker !== '');

        ksort($objects, SORT_STRING);

        return $objects;
    }

    public function getText(string $key): string
    {
        return $this->get($this->bucketUrl . $this->encodeKey($key));
    }

    /**
     * Downloads an object to a file; retries on failure or size mismatch.
     */
    public function download(string $key, string $target, ?int $expectedSize = null): void
    {
        $url = $this->bucketUrl . $this->encodeKey($key);
        $tmp = $target . '.part';

        for ($attempt = 1; ; $attempt++) {
            $fh = fopen($tmp, 'wb');
            if ($fh === false) {
                throw new \RuntimeException("Cannot write to {$tmp}");
            }
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_FILE => $fh,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_FAILONERROR => true,
                CURLOPT_CONNECTTIMEOUT => 30,
                CURLOPT_LOW_SPEED_LIMIT => 1024,
                CURLOPT_LOW_SPEED_TIME => 120,
            ]);
            $ok = curl_exec($ch);
            $error = curl_error($ch);
            fclose($fh);

            $size = filesize($tmp);
            if ($ok && ($expectedSize === null || $size === $expectedSize)) {
                rename($tmp, $target);

                return;
            }
            @unlink($tmp);
            if ($attempt >= self::RETRIES) {
                throw new \RuntimeException("Download of {$key} failed: " . ($error ?: "size {$size} != {$expectedSize}"));
            }
            sleep(5 * $attempt);
        }
    }

    private function get(string $url): string
    {
        for ($attempt = 1; ; $attempt++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_FAILONERROR => true,
                CURLOPT_CONNECTTIMEOUT => 30,
                CURLOPT_TIMEOUT => 120,
            ]);
            $body = curl_exec($ch);
            if (is_string($body)) {
                return $body;
            }
            if ($attempt >= self::RETRIES) {
                throw new \RuntimeException("Request {$url} failed: " . curl_error($ch));
            }
            sleep(5 * $attempt);
        }
    }

    private function encodeKey(string $key): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $key)));
    }
}
