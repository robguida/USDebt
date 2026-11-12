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
            self::assertMatchesRegularExpression(
                '#<li>' . preg_quote($name, '#') . ' \([^)]*\): [+-]?\$[0-9.,]+ [a-z]+ after [0-9]+ months? in office\.'
                . ' Trend: about [+-]?\$[0-9.,]+ [a-z]+ per month \(straight line\)\.</li>#',
                $html
            );
        }
        self::assertStringContainsString("Each dashed line is that term's trend (straight line), in the same color.", $html);
    }

    public function testTrendButtonIsOnByDefaultAndDailyChartHasTrendAndProjection(): void
    {
        $html = self::app()->handle(['start_date' => '2026-01-01', 'end_date' => '2026-11-09'], [])->body;

        // A real toggle button, hidden until the page script runs; its name stays "Trend line".
        self::assertStringContainsString(
            '<button type="button" class="btn btn-quiet btn-toggle" id="trend_toggle" aria-pressed="true" hidden>'
            . 'Trend line<span class="toggle-state" aria-hidden="true">: On</span></button>',
            $html
        );

        $chart = FrontControllerTest::chartJson($html);
        self::assertSame(['debt', 'trend', 'projection'], array_column($chart['series'], 'key'));
        self::assertSame('Trend (straight line)', $chart['series'][1]['label']);
        self::assertTrue($chart['series'][2]['projection']);
        $lastDay = $chart['series'][0]['points'][count($chart['series'][0]['points']) - 1][0];
        self::assertGreaterThan($lastDay, $chart['series'][2]['points'][0][0], 'the projection starts after the last data day');

        // Text summary for screen readers and for anyone who cannot tell the lines apart.
        self::assertMatchesRegularExpression(
            '#<p class="trend-summary">Trend: about \+\$[0-9.,]+ [a-z]+ per day \(straight line\)\.'
            . ' Projection to [A-Z][a-z]{2} \d{1,2}, \d{4}: about \$[0-9.,]+ trillion\.'
            . '<span class="trend-off-note" hidden> \(trend line hidden\)</span></p>#',
            $html
        );

        // Trend on: links carry no trend parameter, the hidden form field is not sent.
        self::assertStringNotContainsString('trend=0', $html);
        self::assertStringContainsString('<input type="hidden" name="trend" value="0" id="trend_field" disabled>', $html);
        self::assertStringContainsString('<form id="compare" method="post" action="./#results" data-trend-link>', $html);
    }

    public function testTrendOffInTheUrlIsKeptByEveryLinkAndForm(): void
    {
        $html = self::app()->handle(['start_date' => '2026-01-01', 'end_date' => '2026-11-09', 'trend' => '0'], [])->body;

        self::assertStringContainsString('id="trend_toggle" aria-pressed="false" hidden>Trend line<span class="toggle-state" aria-hidden="true">: Off</span>', $html);
        self::assertStringContainsString('<span class="trend-off-note"> (trend line hidden)</span>', $html, 'the summary says the line is hidden');

        // The data is still sent, so the switch can turn it back on without a reload.
        self::assertContains('trend', array_column(FrontControllerTest::chartJson($html)['series'], 'key'));

        // Links and forms keep trend=0.
        self::assertStringContainsString('<a class="brand" href="./?trend=0" data-trend-link>', $html);
        self::assertStringContainsString('<input type="hidden" name="trend" value="0" id="trend_field">', $html);
        self::assertStringContainsString('<form id="compare" method="post" action="./?trend=0#results" data-trend-link>', $html);
        preg_match_all('#<a class="(?:chip|term-link)" href="([^"]+)" data-trend-link#', $html, $links);
        self::assertNotEmpty($links[1]);
        foreach ($links[1] as $href) {
            self::assertStringContainsString('&amp;trend=0#results', $href);
        }
    }

    public function testOnlyTrendZeroTurnsTheTrendOff(): void
    {
        foreach (['1', 'off', 'false', ''] as $value) {
            $html = self::app()->handle(['trend' => $value], [])->body;
            self::assertStringContainsString('id="trend_toggle" aria-pressed="true"', $html, 'trend=' . $value);
        }
        $html = self::app()->handle(['trend' => ['0']], [])->body;
        self::assertStringContainsString('id="trend_toggle" aria-pressed="true"', $html, 'an array is ignored');
    }

    public function testCompareHasTheTrendButtonAndKeepsTrendOffFromTheUrl(): void
    {
        $all = array_map('strval', array_keys(PresidentService::getPresidentConfig()));

        $html = self::app()->handle([], ['submit' => 'Compare', 'compare_pres' => $all])->body;
        self::assertStringContainsString('id="trend_toggle" aria-pressed="true"', $html);
        foreach (FrontControllerTest::chartJson($html)['series'] as $s) {
            self::assertIsArray($s['trend']);
        }

        // The Compare form posts to ./?trend=0 when the trend is off.
        $html = self::app()->handle(['trend' => '0'], ['submit' => 'Compare', 'compare_pres' => $all])->body;
        self::assertStringContainsString('id="trend_toggle" aria-pressed="false"', $html);
        self::assertStringContainsString('<span class="trend-off-note"> (trend lines hidden)</span>', $html);
    }

    public function testNoTrendButtonWithoutATrendLine(): void
    {
        // One day: no line can be drawn, so nothing to switch and no trend sentence.
        $html = self::app()->handle(['start_date' => '2026-11-09', 'end_date' => '2026-11-09'], [])->body;
        self::assertStringNotContainsString('trend_toggle', $html);
        self::assertStringNotContainsString('trend-summary', $html);

        // Error page: no chart at all.
        $html = self::app()->handle(['start_date' => '2026-02-30'], [])->body;
        self::assertStringNotContainsString('trend_toggle', $html);
    }

    public function testScriptDrawsTrendRedSolidAndProjectionRedDashed(): void
    {
        $js = $this->script();

        self::assertStringContainsString("if (key === 'trend' || key === 'projection')", $js, 'both use the reserved red');
        self::assertStringContainsString("cssVar('--trend'", $js);
        self::assertStringContainsString('borderDash: isProjection ? TREND_DASH : []', $js, 'projection dashed, trend solid');
        self::assertStringContainsString('points.unshift(lastTrendPoint)', $js, 'no gap between trend and projection');
        // Compare: dashed, in the term's own color.
        self::assertStringContainsString("label: 'Trend: ' + s.label", $js);
        self::assertSame(1, substr_count($js, 'borderDash: TREND_DASH'), 'only the compare trend dataset');

        // The switch: aria-pressed, remembered in storage (guarded) and in the URL.
        self::assertStringContainsString("button.setAttribute('aria-pressed'", $js);
        self::assertStringContainsString('window.localStorage.setItem(TREND_STORAGE_KEY', $js);
        self::assertStringContainsString('window.localStorage.getItem(TREND_STORAGE_KEY', $js);
        self::assertStringContainsString("url.searchParams.set('trend', '0')", $js);
        self::assertStringContainsString('window.history.replaceState', $js);
        self::assertStringContainsString('chart.setDatasetVisibility(i, trendOn)', $js, 'no reload');

        // Short ranges step by whole days, so a date is not shown twice.
        self::assertStringContainsString('x.stepSize = DAY_MS * Math.max(1, Math.ceil(spanDays / (x.maxTicks - 1)))', $js);
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
        self::assertSame(1, substr_count($css, '#c62828'), 'red is defined once, for the trend line');
        self::assertSame(2, substr_count($css, '--trend:'), 'the trend color is defined once for light and once for dark mode');
        self::assertSame(1, preg_match_all('/--trend:\s*#c62828;/', $css), 'the one red is the trend color');
        self::assertStringContainsString('@media (prefers-color-scheme: dark)', $css);
        self::assertMatchesRegularExpression('/font-variant-numeric:\s*tabular-nums/', $css);

        $js = $this->script();
        self::assertStringNotContainsString('innerHTML', $js, 'the script never writes raw HTML');
        self::assertStringContainsString("cssVar('--series-'", $js, 'term colors come from CSS variables');
        self::assertStringContainsString("cssVar('--trend'", $js, 'the trend color comes from CSS');
    }

    public function testOneDayRangeSendsOneChartPoint(): void
    {
        // 2026-11-09 is a reported day in the test data.
        $html = self::app()->handle(['start_date' => '2026-11-09', 'end_date' => '2026-11-09'], [])->body;

        $chart = FrontControllerTest::chartJson($html);
        self::assertSame('daily', $chart['type']);
        self::assertCount(1, $chart['series']);
        self::assertSame('2026-11-09', $chart['series'][0]['points'][0][0]);
        self::assertCount(1, $chart['series'][0]['points']);
    }

    public function testScriptDrawsOnePointChartsAndDatesWithYears(): void
    {
        $js = $this->script();

        // One-day range: min == max would leave the chart blank, so the axis is padded a day each side.
        self::assertStringContainsString('if (maxX > minX)', $js);
        self::assertStringContainsString('min: minX - DAY_MS, max: maxX + DAY_MS', $js);
        self::assertStringContainsString('isFinite(minX) && isFinite(maxX)', $js, 'no points: the axis is left alone');
        // A line with one point shows the point, on both charts.
        self::assertSame(2, substr_count($js, 'length === 1 ? 4 : 0'));
        // Tick labels carry the year when the range crosses New Year.
        self::assertStringContainsString('crossesYear ? fullDate : monthDay', $js);
    }

    public function testFaviconIsLocalAndUnusedImagesAreGone(): void
    {
        $public = dirname(__DIR__, 2) . '/src/Public';
        self::assertFileDoesNotExist($public . '/images/home.png');
        self::assertFileExists($public . '/favicon.svg');
        self::assertStringStartsWith('<svg xmlns="http://www.w3.org/2000/svg"', (string) file_get_contents($public . '/favicon.svg'));

        $html = self::app()->handle([], [])->body;
        self::assertMatchesRegularExpression('#<link rel="icon" href="favicon\.svg(\?r=\d+)?" type="image/svg\+xml">#', $html);
    }

    private function script(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/src/Public/js/usdebt.js');
    }
}
