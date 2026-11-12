<?php

declare(strict_types=1);

namespace USDebt\Http;

/**
 * One finished page: the HTTP status code and the HTML body.
 */
final class Response
{
    public function __construct(
        public readonly int $status,
        public readonly string $body,
    ) {
    }
}
