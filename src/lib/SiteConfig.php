<?php

declare(strict_types=1);

namespace USDebt;

/**
 * Site-wide settings that are not secrets and do not change per server.
 *
 * NAME is the one place the site's name is written. The page header, the
 * page title, the link preview tags (og:site_name and the home page title),
 * the share words (ShareText) and the picture card all read it from here.
 * A test checks that no other file under src/ spells out a site name.
 *
 * Rob chose "Our U.S. Debt" on 2026-11-12. This constant is its only home:
 * to rename the site, change it here and nowhere else.
 */
final class SiteConfig
{
    public const NAME = 'Our U.S. Debt';

    /** Copyright holder and first year: the one place for both (not the site's name). */
    public const COPYRIGHT_HOLDER = 'bengUSA.pro';
    public const COPYRIGHT_FIRST_YEAR = 2026;
    /** The maker's own site, linked from the header logo. */
    public const HOME_URL = 'https://bengusa.pro/';

    /**
     * The footer's copyright line. The end year comes from $now (the server's
     * clock in the bootstrap timezone, America/New_York): "2026" while the year
     * is the first year, "2026–2027" (en dash) after that.
     */
    public static function copyrightLine(\DateTimeInterface $now): string
    {
        $year = (int) $now->format('Y');
        $years = $year > self::COPYRIGHT_FIRST_YEAR
            ? self::COPYRIGHT_FIRST_YEAR . "\u{2013}" . $year
            : (string) self::COPYRIGHT_FIRST_YEAR;
        return "\u{00A9} " . $years . ' ' . self::COPYRIGHT_HOLDER
            . '. Covers this site and its code, not the public-domain Treasury and Census data.';
    }

    /** The site's name as a file-name piece (lower case, hyphens): the CSV download's file name starts with it. */
    public const FILE_SLUG = 'our-us-debt';
}
