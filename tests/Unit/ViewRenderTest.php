<?php

declare(strict_types=1);

namespace USDebt\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use USDebt\Controller\FrontController;
use USDebt\Service\PresidentService;

/**
 * Renders whole pages and checks the markup rules for the redesign:
 * self-hosted Chart.js 4 only, no inline script code (CSP-ready), neutral
 * wording, and the second-term marker on the Compare chart.
 */
final class ViewRenderTest extends TestCase
{
    /** sha256 of Chart.js 4.5.1 dist/chart.umd.min.js, as published by npm and jsDelivr. */
    private const CHART_JS_SHA256 = '48444a82d4edcb5bec0f1965faacdde18d9c17db3063d042abada2f705c9f54a';

    private const CAPTION = 'Congress sets spending and taxes. Presidential terms are shown for reference only.';

    private static function app(): FrontController
    {
        return FrontControllerTest::app(FrontControllerTest::source());
    }

    /** @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>}> */
    public static function pages(): array
    {
        $all = array_map('strval', array_keys(PresidentService::getPresidentConfig()));
        return [
            'default' => [[], []],
            'custom range' => [['start_date' => '2020-01-01', 'end_date' => '2026-11-09'], []],
            'compare all' => [[], ['submit' => 'Compare', 'compare_pres' => $all]],
            'bad date' => [['start_date' => '2026-02-30'], []],
        ];
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     */
    #[DataProvider('pages')]
    public function testPageIsCspReadyAndSelfHosted(array $query, array $post): void
    {
        $html = self::app()->handle($query, $post)->body;

        self::assertStringStartsWith('<!DOCTYPE html>', ltrim($html));
        self::assertStringContainsString('<html lang="en">', $html);
        self::assertStringContainsString('<meta name="viewport" content="width=device-width, initial-scale=1">', $html);
        self::assertMatchesRegularExpression('#<title>[^<]*Our U\.S\. Debt</title>#', $html);

        // Every <script> either loads a local file or is a JSON data block. No inline code.
        preg_match_all('#<script\b([^>]*)>(.*?)</script>#is', $html, $scripts, PREG_SET_ORDER);
        self::assertNotEmpty($scripts);
        foreach ($scripts as [, $attrs, $body]) {
            if (str_contains($attrs, 'type="application/json"')) {
                json_decode($body, true, 512, JSON_THROW_ON_ERROR);
                continue;
            }
            self::assertMatchesRegularExpression('#\ssrc="(lib|js)/[^"]+"#', $attrs, 'local script file');
            self::assertSame('', trim($body), 'no inline script code');
        }

        // No inline event handlers, no inline styles, no javascript: links.
        self::assertDoesNotMatchRegularExpression('#<[a-z][^>]*\son[a-z]+\s*=#i', $html);
        self::assertDoesNotMatchRegularExpression('#<[a-z][^>]*\sstyle\s*=#i', $html);
        self::assertStringNotContainsStringIgnoringCase('javascript:', $html);

        // Nothing loaded from another site.
        self::assertDoesNotMatchRegularExpression('#<(script|link|img)\b[^>]*\s(src|href)="(https?:)?//#i', $html);

        // Old libraries are gone.
        foreach (['jquery', 'flot', 'Chart.bundle', 'datepicker'] as $old) {
            self::assertStringNotContainsStringIgnoringCase($old, $html, $old);
        }
        self::assertStringContainsString('src="lib/chart.umd.min.js', $html);
        self::assertStringContainsString('src="js/usdebt.js', $html);

        // Neutral wording.
        self::assertStringContainsString('View by presidential term', $html);
        self::assertStringContainsString(self::CAPTION, $html);
        self::assertStringNotContainsStringIgnoringCase('Press a Prez', $html);

        // Native date pickers.
        self::assertStringContainsString('<input type="date" id="start_date"', $html);
        self::assertStringContainsString('<input type="date" id="end_date"', $html);
    }

    public function testDefaultViewHasHeadlineCardsChipsSwitchAndSummary(): void
    {
        $html = self::app()->handle([], [])->body;

        self::assertStringContainsString('Debt today', $html);
        self::assertStringContainsString('Change in this range', $html);
        self::assertStringContainsString('Per day', $html);

        foreach (['1M', '1Y', '5Y', 'This term'] as $chip) {
            self::assertStringContainsString('>' . $chip . '</a>', $html);
        }
        self::assertMatchesRegularExpression('#aria-current="true">1M</a>#', $html, 'the default range is the last month');

        self::assertStringContainsString('role="tablist"', $html);
        foreach (['Chart', 'Stats', 'Table'] as $tab) {
            self::assertMatchesRegularExpression('#<button type="button" role="tab"[^>]*>' . $tab . '</button>#', $html);
        }
        self::assertStringContainsString('aria-describedby="chart_summary"', $html);
        self::assertMatchesRegularExpression('#Total debt went from \$[0-9,.]+ on #', $html);
    }

    public function testEveryTermHasALabelledCompareBox(): void
    {
        $html = self::app()->handle([], [])->body;

        foreach (array_keys(PresidentService::getPresidentConfig()) as $key) {
            self::assertStringContainsString('<label class="term-pick" for="pres_' . $key . '">', $html);
            self::assertStringContainsString('id="pres_' . $key . '" name="compare_pres[]" value="' . $key . '"', $html);
        }
        self::assertStringContainsString('<button type="submit" class="btn btn-primary" id="compare_submit" name="submit" value="Compare">', $html);
    }

    public function testCompareMarksWhereTheSecondTermStarts(): void
    {
        $trump = [];
        foreach (PresidentService::getPresidentConfig() as $key => $term) {
            if ($term['pres'] === 'Donald Trump') {
                $trump[] = (string) $key;
            }
        }
        self::assertCount(2, $trump);

        $html = self::app()->handle([], ['submit' => 'Compare', 'compare_pres' => $trump])->body;

        // Both terms of one president are 1 line; Compare needs 2 picks, and these are 2 picks.
        $chart = FrontControllerTest::chartJson($html);
        self::assertSame('compare', $chart['type']);
        self::assertCount(1, $chart['series']);
        $starts = $chart['series'][0]['term_starts'];
        self::assertCount(1, $starts);
        self::assertContains($starts[0], array_column($chart['series'][0]['points'], 0), 'the marker sits on a real point of the line');
        self::assertGreaterThan(1, $starts[0]);
        self::assertStringContainsString(
            'Donald Trump: the second term starts at month ' . $starts[0] . '. The years between the terms are left out.',
            $html
        );
        self::assertStringContainsString(self::CAPTION, $html);
        self::assertStringContainsString('Months in office', $this->script());
        self::assertMatchesRegularExpression('#id="start_date"[^>]*value="\d{4}-\d{2}-\d{2}"#', $html, 'date boxes are prefilled on Compare');
    }

    public function testCompareAllPresidentsListsEveryLineInTheSummary(): void
    {
        $all = array_map('strval', array_keys(PresidentService::getPresidentConfig()));
        $html = self::app()->handle([], ['submit' => 'Compare', 'compare_pres' => $all])->body;

        foreach (['Bill Clinton', 'George W. Bush', 'Barack Obama', 'Joseph Biden', 'Donald Trump'] as $name) {
            self::assertMatchesRegularExpression('#<li>' . preg_quote($name, '#') . ' \([^)]*\): [+-]?\$[0-9.,]+ [a-z]+ after [0-9]+ months? in office\.</li>#', $html);
        }
    }

    public function testVendoredChartJsMatchesThePublishedChecksum(): void
    {
        $file = dirname(__DIR__, 2) . '/src/Public/lib/chart.umd.min.js';
        self::assertFileExists($file);
        self::assertSame(self::CHART_JS_SHA256, hash_file('sha256', $file));
        self::assertStringStartsWith("/*!\n * Chart.js v4.5.1", (string) file_get_contents($file, false, null, 0, 40));
    }

    public function testOldLibrariesAreDeletedAndColorsStayNeutral(): void
    {
        $public = dirname(__DIR__, 2) . '/src/Public';
        foreach (['lib/jquery.js', 'lib/jquery-ui', 'lib/flot-master', 'lib/Chart.bundle.min.js'] as $old) {
            self::assertFileDoesNotExist($public . '/' . $old, $old);
        }

        $css = (string) file_get_contents($public . '/css/usdebt.css');
        self::assertStringContainsString('--trend:', $css, 'red is defined once, for the trend line');
        self::assertStringContainsString('@media (prefers-color-scheme: dark)', $css);
        self::assertMatchesRegularExpression('/font-variant-numeric:\s*tabular-nums/', $css);

        $js = $this->script();
        self::assertStringNotContainsString('innerHTML', $js, 'the script never writes raw HTML');
        self::assertStringContainsString("cssVar('--series-'", $js, 'term colors come from CSS variables');
        self::assertStringContainsString("cssVar('--trend'", $js, 'the trend color comes from CSS');
    }

    private function script(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/src/Public/js/usdebt.js');
    }
}
