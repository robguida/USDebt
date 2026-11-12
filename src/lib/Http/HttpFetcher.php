<?php

declare(strict_types=1);

namespace USDebt\Http;

use USDebt\Exception\HttpException;

interface HttpFetcher
{
    /**
     * GET a URL and return the response body.
     *
     * @throws HttpException on a network error, a timeout or a non-2xx status.
     */
    public function get(string $url, int $timeoutSeconds): string;
}
