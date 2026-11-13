<?php

declare(strict_types=1);

namespace USDebt\Http;

use USDebt\Exception\HttpException;
use USDebt\Service\ShareLinks;

/**
 * Plain HTTPS GET client. Uses the curl extension (required in production);
 * falls back to PHP streams (needs allow_url_fopen) only when curl is missing.
 *
 * Both paths:
 * - verify the server's TLS certificate and host name,
 * - stop the whole request after the timeout (not per read),
 * - stop and fail when the body grows past the size cap,
 * - do not follow redirects.
 */
final class NativeHttpFetcher implements HttpFetcher
{
    private const USER_AGENT = 'UsDebt/1.0 (+' . ShareLinks::DEFAULT_BASE_URL . ')';

    /** One page of the full history is under 1 MB; 20 MB is a generous ceiling. */
    public const MAX_BYTES = 20 * 1024 * 1024;

    private const CHUNK_BYTES = 65536;

    public function __construct(private readonly int $maxBytes = self::MAX_BYTES)
    {
    }

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
        $body = '';
        $tooBig = false;
        $max = $this->maxBytes;
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeoutSeconds),
            CURLOPT_TIMEOUT => $timeoutSeconds, // whole request
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_MAXFILESIZE => $max,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$tooBig, $max): int {
                if (strlen($body) + strlen($chunk) > $max) {
                    $tooBig = true;
                    return 0; // makes curl abort the transfer
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        if ($tooBig || curl_errno($ch) === CURLE_FILESIZE_EXCEEDED) {
            throw new HttpException("GET {$url} stopped: response larger than {$max} bytes");
        }
        if ($ok === false) {
            throw new HttpException("GET {$url} failed: {$error}");
        }
        if ($status < 200 || $status >= 300) {
            throw new HttpException("GET {$url} returned HTTP {$status}");
        }
        return $body;
    }

    private function viaStream(string $url, int $timeoutSeconds): string
    {
        if (!str_starts_with(strtolower($url), 'https://')) {
            throw new HttpException("GET {$url} refused: only https is allowed");
        }
        $deadline = microtime(true) + $timeoutSeconds;
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $timeoutSeconds,
                'ignore_errors' => true,
                'follow_location' => 0,
                'header' => "Accept: application/json\r\nUser-Agent: " . self::USER_AGENT . "\r\n",
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
            ],
        ]);
        $previous = ini_set('default_socket_timeout', (string) $timeoutSeconds);
        try {
            $handle = @fopen($url, 'rb', false, $context);
        } finally {
            if ($previous !== false) {
                ini_set('default_socket_timeout', $previous);
            }
        }
        if (!is_resource($handle)) {
            $error = error_get_last()['message'] ?? 'unknown error';
            throw new HttpException("GET {$url} failed: {$error}");
        }

        try {
            $meta = stream_get_meta_data($handle);
            $headers = is_array($meta['wrapper_data'] ?? null) ? $meta['wrapper_data'] : [];
            $body = '';
            while (!feof($handle)) {
                $left = $deadline - microtime(true);
                if ($left <= 0) {
                    throw new HttpException("GET {$url} timed out after {$timeoutSeconds}s");
                }
                stream_set_timeout($handle, (int) $left, (int) (($left - (int) $left) * 1_000_000));
                $chunk = fread($handle, self::CHUNK_BYTES);
                if ($chunk === false) {
                    throw new HttpException("GET {$url} failed while reading");
                }
                if (stream_get_meta_data($handle)['timed_out']) {
                    throw new HttpException("GET {$url} timed out after {$timeoutSeconds}s");
                }
                if (strlen($body) + strlen($chunk) > $this->maxBytes) {
                    throw new HttpException("GET {$url} stopped: response larger than {$this->maxBytes} bytes");
                }
                $body .= $chunk;
            }
        } finally {
            fclose($handle);
        }

        $status = 0;
        if (isset($headers[0]) && is_string($headers[0]) && preg_match('#^HTTP/\S+\s+(\d{3})#', $headers[0], $m)) {
            $status = (int) $m[1];
        }
        if ($status < 200 || $status >= 300) {
            throw new HttpException("GET {$url} returned HTTP {$status}");
        }
        return $body;
    }
}
