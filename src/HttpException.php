<?php

declare(strict_types=1);

namespace Pictomancer;

final class HttpException extends PictomancerException
{
    /** @param array<string, mixed>|null $body decoded JSON body, when the response was JSON */
    public function __construct(
        public readonly int $statusCode,
        public readonly ?array $body,
        public readonly string $rawBody,
    ) {
        $detail = is_array($body) && isset($body['detail']) ? (string) $body['detail'] : $rawBody;
        parent::__construct(sprintf('HTTP %d: %s', $statusCode, $detail));
    }
}
