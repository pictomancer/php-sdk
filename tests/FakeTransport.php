<?php

declare(strict_types=1);

namespace Pictomancer\Tests;

use Pictomancer\Response;
use Pictomancer\Transport;

final class FakeTransport implements Transport
{
    /** @var list<array<string, mixed>> */
    public array $calls = [];

    /** @var list<Response> */
    private array $responses;

    public function __construct(Response ...$responses)
    {
        $this->responses = $responses;
    }

    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): Response
    {
        $this->calls[] = compact('method', 'url', 'headers', 'body', 'timeout');

        $response = array_shift($this->responses);
        if ($response === null) {
            throw new \RuntimeException('FakeTransport: no canned response left');
        }

        return $response;
    }

    /** @return array<string, mixed> */
    public function lastCall(): array
    {
        if ($this->calls === []) {
            throw new \RuntimeException('FakeTransport: no calls recorded');
        }

        return $this->calls[count($this->calls) - 1];
    }
}
