<?php

declare(strict_types=1);

namespace USDebt\Http;

use USDebt\Exception\HttpException;

/**
 * Plain GET client. Uses the curl extension when it is loaded,
 * otherwise PHP streams (needs allow_url_fopen). Both honour the timeout.
 */
final class NativeHttpFetcher implements HttpFetcher
{
    private const USER_AGENT = 'UsDebt/1.0 (+https://usdebt.bengusa.pro)';

    public function get(string $url, int $timeoutSeconds): string
    {
        return extension_loaded('curl')
            ? $this->viaCurl($url, $timeoutSeconds)
            : $this->viaStream($url, $timeoutSeconds);
    }

    private function viaCurl(string $url, int $timeoutSeconds): string
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new HttpException('curl_init failed');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeoutSeconds),
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        if (PHP_VERSION_ID < 80000) {
            curl_close($ch);
        }
        if (!is_string($body)) {
            throw new HttpException("GET {$url} failed: {$error}");
        }
        if ($status < 200 || $status >= 300) {
            throw new HttpException("GET {$url} returned HTTP {$status}");
        }
        return $body;
    }

    private function viaStream(string $url, int $timeoutSeconds): string
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $timeoutSeconds,
                'ignore_errors' => true,
                'follow_location' => 0,
                'header' => "Accept: application/json\r\nUser-Agent: " . self::USER_AGENT . "\r\n",
            ],
        ]);
        $previous = ini_set('default_socket_timeout', (string) $timeoutSeconds);
        $body = false;
        $headers = [];
        try {
            $handle = @fopen($url, 'rb', false, $context);
            if (is_resource($handle)) {
                $meta = stream_get_meta_data($handle);
                $headers = is_array($meta['wrapper_data'] ?? null) ? $meta['wrapper_data'] : [];
                $body = stream_get_contents($handle);
                fclose($handle);
            }
        } finally {
            if ($previous !== false) {
                ini_set('default_socket_timeout', $previous);
            }
        }
        if (!is_string($body)) {
            $error = error_get_last()['message'] ?? 'unknown error';
            throw new HttpException("GET {$url} failed: {$error}");
        }
        $status = 0;
        if (isset($headers[0]) && preg_match('#^HTTP/\S+\s+(\d{3})#', $headers[0], $m)) {
            $status = (int) $m[1];
        }
        if ($status < 200 || $status >= 300) {
            throw new HttpException("GET {$url} returned HTTP {$status}");
        }
        return $body;
    }
}
