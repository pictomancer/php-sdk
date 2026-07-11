# pictomancer/pictomancer

PHP SDK for [Pictomancer.ai](https://pictomancer.ai) - a thin, zero-dependency
client (native `ext-curl`) around the REST API at `https://api.pictomancer.ai`.

## Install

```bash
composer require pictomancer/pictomancer
```

Requires PHP >= 8.1 with the `curl` and `json` extensions.

## Configuration

`new Client($apiKey, $baseUrl, $timeout, $transport, $integration)`:

- **`apiKey`** - optional Bearer token (`Authorization: Bearer ...`).
- **`baseUrl`** - defaults to `https://api.pictomancer.ai`.
- **`timeout`** - request timeout in seconds (default `30.0`).
- **`transport`** - optional `Pictomancer\Transport`; defaults to `CurlTransport`. Inject a fake in tests.
- **`integration`** - optional consumer identifier (e.g. `wordpress-plugin/0.1.0 wp/6.5`) appended to the SDK `User-Agent`. Set it when building a plugin or framework integration on top of the SDK so server-side traffic can be segmented per consumer type.

JSON helpers (`info`, `usage`, `analyze`) return `array`. Image operations return
the optimized `string` (raw bytes) for inline delivery, or an `array` (etag,
sha256, bytes written, ...) when a delivery target is given.

## Usage

```php
use Pictomancer\Client;

$client = new Client('your-api-key');

$info  = $client->info();
$usage = $client->usage();
$meta  = $client->analyze('https://example.com/image.jpg');

$bytes = $client->resize('https://example.com/image.jpg', ['scale' => 0.5, 'format' => 'webp']);
$bytes = $client->compress('https://example.com/image.jpg', ['q' => 85, 'format' => 'jpeg']);
$bytes = $client->convert('https://example.com/image.jpg', 'png', ['q' => 90]);
$bytes = $client->crop('https://example.com/image.jpg', 0, 0, 100, 100, ['format' => 'webp']);
$bytes = $client->pipeline('https://example.com/image.jpg', [
    ['type' => 'resize', 'params' => ['scale' => '0.5']],
    ['type' => 'convert', 'params' => ['format' => 'webp']],
]);

file_put_contents('out.webp', $bytes);
```

## Delivery: write the result somewhere else

By default an operation returns the optimized bytes. Pass a delivery target to
have Pictomancer write the result directly to your storage or endpoint - the
operation then returns an `array` (etag, sha256, bytes written, ...). No cloud
credentials ever reach Pictomancer.

```php
use Pictomancer\Client;
use Pictomancer\Delivery;

$client = new Client('your-api-key');

// Upload to a customer-signed presigned PUT URL (S3/R2/GCS/Azure).
$res = $client->resize(
    'https://example.com/image.jpg',
    ['scale' => 0.5],
    Delivery::putUrl('https://bucket.s3.amazonaws.com/key?X-Amz-Signature=...'),
);
echo $res['sha256'], ' ', $res['bytes_written'];

// Or POST the bytes to your own callback endpoint (async/large jobs).
$res = $client->compress(
    'https://example.com/image.jpg',
    [],
    Delivery::callback('https://hooks.example.com/pig?token=secret'),
);
echo $res['status'], ' ', $res['sha256'];
```

`Delivery::putUrl()` and `Delivery::callback()` accept optional whitelisted
storage headers (e.g. `Content-Type`, `Cache-Control`, `x-amz-*`). The returned
`sha256` is the digest of exactly the bytes delivered, so you can verify the
stored object.

### Authenticating a callback

Pass `secret:` to `Delivery::callback()` to have the POST body signed. We send
`X-Pig-Signature: sha256=<hex>` (HMAC-SHA256 of the body, GitHub-webhook style).
The secret is used per request and never stored. Verify it on your endpoint:

```php
$res = $client->resize(
    'https://example.com/image.jpg',
    ['scale' => 0.5],
    Delivery::callback('https://hooks.example.com/pig', secret: 'shared-secret'),
);

// On your endpoint, recompute and constant-time compare:
$expected = 'sha256=' . hash_hmac('sha256', $requestBody, 'shared-secret');
$signature = $_SERVER['HTTP_X_PIG_SIGNATURE'] ?? '';
if (! hash_equals($expected, $signature)) {
    http_response_code(401);
    exit;
}
```

## Errors

Non-2xx responses throw `Pictomancer\HttpException` (`$e->statusCode`,
`$e->body`). Transport-level failures throw `Pictomancer\PictomancerException`.
Both extend `RuntimeException`.

## API documentation

Interactive docs: [https://api.pictomancer.ai/docs](https://api.pictomancer.ai/docs)

OpenAPI: [https://api.pictomancer.ai/openapi.json](https://api.pictomancer.ai/openapi.json)
