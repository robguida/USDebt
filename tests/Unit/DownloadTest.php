<?php

declare(strict_types=1);

namespace USDebt\Tests\Unit;

use PHPUnit\Framework\TestCase;
use USDebt\Controller\DownloadController;
use USDebt\Controller\FrontController;
use USDebt\Controller\USDebtController;
use USDebt\Exception\DataUnavailableException;
use USDebt\Model\DebtDataset;
use USDebt\Model\DebtEntry;
use USDebt\Model\Per;
use USDebt\Service\CompareService;
use USDebt\Service\DebtSource;
use USDebt\Service\PopulationService;
use USDebt\Service\ShareLinks;
use USDebt\Service\ShareText;
use USDebt\Service\TableCsv;

/** The Table tab's CSV download (download.php) and its icon. */
final class DownloadTest extends TestCase
{
    private const RANGE = ['start_date' => '2026-09-01', 'end_date' => '2026-10-31'];

    protected function tearDown(): void
    {
        putenv(ShareLinks::ENV_BASE_URL);
    }

    private static function controller(?DebtSource $source = null): DownloadController
    {
        return new DownloadController($source ?? FrontControllerTest::source(), new CompareService());
    }

    /** @return list<string> */
    private static function lines(string $csv): array
    {
        self::assertStringEndsWith("\r\n", $csv);
        return explode("\r\n", substr($csv, 0, -2));
    }

    /** @return list<string> the dates in the Table, in order */
    private static function tableDates(string $html): array
    {
        $table = substr($html, (int) strpos($html, 'data-table'));
        preg_match_all('#<td class="date">([^<]+)</td>#', $table, $m);
        return array_map(static fn (string $d): string => date('Y-m-d', strtotime($d)), $m[1]);
    }

    /** @return list<string> the Table's amounts as plain numbers ("$1,234.50" becomes "1234.50"), in order */
    private static function tableAmounts(string $html): array
    {
        $table = substr($html, (int) strpos($html, 'data-table'));
        preg_match_all('#<td class="currency">([^<]+)</td>#', $table, $m);
        self::assertNotEmpty($m[1]);
        return array_map(static fn (string $c): string => str_replace(['$', ','], '', $c), $m[1]);
    }

    private static function page(array $query): string
    {
        $app = new FrontController(new USDebtController(FrontControllerTest::source(), new CompareService(), ShareLinks::fromEnvironment()));
        return $app->handle($query, [])->body;
    }

    public function testTotalCsv(): void
    {
        $r = self::controller()->handle(self::RANGE);
        self::assertSame(200, $r->status);
        self::assertSame('text/csv; charset=utf-8', $r->headers['Content-Type']);
        self::assertSame('nosniff', $r->headers['X-Content-Type-Options']);
        self::assertSame('attachment; filename="our-us-debt_2026-09-01_to_2026-10-31.csv"', $r->headers['Content-Disposition']);
        self::assertSame((string) strlen($r->body), $r->headers['Content-Length']);
        self::assertStringNotContainsString("\n", str_replace("\r\n", '', $r->body), 'CRLF only');
        $lines = self::lines($r->body);
        self::assertSame('Date,"Total debt (USD)"', $lines[0]);
        self::assertMatchesRegularExpression('/^2026-10-\d\d,\d+\.\d{2}$/', $lines[1]);
        self::assertStringNotContainsString('$', $r->body);
    }

    public function testPerPersonAndHouseholdCsv(): void
    {
        $p = self::controller()->handle(self::RANGE + ['per' => 'person']);
        self::assertSame('Date,"Debt per person (USD)"', self::lines($p->body)[0]);
        self::assertStringEndsWith('_per-person.csv"', $p->headers['Content-Disposition']);
        self::assertMatchesRegularExpression('/^2026-10-\d\d,\d{4,6}\.\d{2}$/', self::lines($p->body)[1]);

        $h = self::controller()->handle(self::RANGE + ['per' => 'household']);
        self::assertSame('Date,"Debt per household (USD)"', self::lines($h->body)[0]);
        self::assertStringEndsWith('_per-household.csv"', $h->headers['Content-Disposition']);
        self::assertGreaterThan((float) explode(',', self::lines($p->body)[1])[1], (float) explode(',', self::lines($h->body)[1])[1]);
    }

    /** Same rows, same order, same numbers as the Table on the page. */
    public function testMatchesTheTable(): void
    {
        foreach (['', 'person', 'household'] as $per) {
            $query = self::RANGE + ($per === '' ? [] : ['per' => $per]);
            $lines = self::lines(self::controller()->handle($query)->body);
            array_shift($lines);
            $dates = array_map(static fn (string $l): string => explode(',', $l)[0], $lines);
            $html = self::page($query);
            self::assertSame(self::tableDates($html), $dates, "per=$per");
            $amounts = array_map(static fn (string $l): string => explode(',', $l)[1], $lines);
            self::assertSame(self::tableAmounts($html), $amounts, "per=$per amounts, to the cent");
            self::assertSame($dates, array_reverse(array_values(array_unique(array_reverse($dates)))), 'unique');
            self::assertGreaterThan($dates[1], $dates[0], 'newest first');
        }
    }

    public function testFullHistoryIsFine(): void
    {
        $r = self::controller()->handle(['start_date' => '1993-01-01', 'end_date' => '2026-11-09']);
        self::assertSame(200, $r->status);
        self::assertGreaterThan(1700, count(self::lines($r->body)));
    }

    public function testNoDatesUsesThePagesDefaultRange(): void
    {
        $r = self::controller(FrontControllerTest::sourceOf(new DebtEntry(date('Y-m-d'), 1.5e13)))->handle([]);
        self::assertSame(200, $r->status);
        self::assertMatchesRegularExpression('/filename="our-us-debt_\d{4}-\d\d-\d\d_to_\d{4}-\d\d-\d\d\.csv"/', $r->headers['Content-Disposition']);
    }

    public function testOnlyThePageQueryNamesAreRead(): void
    {
        $r = self::controller()->handle(self::RANGE + ['trend' => '0', 'r' => 'abc', 'x' => '"; evil']);
        self::assertSame(200, $r->status);
        self::assertStringNotContainsString('evil', $r->headers['Content-Disposition']);
    }

    public function testBadInputIs400(): void
    {
        foreach ([
            ['start_date' => '2026-02-30'],
            ['start_date' => '2026-10-31', 'end_date' => '2026-09-01'],
            ['start_date' => ['a'], 'end_date' => '2026-09-01x'],
            ['compare' => '1,2'],
            ['compare' => '1,2', 'start_date' => '2026-09-01', 'end_date' => '2026-10-31'],
        ] as $query) {
            $r = self::controller()->handle($query);
            self::assertSame(400, $r->status, json_encode($query));
            self::assertSame('text/plain; charset=utf-8', $r->headers['Content-Type']);
            self::assertArrayNotHasKey('Content-Disposition', $r->headers);
        }
        self::assertStringContainsString('no Compare', self::controller()->handle(['compare' => '1,2'])->body);
    }

    public function testRangeWithNoDataIs400(): void
    {
        $r = self::controller()->handle(['start_date' => '1980-01-01', 'end_date' => '1980-02-01']);
        self::assertSame(400, $r->status);
    }

    public function testNoTreasuryDataIs503(): void
    {
        $source = new class implements DebtSource {
            public function load(): DebtDataset
            {
                throw new DataUnavailableException();
            }
        };
        $r = self::controller($source)->handle(self::RANGE);
        self::assertSame(503, $r->status);
        self::assertSame(DataUnavailableException::MESSAGE . "\n", $r->body);
    }

    public function testUnexpectedFailureIs500WithTheGenericMessage(): void
    {
        $source = new class implements DebtSource {
            public function load(): DebtDataset
            {
                throw new \RuntimeException('secret detail');
            }
        };
        $r = self::controller($source)->handle(self::RANGE);
        self::assertSame(500, $r->status);
        self::assertStringNotContainsString('secret', $r->body);
        self::assertStringContainsString(FrontController::GENERIC_ERROR, $r->body);
    }

    public function testFormulaGuard(): void
    {
        foreach (['=1+1', '+1', '-1', '@x', "\tx", "\rx"] as $bad) {
            self::assertSame("'" . $bad, TableCsv::text($bad));
        }
        self::assertSame('2026-10-31', TableCsv::text('2026-10-31'));
        self::assertSame('', TableCsv::text(''));
        // A bad amount is written as guarded text, never as a bare "-5.00"; the other rows stay.
        $prev = ini_set('error_log', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null');
        try {
            $csv = TableCsv::build([new DebtEntry('2026-10-31', -5.0), new DebtEntry('2026-10-30', 10.0)], Per::Total);
        } finally {
            ini_set('error_log', (string) $prev);
        }
        self::assertSame(["Date,\"Total debt (USD)\"", "2026-10-31,'-5.00", '2026-10-30,10.00'], self::lines($csv));
    }

    public function testQuotingFollowsRfc4180(): void
    {
        $csv = TableCsv::build([new DebtEntry('2026-10-31', 1234567.891)], Per::Person);
        self::assertSame('2026-10-31,1234567.89', self::lines($csv)[1]);
    }

    public function testFileNames(): void
    {
        self::assertSame('our-us-debt_2020-01-01_to_2020-02-01.csv', TableCsv::fileName('2020-01-01', '2020-02-01', Per::Total));
        self::assertSame('our-us-debt_2020-01-01_to_2020-02-01_per-person.csv', TableCsv::fileName('2020-01-01', '2020-02-01', Per::Person));
        self::assertSame('our-us-debt_2020-01-01_to_2020-02-01_per-household.csv', TableCsv::fileName('2020-01-01', '2020-02-01', Per::Household));
    }

    /** @return array<string, array{0: string, 1: array<string, string>, 2: string}> */
    public static function icons(): array
    {
        $range = 'start_date=2026-09-01&end_date=2026-10-31';
        return [
            'default base, total' => ['', self::RANGE, "/ourusdebt/download.php?$range"],
            'default base, person' => ['', self::RANGE + ['per' => 'person'], "/ourusdebt/download.php?$range&per=person"],
            'subpath base, household' => ['https://bengusa.pro/ourusdebt', self::RANGE + ['per' => 'household'], "/ourusdebt/download.php?$range&per=household"],
            'root base (local)' => ['http://localhost:8000', self::RANGE, "/download.php?$range"],
            'other folder' => ['https://example.org/a/b/', self::RANGE + ['per' => 'person'], "/a/b/download.php?$range&per=person"],
        ];
    }

    /** @dataProvider icons */
    #[\PHPUnit\Framework\Attributes\DataProvider('icons')]
    public function testIconOnTheTableTab(string $base, array $query, string $href): void
    {
        $base === '' ? putenv(ShareLinks::ENV_BASE_URL) : putenv(ShareLinks::ENV_BASE_URL . '=' . $base);
        $html = self::page($query);
        self::assertSame('Download table as CSV', ShareText::TABLE_DOWNLOAD);
        self::assertStringContainsString(
            '<a class="icon-link" href="' . htmlspecialchars($href, ENT_QUOTES) . '" download>'
            . '<svg viewBox="0 0 24 24"',
            $html,
        );
        self::assertSame(1, preg_match('#<p class="table-download">(.*?)</p>#s', $html, $m));
        $box = $m[1];
        // Icon only: the SVG is hidden from screen readers; the one hidden text span is both the name and the tooltip.
        self::assertStringContainsString('aria-hidden="true" focusable="false"', $box);
        self::assertStringContainsString('<span class="icon-tip">Download table as CSV</span></a>', $box);
        self::assertSame(1, substr_count($box, 'Download table as CSV</span>'));
        self::assertStringNotContainsString('aria-label', $box);
        self::assertStringNotContainsString('data-tip', $box);
        // No visible text: nothing but whitespace is left once the svg and the tip span are removed.
        self::assertSame(1, preg_match('#<a class="icon-link"[^>]*>(.*?)</a>#s', $box, $link));
        $rest = preg_replace(['#<svg\b.*?</svg>#s', '#<span class="icon-tip">.*?</span>#s'], '', $link[1]);
        self::assertSame('', trim((string) $rest), 'the link shows only the icon');
        self::assertStringNotContainsString('title=', $box);
        // No helper line, and nothing CSP would block.
        self::assertStringNotContainsString('Opens in Excel', $html);
        self::assertStringNotContainsString('class="hint"', $box);
        self::assertStringNotContainsString('btn-quiet', $box);
        self::assertDoesNotMatchRegularExpression('#\sstyle\s*=|<script|\son[a-z]+\s*=#i', $box);
    }

    public function testTableDownloadWordsLiveInShareTextOnly(): void
    {
        $view = (string) file_get_contents(dirname(__DIR__, 2) . '/src/lib/View/tab_data.php');
        self::assertStringNotContainsString('Download table', $view);
        self::assertStringNotContainsString('Opens in Excel', $view);
        self::assertStringContainsString('ShareText::TABLE_DOWNLOAD', $view);
    }

    public function testNoIconOnCompare(): void
    {
        $app = new FrontController(new USDebtController(FrontControllerTest::source(), new CompareService(), ShareLinks::fromEnvironment()));
        $r = $app->handle(['compare' => '727506000,979966800'], []);
        self::assertSame(200, $r->status, 'the Compare view itself rendered');
        self::assertStringContainsString('View by presidential term', $r->body);
        self::assertStringNotContainsString('Download table', $r->body);
    }

    /** Without usable Census data, per=person gives the Total CSV, the same as the page. */
    public function testPerFallsBackToTotalWithoutCensusData(): void
    {
        $prev = ini_set('error_log', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null');
        try {
            $compare = new CompareService(null, PopulationService::fromYears([2003 => 0], [2003 => 1]));
            self::assertFalse($compare->population()->available());
            $csv = (new DownloadController(FrontControllerTest::source(), $compare))->handle(self::RANGE + ['per' => 'person']);
            $page = (new FrontController(new USDebtController(FrontControllerTest::source(), $compare, ShareLinks::fromEnvironment())))
                ->handle(self::RANGE + ['per' => 'person'], [])->body;
        } finally {
            ini_set('error_log', (string) $prev);
        }
        self::assertSame(200, $csv->status);
        self::assertSame('Date,"Total debt (USD)"', self::lines($csv->body)[0]);
        self::assertSame('attachment; filename="our-us-debt_2026-09-01_to_2026-10-31.csv"', $csv->headers['Content-Disposition']);
        $lines = self::lines($csv->body);
        array_shift($lines);
        self::assertSame(self::tableAmounts($page), array_map(static fn (string $l): string => explode(',', $l)[1], $lines));
        self::assertMatchesRegularExpression('/^2026-10-\d\d,\d{13,}\.\d{2}$/', $lines[0], 'Total-sized amounts');
    }

    /** The icon's size and its tooltip rules (WCAG 2.5.8 target size, 1.4.13 hover and focus). */
    public function testIconStyles(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Public/css/usdebt.css');
        self::assertSame(1, preg_match('/\.icon-link \{([^}]*)\}/', $css, $m));
        self::assertMatchesRegularExpression('/width:\s*44px;/', $m[1]);
        self::assertMatchesRegularExpression('/height:\s*44px;/', $m[1]);
        self::assertMatchesRegularExpression('/\.icon-link:focus-visible \.icon-tip \{[^}]*clip:\s*auto/', $css, 'the tip shows on keyboard focus');
        self::assertSame(1, preg_match('/@media \(hover: hover\) \{\s*\.icon-link:hover \.icon-tip \{/', $css), 'hover reveal only where hover exists');
        self::assertStringNotContainsString('content: attr(', $css);
        self::assertStringNotContainsString('data-tip', $css);
        self::assertSame(1, preg_match('/\.icon-tip \{([^}]*)\}/', $css, $t));
        self::assertStringNotContainsString('pointer-events', $css, 'the pointer may move onto the tip');
    }
}
