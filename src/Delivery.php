<?php

declare(strict_types=1);

namespace Pictomancer;

/**
 * Builders for delivery targets. By default an operation returns the optimized
 * bytes inline; pass a delivery target to have Pictomancer write the result
 * directly to your storage or endpoint instead. No cloud credentials reach us.
 */
final class Delivery
{
    /** @return array<string, mixed> */
    public static function inline(): array
    {
        return ['mode' => 'inline'];
    }

    /**
     * Upload to a customer-signed presigned PUT URL (S3/R2/GCS/Azure).
     *
     * @param array<string, string>|null $headers whitelisted storage headers (Content-Type, Cache-Control, x-amz-*, ...)
     * @return array<string, mixed>
     */
    public static function putUrl(string $url, ?array $headers = null): array
    {
        $target = ['mode' => 'put_url', 'put_url' => $url];
        if ($headers) {
            $target['headers'] = $headers;
        }

        return $target;
    }

    /**
     * POST the bytes to a customer callback endpoint (async/large jobs).
     *
     * Pass $secret to sign the body with HMAC-SHA256: we send
     * `X-Pig-Signature: sha256=<hex>`, GitHub-webhook style. The secret is used
     * per request and never stored.
     *
     * @param array<string, string>|null $headers
     * @return array<string, mixed>
     */
    public static function callback(string $url, ?array $headers = null, ?string $secret = null): array
    {
        $target = ['mode' => 'callback_url', 'callback_url' => $url];
        if ($headers) {
            $target['headers'] = $headers;
        }
        if ($secret !== null && $secret !== '') {
            $target['secret'] = $secret;
        }

        return $target;
    }
}
