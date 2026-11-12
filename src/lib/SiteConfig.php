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
 * The name is not final yet ("Our U.S. Debt" or "U.S. Debt Tracker"):
 * change it here and nowhere else.
 */
final class SiteConfig
{
    public const NAME = 'Our U.S. Debt';
}
