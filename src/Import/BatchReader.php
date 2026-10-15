<?php

declare(strict_types=1);

namespace Rpo\Import;

/**
 * Streaming reader for an RPO batch (.json.gz). The file looks like
 *   {"exportDate":"2026-10-05","results":[
 *   {...record...}
 *   ,{...record...}
 *   ]}
 * i.e. one record per line, so the whole file never has to be loaded into memory.
 */
final class BatchReader
{
    private ?string $exportDate = null;

    public function __construct(private readonly string $path)
    {
    }

    /**
     * Export date from the file header (available once the first line has been read).
     */
    public function exportDate(): ?string
    {
        return $this->exportDate;
    }

    /**
     * @return \Generator<int, object>
     */
    public function records(): \Generator
    {
        $handle = gzopen($this->path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException("Cannot open {$this->path}");
        }

        try {
            $lineNo = 0;
            while (($line = gzgets($handle)) !== false) {
                $lineNo++;
                $line = trim($line);

                if ($lineNo === 1 && str_starts_with($line, '{"exportDate"')) {
                    if (preg_match('/^\{"exportDate":"(\d{4}-\d{2}-\d{2})"/', $line, $m)) {
                        $this->exportDate = $m[1];
                    }
                    $pos = strpos($line, '"results":[');
                    $line = $pos === false ? '' : substr($line, $pos + strlen('"results":['));
                }

                $line = ltrim($line, ',');
                if ($line === '' || $line === ']}' || $line === ']') {
                    continue;
                }

                yield $this->decode($line, $lineNo);
            }
            if (!gzeof($handle)) {
                throw new \RuntimeException("Error reading {$this->path} (line {$lineNo})");
            }
        } finally {
            gzclose($handle);
        }
    }

    private function decode(string $line, int $lineNo): object
    {
        try {
            $record = json_decode($line, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            // The last record may share its line with the closing "]}"
            if (!str_ends_with($line, ']}')) {
                throw new \RuntimeException("Invalid JSON on line {$lineNo}: {$e->getMessage()}", 0, $e);
            }
            $record = json_decode(substr($line, 0, -2), false, 512, JSON_THROW_ON_ERROR);
        }

        if (!is_object($record) || !isset($record->id)) {
            throw new \RuntimeException("Line {$lineNo} does not contain a record with an id");
        }

        return $record;
    }
}
