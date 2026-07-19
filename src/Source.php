<?php

declare(strict_types=1);

namespace Pictomancer;

/**
 * Builders for operation sources. Every operation accepts an image URL, a
 * base64 string, or a data: URI; these helpers encode local files and
 * in-memory bytes as the raw base64 the API accepts.
 */
final class Source
{
    public static function fromBytes(string $bytes): string
    {
        return base64_encode($bytes);
    }

    public static function fromPath(string $path): string
    {
        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            throw new PictomancerException("cannot read source file: {$path}");
        }

        return self::fromBytes($bytes);
    }
}
