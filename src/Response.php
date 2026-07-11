<?php

declare(strict_types=1);

namespace Pictomancer;

final class Response
{
    /** @param array<string, string> $headers lowercased header name => value */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
    ) {
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function isJson(): bool
    {
        return str_starts_with($this->header('content-type') ?? '', 'application/json');
    }

    /** @return array<string, mixed> */
    public function json(): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($this->body, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
