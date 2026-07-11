<?php

declare(strict_types=1);

namespace Pictomancer;

interface Transport
{
    /**
     * Perform one HTTP request and return the raw response. Throws
     * PictomancerException on a transport-level failure (no response).
     *
     * @param array<string, string> $headers
     */
    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): Response;
}
