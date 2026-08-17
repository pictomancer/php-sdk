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

Sources can be an image URL, a base64 string, or a `data:` URI. For local files or in-memory bytes:

```php
use Pictomancer\Source;

$bytes = $client->compress(Source::fromPath('photo.jpg'), ['q' => 80]);
$bytes = $client->compress(Source::fromBytes($raw), ['q' => 80]);
```

## Geometry ops: smart crop, trim, fill, autorot

`crop` has three mutually exclusive modes. Pass `null` for the positional
`$x`/`$y`/`$width`/`$height` args not used by a given mode.

```php
// Manual: exact rectangle.
$bytes = $client->crop('https://example.com/image.jpg', 0, 0, 100, 100);

// Smart: gravity picks the window. One of 'attention', 'entropy', 'centre'.
$bytes = $client->crop('https://example.com/image.jpg', null, null, 200, 200, ['gravity' => 'attention']);

// Trim: removes a uniform background border. threshold defaults to 10.0 server-side.
$bytes = $client->crop('https://example.com/image.jpg', null, null, null, null, ['trim' => true, 'threshold' => 5.0]);
```

`resize` gains a fill mode: pass `width` + `height` in `$options` (instead of
`scale`/`scale_x`/`scale_y`) to resize and smart-crop to exact dimensions in
one call; `gravity` defaults to `'attention'`.

```php
$bytes = $client->resize('https://example.com/image.jpg', ['width' => 200, 'height' => 150, 'gravity' => 'entropy']);
```

All four ops (`resize`, `compress`, `convert`, `crop`) accept `'autorot' => true`
in `$options` to apply EXIF orientation before processing.

When a crop actually trims, the response carries
`X-Pictomancer-Trim-Left/-Top/-Width/-Height` headers.

## Enhance: denoise, auto-contrast, sharpen

All four ops (`resize`, `compress`, `convert`, `crop`) accept three opt-in
modifiers in `$options`, applied in a fixed order around the operation itself:
`autorot -> denoise -> equalize -> op -> sharpen`. Base price, no surcharge.

- **`denoise`** - integer 1-3, median denoise (window 3x3 to 7x7) before the operation.
- **`equalize`** - boolean, auto-contrast (histogram equalisation of the value channel; hue and saturation preserved) before the operation.
- **`sharpen`** - boolean, unsharp-mask sharpen after the operation (libvips defaults).

```php
$bytes = $client->convert('https://example.com/image.jpg', 'webp', [
    'denoise' => 2,
    'equalize' => true,
]);
$bytes = $client->resize('https://example.com/image.jpg', ['scale' => 0.5, 'sharpen' => true]);
```

A `compress` that grows because of these modifiers is still billed
(`X-Pig-Billed: 1`), unlike a plain compress with no gain.

## Quality target: smallest file above an SSIM floor

Instead of guessing a `q` number, pass `quality_target` (float, 0 < v <= 1) to
`compress` or `convert` and the server binary-searches the encoder quality for
the smallest file whose SSIM against the source is at least the target:

```php
$bytes = $client->compress('https://example.com/image.jpg', [
    'format' => 'webp',
    'quality_target' => 0.95,
]);
$bytes = $client->convert('https://example.com/image.jpg', 'avif', [
    'quality_target' => 0.9,
]);
```

Constraints (the server rejects violations with a 422):

- Mutually exclusive with `q`; on `convert` also invalid with `lossless`.
- Output format must be `jpeg`, `webp` or `avif`; `compress` requires an
  explicit `format`.
- Not supported inside `pipeline` operations.

The outcome is reported in response headers: `X-Pictomancer-Quality-Target`,
`X-Pictomancer-Quality-Achieved` (e.g. `0.9530`), `X-Pictomancer-Quality-Q-Final`
and `X-Pictomancer-Quality-Encodes`. They are absent when no search ran. The SDK
returns only the body (bytes or JSON), so read the headers with your own HTTP
tooling if you need the report. `X-Pig-Billed: 0` means the input came back
untouched and the request was free.

## AI-generated images: one call to web-ready

Image generators (gpt-image, DALL-E, Flux, Midjourney, Stable Diffusion)
return 2-8 MB PNGs. optimize_generated returns the same picture as web-ready
webp (default), avif, jpeg or png: metadata stripped, transparency kept,
optional max_dimension cap (never upscales), optional q or quality_target.
Same price as convert; a result that is not smaller is returned free.

```php
$bytes = $client->optimizeGenerated('https://example.com/gen.png', [
    'format' => 'avif',
    'max_dimension' => 1600,
]);
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
