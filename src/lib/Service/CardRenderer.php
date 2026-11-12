<?php

declare(strict_types=1);

namespace USDebt\Service;

use GdImage;
use RuntimeException;
use USDebt\Exception\CardUnavailableException;
use USDebt\Model\DebtEntry;
use USDebt\Model\ShareCard;

/**
 * Draws the share picture card: a 1200x630 PNG (the size social sites use for
 * a large link preview), with PHP gd and the self-hosted DejaVu Sans font.
 *
 * Layout: site name, headline, one detail line (plus a small note on Compare), then
 * - date range: a small line chart of the days in the range (x = calendar
 *   day, like the page chart) with the straight trend line in red when it is on;
 * - Compare: one row per term with a bar for its debt change.
 * Footer: the data source and the site address.
 *
 * Colours are neutral greys and slate blue. Red is used only for a trend line.
 * Every word comes from ShareText.
 */
final class CardRenderer
{
    public const WIDTH = 1200;
    public const HEIGHT = 630;

    private const PAD = 64;
    /** Chart is drawn this many times larger, then scaled down, for smooth lines. */
    private const SCALE = 2;
    /** Most Compare rows on one card; the rest are counted in a last row. */
    private const MAX_ROWS = 6;

    private const BACKGROUND = 0xFFFFFF;
    private const INK = 0x0F172A;
    private const MUTED = 0x475569;
    private const RULE = 0xE2E8F0;
    private const LINE = 0x334E68;
    private const FILL = 0xDCE6F0;
    private const BAR = 0x52677E;
    /** Same red as the page's trend line (css --trend). */
    private const TREND = 0xC62828;

    private readonly string $regular;
    private readonly string $bold;

    /** @var list<array{role: string, text: string, left: int, top: int, right: int, bottom: int}> */
    private array $layout = [];

    public function __construct(private readonly string $siteAddress, ?string $fontDir = null)
    {
        $fontDir ??= dirname(__DIR__) . '/Assets/fonts';
        $this->regular = $fontDir . '/DejaVuSans.ttf';
        $this->bold = $fontDir . '/DejaVuSans-Bold.ttf';
    }

    /** True when this PHP can draw cards (gd with FreeType and PNG). */
    public static function gdAvailable(): bool
    {
        return function_exists('imagecreatetruecolor')
            && function_exists('imagettftext')
            && function_exists('imagettfbbox')
            && function_exists('imagepng');
    }

    /**
     * Where the last render() put things, in card pixels, so a test can check
     * that nothing overflows or overlaps. Each box has a role: site, headline,
     * detail, note, footer, footer-rule (the line above the footer), range-label,
     * row-label, row-value, row-more and bar (a Compare bar's grey track).
     * Text boxes are the measured ink boxes gd reports for the drawn text.
     *
     * @return list<array{role: string, text: string, left: int, top: int, right: int, bottom: int}>
     */
    public function lastLayout(): array
    {
        return $this->layout;
    }

    /**
     * @return string  PNG bytes, 1200x630.
     * @throws CardUnavailableException when gd or a font is missing.
     */
    public function render(ShareCard $card): string
    {
        if (!self::gdAvailable()) {
            throw new CardUnavailableException('The PHP gd extension with FreeType is not loaded.');
        }
        foreach ([$this->regular, $this->bold] as $font) {
            if (!is_file($font)) {
                throw new CardUnavailableException("Card font missing: {$font}");
            }
        }

        $this->layout = [];
        $img = self::canvas(self::WIDTH, self::HEIGHT);
        $width = self::WIDTH - 2 * self::PAD;

        // Thin top band, site name.
        imagefilledrectangle($img, 0, 0, self::WIDTH - 1, 9, self::color($img, self::LINE));
        $this->text($img, $this->bold, 22, self::PAD, 72, self::MUTED, ShareText::SITE_NAME, 'site');

        // Headline: as large as fits in two lines.
        $size = 40;
        $lines = $this->wrap($card->headline, $this->bold, $size, $width);
        while (count($lines) > 2 && $size > 28) {
            $size -= 2;
            $lines = $this->wrap($card->headline, $this->bold, $size, $width);
        }
        $lines = array_slice($lines, 0, 3);
        $y = 140;
        $lineHeight = (int) round($size * 1.45);
        foreach ($lines as $line) {
            $this->text($img, $this->bold, $size, self::PAD, $y, self::INK, $line, 'headline');
            $y += $lineHeight;
        }
        $y += 4;
        foreach (array_slice($this->wrap($card->detail, $this->regular, 18, $width), 0, 2) as $line) {
            $this->text($img, $this->regular, 18, self::PAD, $y, self::MUTED, $line, 'detail');
            $y += 30;
        }
        // Small note (Compare: terms are shown for reference only).
        if ($card->note !== '') {
            foreach (array_slice($this->wrap($card->note, $this->regular, 15, $width), 0, 2) as $line) {
                $this->text($img, $this->regular, 15, self::PAD, $y, self::MUTED, $line, 'note');
                $y += 24;
            }
        }

        // Footer.
        $footerY = self::HEIGHT - 40;
        $ruleY = $footerY - 38;
        imageline($img, self::PAD, $ruleY, self::WIDTH - self::PAD, $ruleY, self::color($img, self::RULE));
        $this->record('footer-rule', '', self::PAD, $ruleY, self::WIDTH - self::PAD, $ruleY);
        $this->text($img, $this->regular, 15, self::PAD, $footerY, self::MUTED, ShareText::SOURCE, 'footer');
        $address = $this->fit($this->siteAddress, $this->bold, 15, 420);
        $this->text($img, $this->bold, 15, self::WIDTH - self::PAD - $this->textWidth($address, $this->bold, 15), $footerY, self::MUTED, $address, 'footer');

        // Body: between the words and the footer.
        $top = $y + 14;
        $bottom = $footerY - 64;
        if ($card->kind === ShareCard::KIND_COMPARE) {
            $this->drawRows($img, $card->rows, $top, $bottom);
        } else {
            $this->drawRange($img, $card->entries, $card->drawTrend, $top, $bottom);
        }

        ob_start();
        $ok = imagepng($img, null, 9);
        $png = (string) ob_get_clean();
        if (!$ok || $png === '') {
            throw new RuntimeException('imagepng failed');
        }
        return $png;
    }

    /**
     * Line chart of the range, with first and last date under it.
     *
     * @param list<DebtEntry> $entries  Oldest first.
     */
    private function drawRange(GdImage $img, array $entries, bool $drawTrend, int $top, int $bottom): void
    {
        $labelSize = 14;
        $chartBottom = $bottom - 30;
        $w = self::WIDTH - 2 * self::PAD;
        $h = max(40, $chartBottom - $top);
        $s = self::SCALE;
        $chart = self::canvas($w * $s, $h * $s);

        // Calendar days on x, like the page chart.
        $firstDay = self::day($entries[0]->effectiveDate);
        $span = max(1, self::day($entries[count($entries) - 1]->effectiveDate) - $firstDay);
        $values = array_map(static fn (DebtEntry $e): float => $e->totalDebt, $entries);
        $trend = $drawTrend ? TrendService::daily($entries) : null;
        $min = min($values);
        $max = max($values);
        if ($trend !== null) {
            foreach ([$trend['fitted'][0][1], $trend['fitted'][count($trend['fitted']) - 1][1]] as $t) {
                $min = min($min, $t * 1e12);
                $max = max($max, $t * 1e12);
            }
        }
        $pad = ($max - $min) * 0.08;
        if ($pad <= 0.0) {
            $pad = max(abs($max) * 0.01, 1.0);
        }
        $min -= $pad;
        $max += $pad;
        $px = static fn (int $day): float => ($day - $firstDay) / $span * ($w * $s - 1);
        $py = static fn (float $v): float => ($h * $s - 1) - ($v - $min) / ($max - $min) * ($h * $s - 1);

        // Light guide lines.
        $rule = self::color($chart, self::RULE);
        imagesetthickness($chart, $s);
        foreach ([0.0, 0.5, 1.0] as $f) {
            $gy = (int) round($f * ($h * $s - 1));
            imageline($chart, 0, $gy, $w * $s - 1, $gy, $rule);
        }

        if (count($entries) === 1) {
            imagefilledellipse($chart, (int) round(($w * $s) / 2), (int) round($py($values[0])), 9 * $s, 9 * $s, self::color($chart, self::LINE));
        } else {
            // At most one point per pixel column: enough for the eye, quick to draw.
            $line = [];
            $lastX = null;
            foreach ($entries as $entry) {
                $x = (int) round($px(self::day($entry->effectiveDate)));
                $y = (int) round($py($entry->totalDebt));
                if ($x === $lastX) {
                    $line[count($line) - 1] = [$x, $y];
                    continue;
                }
                $line[] = [$x, $y];
                $lastX = $x;
            }
            $polygon = [$line[0][0], $h * $s - 1];
            foreach ($line as [$x, $y]) {
                $polygon[] = $x;
                $polygon[] = $y;
            }
            $polygon[] = $line[count($line) - 1][0];
            $polygon[] = $h * $s - 1;
            if (count($polygon) >= 6) {
                imagefilledpolygon($chart, $polygon, self::color($chart, self::FILL));
            }
            imagesetthickness($chart, 3 * $s);
            $ink = self::color($chart, self::LINE);
            for ($i = 1, $n = count($line); $i < $n; $i++) {
                imageline($chart, $line[$i - 1][0], $line[$i - 1][1], $line[$i][0], $line[$i][1], $ink);
            }
        }

        if ($trend !== null) {
            $fitted = $trend['fitted'];
            $a = $fitted[0];
            $b = $fitted[count($fitted) - 1];
            imagesetthickness($chart, 3 * $s);
            imageline(
                $chart,
                (int) round($px(self::day($a[0]))),
                (int) round($py($a[1] * 1e12)),
                (int) round($px(self::day($b[0]))),
                (int) round($py($b[1] * 1e12)),
                self::color($chart, self::TREND)
            );
        }

        imagecopyresampled($img, $chart, self::PAD, $top, 0, 0, $w, $h, $w * $s, $h * $s);

        $from = Html::shortDate($entries[0]->effectiveDate);
        $to = Html::shortDate($entries[count($entries) - 1]->effectiveDate);
        $labelY = $top + $h + 26;
        $this->text($img, $this->regular, $labelSize, self::PAD, $labelY, self::MUTED, $from, 'range-label');
        if ($to !== $from) {
            $this->text($img, $this->regular, $labelSize, self::WIDTH - self::PAD - $this->textWidth($to, $this->regular, $labelSize), $labelY, self::MUTED, $to, 'range-label');
        }
    }

    /**
     * One row per Compare line: name, a bar for the change, the amount.
     *
     * @param list<array{label: string, change: float}> $rows
     */
    private function drawRows(GdImage $img, array $rows, int $top, int $bottom): void
    {
        $shown = $rows;
        $more = 0;
        if (count($rows) > self::MAX_ROWS) {
            $shown = array_slice($rows, 0, self::MAX_ROWS - 1);
            $more = count($rows) - count($shown);
        }
        $slots = count($shown) + ($more > 0 ? 1 : 0);
        $rowHeight = (int) min(52, floor(($bottom - $top) / max(1, $slots)));
        $size = $rowHeight >= 44 ? 17 : 15;

        $labelWidth = 490;
        $valueWidth = 210;
        $barLeft = self::PAD + $labelWidth + 20;
        $barRight = self::WIDTH - self::PAD - $valueWidth - 20;

        $biggest = 0.0;
        $hasNegative = false;
        foreach ($shown as $row) {
            $biggest = max($biggest, abs($row['change']));
            $hasNegative = $hasNegative || $row['change'] < 0;
        }
        // Zero sits at the left, or in the middle when some terms went down.
        $zero = $hasNegative ? (int) round(($barLeft + $barRight) / 2) : $barLeft;
        $reach = $hasNegative ? ($barRight - $barLeft) / 2 : $barRight - $barLeft;

        $bar = self::color($img, self::BAR);
        $rule = self::color($img, self::RULE);
        // A few rows sit in the middle of the space, not at the top.
        $y = $top + (int) floor(max(0, ($bottom - $top) - $slots * $rowHeight) / 2);
        foreach ($shown as $row) {
            $base = $y + (int) round($rowHeight * 0.62);
            $this->text($img, $this->regular, $size, self::PAD, $base, self::INK, $this->fit($row['label'], $this->regular, $size, $labelWidth), 'row-label');
            $value = ShareText::compareChange($row['change']);
            $this->text($img, $this->bold, $size, self::WIDTH - self::PAD - $this->textWidth($value, $this->bold, $size), $base, self::INK, $value, 'row-value');

            $barTop = $y + (int) round($rowHeight * 0.25);
            $barBottom = $y + (int) round($rowHeight * 0.75);
            imagefilledrectangle($img, $barLeft, $barTop, $barRight, $barBottom, $rule);
            $this->record('bar', '', $barLeft, $barTop, $barRight, $barBottom);
            $length = $biggest > 0.0 ? (int) round(abs($row['change']) / $biggest * $reach) : 0;
            if ($length > 0) {
                [$from, $to] = $row['change'] >= 0 ? [$zero, $zero + $length] : [$zero - $length, $zero];
                imagefilledrectangle($img, $from, $barTop, $to, $barBottom, $bar);
            }
            $y += $rowHeight;
        }
        if ($more > 0) {
            $this->text($img, $this->regular, $size, self::PAD, $y + (int) round($rowHeight * 0.62), self::MUTED, ShareText::moreRows($more), 'row-more');
        }
    }

    private static function canvas(int $width, int $height): GdImage
    {
        $img = imagecreatetruecolor($width, $height);
        if ($img === false) {
            throw new RuntimeException('imagecreatetruecolor failed');
        }
        imagefilledrectangle($img, 0, 0, $width - 1, $height - 1, self::color($img, self::BACKGROUND));
        return $img;
    }

    private static function color(GdImage $img, int $rgb): int
    {
        $color = imagecolorallocate($img, ($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF);
        if ($color === false) {
            throw new RuntimeException('imagecolorallocate failed');
        }
        return $color;
    }

    /** Draw one line of text and record its measured box (see lastLayout). */
    private function text(GdImage $img, string $font, int $size, int $x, int $baseline, int $rgb, string $text, string $role): void
    {
        if ($text === '') {
            return;
        }
        $box = imagettftext($img, $size, 0, $x, $baseline, self::color($img, $rgb), $font, $text);
        if ($box !== false) {
            $xs = [$box[0], $box[2], $box[4], $box[6]];
            $ys = [$box[1], $box[3], $box[5], $box[7]];
            $this->record($role, $text, min($xs), min($ys), max($xs), max($ys));
        }
    }

    private function record(string $role, string $text, int $left, int $top, int $right, int $bottom): void
    {
        $this->layout[] = ['role' => $role, 'text' => $text, 'left' => $left, 'top' => $top, 'right' => $right, 'bottom' => $bottom];
    }

    private function textWidth(string $text, string $font, int $size): int
    {
        $box = imagettfbbox($size, 0, $font, $text);
        if ($box === false) {
            return 0;
        }
        return (int) (max($box[2], $box[4]) - min($box[0], $box[6]));
    }

    /**
     * Break text into lines no wider than $maxWidth, at spaces. A date such as
     * "Nov 9, 2026" is never split over two lines.
     *
     * @return list<string>
     */
    private function wrap(string $text, string $font, int $size, int $maxWidth): array
    {
        $text = (string) preg_replace('/\b([A-Z][a-z]{2}) (\d{1,2}), (\d{4})\b/u', "\$1\u{00A0}\$2,\u{00A0}\$3", trim($text));
        $lines = [];
        $current = '';
        foreach (preg_split('/ +/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $try = $current === '' ? $word : $current . ' ' . $word;
            if ($current !== '' && $this->textWidth($try, $font, $size) > $maxWidth) {
                $lines[] = $current;
                $current = $word;
            } else {
                $current = $try;
            }
        }
        if ($current !== '') {
            $lines[] = $current;
        }
        return array_map(fn (string $l): string => $this->fit($l, $font, $size, $maxWidth), $lines);
    }

    /** The text, cut with "…" when it is wider than $maxWidth. */
    private function fit(string $text, string $font, int $size, int $maxWidth): string
    {
        if ($this->textWidth($text, $font, $size) <= $maxWidth) {
            return $text;
        }
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        while ($chars !== [] && $this->textWidth(implode('', $chars) . "\u{2026}", $font, $size) > $maxWidth) {
            array_pop($chars);
        }
        return rtrim(implode('', $chars)) . "\u{2026}";
    }

    /** Whole days since 1970-01-01 for a Y-m-d date (UTC). */
    private static function day(string $isoDate): int
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $isoDate, new \DateTimeZone('UTC'));
        if ($date === false) {
            throw new \InvalidArgumentException("Not a Y-m-d date: {$isoDate}");
        }
        return intdiv($date->getTimestamp(), 86_400);
    }
}
