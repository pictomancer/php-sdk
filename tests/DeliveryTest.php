<?php

declare(strict_types=1);

namespace Pictomancer\Tests;

use Pictomancer\Delivery;
use PHPUnit\Framework\TestCase;

final class DeliveryTest extends TestCase
{
    private const PUT_URL = 'https://bucket.s3.amazonaws.com/key?X-Amz-Signature=abc';
    private const CALLBACK_URL = 'https://hooks.example.com/pig?token=abc';

    public function testInline(): void
    {
        $this->assertSame(['mode' => 'inline'], Delivery::inline());
    }

    public function testPutUrlWithoutHeaders(): void
    {
        $this->assertSame(
            ['mode' => 'put_url', 'put_url' => self::PUT_URL],
            Delivery::putUrl(self::PUT_URL),
        );
    }

    public function testPutUrlWithHeaders(): void
    {
        $out = Delivery::putUrl(self::PUT_URL, ['Content-Type' => 'image/webp']);

        $this->assertSame(
            [
                'mode' => 'put_url',
                'put_url' => self::PUT_URL,
                'headers' => ['Content-Type' => 'image/webp'],
            ],
            $out,
        );
    }

    public function testCallbackWithoutSecret(): void
    {
        $this->assertSame(
            ['mode' => 'callback_url', 'callback_url' => self::CALLBACK_URL],
            Delivery::callback(self::CALLBACK_URL),
        );
    }

    public function testCallbackWithSecret(): void
    {
        $out = Delivery::callback(self::CALLBACK_URL, secret: 's3cr3t');

        $this->assertSame(
            [
                'mode' => 'callback_url',
                'callback_url' => self::CALLBACK_URL,
                'secret' => 's3cr3t',
            ],
            $out,
        );
    }
}
