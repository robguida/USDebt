<?php

declare(strict_types=1);

namespace USDebt\Http;

/**
 * One finished response: the HTTP status code, the body, and any extra
 * headers (name => value). Pages send no extra headers; the share card
 * sends its image type and cache headers.
 */
final class Response
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers = [],
    ) {
    }

    /** Send the status, the headers and the body. */
    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        echo $this->body;
    }
}
