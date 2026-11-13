<?php

declare(strict_types=1);

namespace USDebt\Service;

use USDebt\Model\Per;

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
    /** The real address: the site lives under /ourusdebt on bengusa.pro. */
    public const DEFAULT_BASE_URL = 'https://bengusa.pro/ourusdebt';
    public const ENV_BASE_URL = 'USDEBT_BASE_URL';
    public const CARD_PATH = 'card.php';

    private readonly string $baseUrl;

    public function __construct(?string $baseUrl = null)
    {
        $normalized = $baseUrl === null ? null : self::normalize($baseUrl);
        $this->baseUrl = $normalized ?? self::DEFAULT_BASE_URL;
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

    /**
     * The card link on this site, root-relative ("/ourusdebt/card.php?..."), for the
     * Share menu's preview and "Download picture". A browser only honours the
     * download attribute on a same-site link, so this one has no host. It
     * starts at the site root plus the base URL's path (the effective base
     * URL, so unset means /ourusdebt), so a page address with extra path parts, such as
     * /index.php/extra, still points at the real card and not at
     * /index.php/card.php.
     */
    public function localCard(string $query): string
    {
        // One rule: the path always comes from the effective base URL.
        // Local development at "/" sets USDEBT_BASE_URL=http://localhost:8000.
        $path = (string) parse_url($this->baseUrl, PHP_URL_PATH);
        return $path . '/' . self::CARD_PATH . ($query === '' ? '' : '?' . $query);
    }

    /**
     * Plain share links for the Share menu: no scripts or SDKs from the
     * social sites. Every value is percent-encoded (spaces as %20, which
     * mail apps need). The page script builds the same links again when the
     * trend switch changes the page link (js/usdebt.js shareHrefs).
     *
     * @return array{x: string, facebook: string, linkedin: string, reddit: string, email: string}
     */
    public static function networks(string $url, string $text, string $subject, Per $per = Per::Total): array
    {
        $u = rawurlencode($url);
        return [
            'x' => 'https://x.com/intent/tweet?text=' . rawurlencode($text) . '&url=' . $u,
            'facebook' => 'https://www.facebook.com/sharer/sharer.php?u=' . $u,
            'linkedin' => 'https://www.linkedin.com/sharing/share-offsite/?url=' . $u,
            'reddit' => 'https://www.reddit.com/submit?url=' . $u . '&title=' . rawurlencode($text),
            'email' => 'mailto:?subject=' . rawurlencode($subject) . '&body=' . rawurlencode(ShareText::emailBody($text, $url, $per)),
        ];
    }
}
