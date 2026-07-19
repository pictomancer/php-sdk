<?php

declare(strict_types=1);

namespace Pictomancer\Tests;

use Pictomancer\PictomancerException;
use Pictomancer\Source;
use PHPUnit\Framework\TestCase;

final class SourceTest extends TestCase
{
    private const IMAGE_BYTES = "\x89PNG\r\n\x1a\n";

    public function testFromBytesReturnsBase64(): void
    {
        $this->assertSame(base64_encode(self::IMAGE_BYTES), Source::fromBytes(self::IMAGE_BYTES));
    }

    public function testFromPathReadsAndEncodes(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pictomancer');
        $this->assertNotFalse($path);
        $this->assertNotFalse(file_put_contents($path, self::IMAGE_BYTES));

        try {
            $this->assertSame(base64_encode(self::IMAGE_BYTES), Source::fromPath($path));
        } finally {
            unlink($path);
        }
    }

    public function testFromPathThrowsOnMissingFile(): void
    {
        $this->expectException(PictomancerException::class);

        Source::fromPath('/nonexistent/image.png');
    }
}
