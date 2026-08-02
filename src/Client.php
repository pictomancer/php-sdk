<?php

declare(strict_types=1);

namespace Pictomancer;

final class Client
{
    public const VERSION = '0.2.0';

    public const DEFAULT_BASE_URL = 'https://api.pictomancer.ai';

    private Transport $transport;

    /**
     * @param string|null $integration identifies the layer built on top of the
     *        SDK (e.g. "wordpress-plugin/0.1.0 wp/6.5") so the server can
     *        segment traffic per consumer type; appended to the User-Agent
     */
    public function __construct(
        private readonly ?string $apiKey = null,
        private readonly string $baseUrl = self::DEFAULT_BASE_URL,
        private readonly float $timeout = 30.0,
        ?Transport $transport = null,
        private readonly ?string $integration = null,
    ) {
        $this->transport = $transport ?? new CurlTransport();
    }

    /** @return array<string, mixed> */
    public function info(): array
    {
        return $this->get('/v1/info')->json();
    }

    /** @return array<string, mixed> */
    public function usage(): array
    {
        return $this->get('/v1/usage')->json();
    }

    /** @return array<string, mixed> */
    public function analyze(string $source): array
    {
        return $this->postJson('/v1/analyze', ['source' => $source])->json();
    }

    /**
     * @param array<string, mixed> $options scale, scale_x, scale_y, format, ...
     * @param array<string, mixed>|null $delivery
     * @return string|array<string, mixed> raw bytes for inline delivery, JSON dict for put_url/callback_url
     */
    public function resize(string $source, array $options = [], ?array $delivery = null): string|array
    {
        return $this->process('/v1/resize', ['source' => $source] + $options, $delivery);
    }

    /**
     * @param array<string, mixed> $options format, q, quality_target, strip, ...
     * @param array<string, mixed>|null $delivery
     * @return string|array<string, mixed>
     */
    public function compress(string $source, array $options = [], ?array $delivery = null): string|array
    {
        return $this->process('/v1/compress', ['source' => $source] + $options, $delivery);
    }

    /**
     * @param array<string, mixed> $options q, quality_target, strip, lossless, ...
     * @param array<string, mixed>|null $delivery
     * @return string|array<string, mixed>
     */
    public function convert(string $source, string $format, array $options = [], ?array $delivery = null): string|array
    {
        return $this->process('/v1/convert', ['source' => $source, 'format' => $format] + $options, $delivery);
    }

    /**
     * @param array<string, mixed> $options format, ...
     * @param array<string, mixed>|null $delivery
     * @return string|array<string, mixed>
     */
    public function crop(string $source, int $x, int $y, int $width, int $height, array $options = [], ?array $delivery = null): string|array
    {
        $body = ['source' => $source, 'x' => $x, 'y' => $y, 'width' => $width, 'height' => $height] + $options;

        return $this->process('/v1/crop', $body, $delivery);
    }

    /**
     * @param list<array<string, mixed>> $operations
     * @param array<string, mixed>|null $delivery
     * @return string|array<string, mixed>
     */
    public function pipeline(string $source, array $operations, ?array $delivery = null): string|array
    {
        return $this->process('/v1/pipeline', ['source' => $source, 'operations' => $operations], $delivery);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed>|null $delivery
     * @return string|array<string, mixed>
     */
    private function process(string $path, array $body, ?array $delivery): string|array
    {
        if ($delivery !== null) {
            $body['delivery'] = $delivery;
        }

        $response = $this->postJson($path, $body);

        return $response->isJson() ? $response->json() : $response->body;
    }

    private function get(string $path): Response
    {
        return $this->send('GET', $path, null);
    }

    /** @param array<string, mixed> $body */
    private function postJson(string $path, array $body): Response
    {
        return $this->send('POST', $path, json_encode($body, JSON_THROW_ON_ERROR));
    }

    private function send(string $method, string $path, ?string $body): Response
    {
        $headers = ['Accept' => 'application/json', 'User-Agent' => $this->userAgent()];
        if ($this->apiKey !== null && $this->apiKey !== '') {
            $headers['Authorization'] = 'Bearer ' . $this->apiKey;
        }
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }

        $response = $this->transport->send($method, $this->baseUrl . $path, $headers, $body, $this->timeout);

        if ($response->status >= 400) {
            throw new HttpException($response->status, $this->decodeOrNull($response), $response->body);
        }

        return $response;
    }

    private function userAgent(): string
    {
        $agent = sprintf('pictomancer-php/%s php/%s', self::VERSION, PHP_VERSION);

        return $this->integration === null || $this->integration === ''
            ? $agent
            : $agent . ' ' . $this->integration;
    }

    /** @return array<string, mixed>|null */
    private function decodeOrNull(Response $response): ?array
    {
        if (! $response->isJson()) {
            return null;
        }
        try {
            return $response->json();
        } catch (\JsonException) {
            return null;
        }
    }
}
