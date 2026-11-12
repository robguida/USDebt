<?php

declare(strict_types=1);

namespace USDebt\Service;

/**
 * Absolute links for sharing: the page link (og:url) and the card image
 * link (og:image).
 *
 * The site address comes from configuration (USDEBT_BASE_URL), never from the
 * request's Host header, so a forged Host cannot change the links that
 * social sites copy.
 */
final class ShareLinks
{
    public const DEFAULT_BASE_URL = 'https://usdebt.bengusa.pro';
    public const ENV_BASE_URL = 'USDEBT_BASE_URL';
    public const CARD_PATH = 'card.php';

    private readonly string $baseUrl;

    public function __construct(string $baseUrl = self::DEFAULT_BASE_URL)
    {
        $this->baseUrl = self::normalize($baseUrl) ?? self::DEFAULT_BASE_URL;
    }

    /** From USDEBT_BASE_URL, or the default. A bad value is logged and the default is used. */
    public static function fromEnvironment(): self
    {
        $value = getenv(self::ENV_BASE_URL);
        if ($value === false || trim($value) === '') {
            return new self();
        }
        if (self::normalize($value) === null) {
            error_log('UsDebt: ' . self::ENV_BASE_URL . ' is not a plain http(s) address; using ' . self::DEFAULT_BASE_URL . '.');
        }
        return new self($value);
    }

    /**
     * "https://host[:port][/path]" with no trailing slash, or null when the
     * value is not a plain http(s) address (no user, query or fragment).
     */
    public static function normalize(string $value): ?string
    {
        $value = trim($value);
        if (!preg_match('#^https?://#i', $value)) {
            return null;
        }
        $parts = parse_url($value);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || str_contains($value, '?') || str_contains($value, '#')) {
            return null;
        }
        $host = strtolower($parts['host']);
        if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/', $host)) {
            return null;
        }
        $path = $parts['path'] ?? '';
        if ($path !== '' && !preg_match('#^(/[A-Za-z0-9._~-]+)*/?$#', $path)) {
            return null;
        }
        return strtolower($parts['scheme']) . '://' . $host
            . (isset($parts['port']) ? ':' . $parts['port'] : '')
            . rtrim($path, '/');
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    /** The page link for a view's query ('' = the home page). */
    public function page(string $query): string
    {
        return $this->baseUrl . '/' . ($query === '' ? '' : '?' . $query);
    }

    /** The card image link for a view's query ('' = the default view). */
    public function card(string $query): string
    {
        return $this->baseUrl . '/' . self::CARD_PATH . ($query === '' ? '' : '?' . $query);
    }
}
