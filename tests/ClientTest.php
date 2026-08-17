<?php

declare(strict_types=1);

namespace Pictomancer\Tests;

use Pictomancer\Client;
use Pictomancer\Delivery;
use Pictomancer\HttpException;
use Pictomancer\Response;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    private const PNG = "\x89PNG\r\n\x1a\n";
    private const PUT_URL = 'https://bucket.s3.amazonaws.com/key?X-Amz-Signature=abc';
    private const CALLBACK_URL = 'https://hooks.example.com/pig?token=abc';
    private const SOURCE = 'data:image/png;base64,xxx';

    private function newClient(FakeTransport $transport): Client
    {
        return new Client(apiKey: 'k', transport: $transport);
    }

    /** @param array<string, mixed> $body */
    private function jsonResponse(int $status, array $body): Response
    {
        return new Response($status, ['content-type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
    }

    private function imageResponse(string $bytes): Response
    {
        return new Response(200, ['content-type' => 'image/png'], $bytes);
    }

    public function testResizeInlineReturnsBytes(): void
    {
        $transport = new FakeTransport($this->imageResponse(self::PNG));
        $client = $this->newClient($transport);

        $out = $client->resize(self::SOURCE, ['scale' => 0.5]);

        $this->assertSame(self::PNG, $out);
    }

    public function testResizePutUrlReturnsArrayAndSendsDelivery(): void
    {
        $transport = new FakeTransport($this->jsonResponse(200, [
            'etag' => 'abc',
            'sha256' => str_repeat('0', 64),
            'bytes_written' => 10,
        ]));
        $client = $this->newClient($transport);

        $out = $client->resize(self::SOURCE, ['scale' => 0.5], Delivery::putUrl(self::PUT_URL));

        $this->assertSame(['etag' => 'abc', 'sha256' => str_repeat('0', 64), 'bytes_written' => 10], $out);
        $body = $transport->lastCall()['body'];
        $this->assertStringContainsString('"put_url"', $body);
        $this->assertStringContainsString('"delivery"', $body);
    }

    public function testCompressCallbackReturnsArray(): void
    {
        $transport = new FakeTransport($this->jsonResponse(200, ['status' => 202, 'sha256' => str_repeat('f', 64)]));
        $client = $this->newClient($transport);

        $out = $client->compress(self::SOURCE, [], Delivery::callback(self::CALLBACK_URL));

        $this->assertSame(202, $out['status']);
    }

    public function testAuthorizationHeaderSetFromApiKey(): void
    {
        $transport = new FakeTransport($this->imageResponse(self::PNG));
        $client = $this->newClient($transport);

        $client->resize(self::SOURCE, ['scale' => 0.5]);

        $this->assertSame('Bearer k', $transport->lastCall()['headers']['Authorization']);
    }

    public function testNoAuthorizationHeaderWithoutApiKey(): void
    {
        $transport = new FakeTransport($this->imageResponse(self::PNG));
        $client = new Client(transport: $transport);

        $client->resize(self::SOURCE, ['scale' => 0.5]);

        $this->assertArrayNotHasKey('Authorization', $transport->lastCall()['headers']);
    }

    public function testUserAgentIdentifiesSdkAndRuntime(): void
    {
        $transport = new FakeTransport($this->imageResponse(self::PNG));
        $client = $this->newClient($transport);

        $client->resize(self::SOURCE, ['scale' => 0.5]);

        $userAgent = $transport->lastCall()['headers']['User-Agent'];
        $this->assertSame(sprintf('pictomancer-php/%s php/%s', Client::VERSION, PHP_VERSION), $userAgent);
    }

    public function testIntegrationAppendedToUserAgent(): void
    {
        $transport = new FakeTransport($this->imageResponse(self::PNG));
        $client = new Client(apiKey: 'k', transport: $transport, integration: 'wordpress-plugin/0.1.0 wp/6.5');

        $client->resize(self::SOURCE, ['scale' => 0.5]);

        $this->assertStringEndsWith(' wordpress-plugin/0.1.0 wp/6.5', $transport->lastCall()['headers']['User-Agent']);
    }

    public function testNon2xxRaisesHttpException(): void
    {
        $transport = new FakeTransport($this->jsonResponse(402, ['detail' => 'payment_required']));
        $client = $this->newClient($transport);

        $this->expectException(HttpException::class);

        $client->resize(self::SOURCE, ['scale' => 0.5]);
    }

    public function testHttpExceptionCarriesStatusAndBody(): void
    {
        $transport = new FakeTransport($this->jsonResponse(402, ['detail' => 'payment_required', 'price' => 0.001]));
        $client = $this->newClient($transport);

        try {
            $client->resize(self::SOURCE, ['scale' => 0.5]);
            $this->fail('expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(402, $e->statusCode);
            $this->assertSame('payment_required', $e->body['detail']);
        }
    }

    public function testInfoIssuesGetAndReturnsJson(): void
    {
        $transport = new FakeTransport($this->jsonResponse(200, ['version' => '1.0']));
        $client = $this->newClient($transport);

        $out = $client->info();

        $this->assertSame('1.0', $out['version']);
        $this->assertSame('GET', $transport->lastCall()['method']);
    }

    public function testConvertPutsFormatInBody(): void
    {
        $transport = new FakeTransport($this->imageResponse(self::PNG));
        $client = $this->newClient($transport);

        $client->convert(self::SOURCE, 'webp', ['q' => 90]);

        $body = $transport->lastCall()['body'];
        $this->assertStringContainsString('"format":"webp"', $body);
        $this->assertStringContainsString('"q":90', $body);
    }

    public function testCompressPutsQualityTargetInBody(): void
    {
        $transport = new FakeTransport($this->imageResponse(self::PNG));
        $client = $this->newClient($transport);

        $client->compress(self::SOURCE, ['format' => 'webp', 'quality_target' => 0.95]);

        $body = $transport->lastCall()['body'];
        $this->assertStringContainsString('"quality_target":0.95', $body);
        $this->assertStringContainsString('"format":"webp"', $body);
    }

    public function testConvertPutsQualityTargetInBody(): void
    {
        $transport = new FakeTransport($this->imageResponse(self::PNG));
        $client = $this->newClient($transport);

        $client->convert(self::SOURCE, 'avif', ['quality_target' => 0.9]);

        $body = $transport->lastCall()['body'];
        $this->assertStringContainsString('"quality_target":0.9', $body);
        $this->assertStringContainsString('"format":"avif"', $body);
    }

    public function testOptimizeGeneratedSendsOnlySourceByDefault(): void
    {
        $transport = new FakeTransport($this->imageResponse(self::PNG));
        $client = $this->newClient($transport);

        $client->optimizeGenerated(self::SOURCE);

        $body = $transport->lastCall()['body'];
        $this->assertSame(json_encode(['source' => self::SOURCE]), $body);
    }

    public function testOptimizeGeneratedPutsFormatAndMaxDimensionInBody(): void
    {
        $transport = new FakeTransport($this->imageResponse(self::PNG));
        $client = $this->newClient($transport);

        $client->optimizeGenerated(self::SOURCE, ['format' => 'avif', 'max_dimension' => 1600]);

        $body = $transport->lastCall()['body'];
        $this->assertStringContainsString('"format":"avif"', $body);
        $this->assertStringContainsString('"max_dimension":1600', $body);
    }

    public function testCropSmartSendsGravityViaOptionsWithoutXY(): void
    {
        $transport = new FakeTransport($this->imageResponse(self::PNG));
        $client = $this->newClient($transport);

        $client->crop(self::SOURCE, null, null, 200, 200, ['gravity' => 'attention']);

        $body = $transport->lastCall()['body'];
        $this->assertStringContainsString('"gravity":"attention"', $body);
        $this->assertStringContainsString('"width":200', $body);
        $this->assertStringContainsString('"height":200', $body);
        $this->assertStringNotContainsString('"x"', $body);
        $this->assertStringNotContainsString('"y"', $body);
    }

    public function testCropTrimSendsThresholdWithoutDims(): void
    {
        $transport = new FakeTransport($this->imageResponse(self::PNG));
        $client = $this->newClient($transport);

        $client->crop(self::SOURCE, null, null, null, null, ['trim' => true, 'threshold' => 5.0]);

        $body = $transport->lastCall()['body'];
        $this->assertStringContainsString('"trim":true', $body);
        $this->assertStringContainsString('"threshold":5', $body);
        $this->assertStringNotContainsString('"width"', $body);
        $this->assertStringNotContainsString('"height"', $body);
    }

    public function testCropManualRegressionSendsAllFour(): void
    {
        $transport = new FakeTransport($this->imageResponse(self::PNG));
        $client = $this->newClient($transport);

        $client->crop(self::SOURCE, 0, 0, 100, 100, ['format' => 'webp']);

        $body = $transport->lastCall()['body'];
        $this->assertStringContainsString('"x":0', $body);
        $this->assertStringContainsString('"y":0', $body);
        $this->assertStringContainsString('"width":100', $body);
        $this->assertStringContainsString('"height":100', $body);
        $this->assertStringNotContainsString('"gravity"', $body);
        $this->assertStringNotContainsString('"trim"', $body);
    }

    public function testCropPutsAutorotInBody(): void
    {
        $transport = new FakeTransport($this->imageResponse(self::PNG));
        $client = $this->newClient($transport);

        $client->crop(self::SOURCE, 0, 0, 100, 100, ['autorot' => true]);

        $body = $transport->lastCall()['body'];
        $this->assertStringContainsString('"autorot":true', $body);
    }

    public function testResizePutsFillModeParamsInBody(): void
    {
        $transport = new FakeTransport($this->imageResponse(self::PNG));
        $client = $this->newClient($transport);

        $client->resize(self::SOURCE, ['width' => 200, 'height' => 150, 'gravity' => 'entropy']);

        $body = $transport->lastCall()['body'];
        $this->assertStringContainsString('"width":200', $body);
        $this->assertStringContainsString('"height":150', $body);
        $this->assertStringContainsString('"gravity":"entropy"', $body);
        $this->assertStringNotContainsString('"scale"', $body);
    }

    public function testCompressPutsEnhanceParamsInBody(): void
    {
        $transport = new FakeTransport($this->imageResponse(self::PNG));
        $client = $this->newClient($transport);

        $client->compress(self::SOURCE, ['denoise' => 2, 'sharpen' => true]);

        $body = $transport->lastCall()['body'];
        $this->assertStringContainsString('"denoise":2', $body);
        $this->assertStringContainsString('"sharpen":true', $body);
    }

    public function testConvertOmitsEnhanceParamsByDefault(): void
    {
        $transport = new FakeTransport($this->imageResponse(self::PNG));
        $client = $this->newClient($transport);

        $client->convert(self::SOURCE, 'webp');

        $body = $transport->lastCall()['body'];
        $this->assertStringNotContainsString('"denoise"', $body);
        $this->assertStringNotContainsString('"equalize"', $body);
        $this->assertStringNotContainsString('"sharpen"', $body);
    }

    public function testUserAgentReportsCurrentVersion(): void
    {
        $transport = new FakeTransport($this->jsonResponse(200, ['formats' => []]));
        $client = $this->newClient($transport);

        $client->info();

        $headers = $transport->lastCall()['headers'];
        $this->assertStringContainsString('pictomancer-php/0.6.0', $headers['User-Agent']);
    }
}
