<?php

declare(strict_types=1);

namespace USDebt\Tests\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use USDebt\Model\DebtDataset;
use USDebt\Model\DebtEntry;
use USDebt\Service\CompareService;
use USDebt\Service\PresidentService;

final class CompareServiceTest extends TestCase
{
    /** A (2001-2002), B (2003-2004), A again (2005-2006): A has a gap between terms. */
    private const TERMS = [
        100 => ['pres' => 'Alpha', 'start' => '2001-01-01', 'end' => '2002-12-31', 'img' => 'a.jpg'],
        200 => ['pres' => 'Bravo', 'start' => '2003-01-01', 'end' => '2004-12-31', 'img' => 'b.jpg'],
        300 => ['pres' => 'Alpha', 'start' => '2005-01-01', 'end' => '2006-12-31', 'img' => 'a.jpg'],
    ];

    public function testUnknownAndDuplicateValuesAreIgnored(): void
    {
        $service = new CompareService(self::TERMS);
        $keys = $service->validateSelection(['300', '999', '100', '100', 'abc', '0100', '1e2', ' 200', ['200'], 300, null]);

        self::assertSame([100, 300], $keys, 'only real ids, once each, oldest term first');
    }

    public function testNonListInputGivesNoSelection(): void
    {
        $service = new CompareService(self::TERMS);
        self::assertSame([], $service->validateSelection('100'));
        self::assertSame([], $service->validateSelection(null));
    }

    public function testEveryRealPresidentCanBeSelectedAtOnce(): void
    {
        $all = array_keys(PresidentService::getPresidentConfig());
        $keys = (new CompareService())->validateSelection(array_map('strval', array_reverse($all)));

        self::assertSame($all, $keys, 'no cap: every term id is accepted');
    }

    public function testAllRealPresidentsProduceOneSeriesPerPresident(): void
    {
        $config = PresidentService::getPresidentConfig();
        $entries = [];
        $day = new DateTimeImmutable('1993-01-04');
        $amount = 4.0e12;
        while ($day <= new DateTimeImmutable('2026-11-09')) {
            $entries[] = new DebtEntry($day->format('Y-m-d'), $amount);
            $amount += 1.0e9;
            $day = $day->modify('+7 days');
        }
        $series = (new CompareService())->buildSeries(array_keys($config), new DebtDataset($entries, '2026-11-12'));

        $names = array_column($series, 'president');
        self::assertSame(array_values(array_unique(array_column($config, 'pres'))), $names);
        $trump = $series[array_search('Donald Trump', $names, true)];
        self::assertCount(1, $trump['term_starts'], 'two terms, one line, one later-term start');
        self::assertSame('Donald Trump (2017-2021, 2025-2029)', $trump['label']);
    }

    public function testBothTermsOfAPresidentWithAGapFormOneContinuousRun(): void
    {
        $dataset = new DebtDataset([
            // Alpha term 1: two months
            new DebtEntry('2001-01-02', 100.0e12),
            new DebtEntry('2001-01-15', 100.5e12),
            new DebtEntry('2001-02-01', 101.0e12),
            new DebtEntry('2002-12-30', 103.0e12),
            // Bravo's years: must not count toward Alpha
            new DebtEntry('2003-06-01', 150.0e12),
            // Alpha term 2
            new DebtEntry('2005-01-03', 200.0e12),
            new DebtEntry('2005-02-01', 202.0e12),
        ], '2026-11-12');

        $series = (new CompareService(self::TERMS))->buildSeries([100, 300], $dataset);

        self::assertCount(1, $series);
        $alpha = $series[0];
        self::assertSame('Alpha (2001-2002, 2005-2006)', $alpha['label']);
        // Term 1: months 1, 2, 3 (Jan 01, Feb 01, Dec 02). Term 2 continues at x = 4.
        self::assertSame([[1, 0.0], [2, 1.0], [3, 3.0], [4, 3.0], [5, 5.0]], $alpha['data']);
        self::assertSame([4], $alpha['term_starts']);
    }

    public function testTotalChangeRunsToTheLastDataDayOfEachTerm(): void
    {
        $dataset = new DebtDataset([
            new DebtEntry('2001-01-02', 100.0e12),
            new DebtEntry('2002-12-02', 102.0e12),  // first day of the last month: the last chart point of term 1
            new DebtEntry('2002-12-31', 102.75e12), // term 1's last data day
            new DebtEntry('2005-01-03', 200.0e12),
            new DebtEntry('2006-12-01', 201.0e12),
            new DebtEntry('2006-12-29', 201.5e12),  // term 2's last data day
            new DebtEntry('2007-01-02', 201.6e12),  // later data: both terms are over
        ], '2026-11-12');

        $alpha = (new CompareService(self::TERMS))->buildSeries([100, 300], $dataset)[0];

        // Chart: one point per month; term 2 starts from term 1's full change (2.75).
        self::assertSame([[1, 0.0], [2, 2.0], [3, 2.75], [4, 3.75]], $alpha['data']);
        // Stated total: (102.75 - 100) + (201.5 - 200), not the last chart point (3.75).
        self::assertSame(4.25e12, $alpha['change']);
        self::assertSame(48, $alpha['months'], 'two 2-year terms');
    }

    public function testSingleTermHasNoLaterTermStart(): void
    {
        $dataset = new DebtDataset([
            new DebtEntry('2005-01-03', 200.0e12),
            new DebtEntry('2005-02-01', 199.5e12),
        ], '2026-11-12');
        $series = (new CompareService(self::TERMS))->buildSeries([300], $dataset);

        self::assertSame('Alpha (2005-2006)', $series[0]['label']);
        self::assertSame([[1, 0.0], [2, -0.5]], $series[0]['data'], 'a falling debt gives negative change');
        self::assertSame([], $series[0]['term_starts']);
    }

    public function testTermsWithoutDataAreLeftOut(): void
    {
        $dataset = new DebtDataset([new DebtEntry('2003-02-03', 1.0e12)], '2026-11-12');
        $series = (new CompareService(self::TERMS))->buildSeries([100, 200, 300], $dataset);

        self::assertSame(['Bravo'], array_column($series, 'president'));
        self::assertSame([], (new CompareService(self::TERMS))->buildSeries([100], $dataset));
    }

    public function testMonthsInOfficeAreWholeTermMonths(): void
    {
        $terms = PresidentService::getPresidentConfig();
        $byName = [];
        foreach ($terms as $key => $t) {
            $byName[$t['pres']][] = $key;
        }
        $entries = [];
        for ($y = 1993; $y <= 2026; $y++) {
            foreach (['01-22', '06-15'] as $md) {
                $entries[] = new DebtEntry("$y-$md", 1.0e12 + $y * 1.0e10);
            }
        }
        $entries[] = new DebtEntry('2026-11-12', 40.0e12);
        $dataset = new DebtDataset($entries, '2026-11-13');
        $service = new CompareService($terms);
        $months = [];
        foreach ($byName as $name => $keys) {
            $months[$name] = $service->buildSeries($keys, $dataset)[0]['months'] ?? null;
        }
        self::assertSame(96, $months['Bill Clinton']);
        self::assertSame(96, $months['George W. Bush']);
        self::assertSame(96, $months['Barack Obama']);
        self::assertSame(48, $months['Joseph Biden']);
        // Trump: first term 48 + running term (Jan 20 2025 to Nov 13 2026 = 21).
        self::assertSame(48 + 21, $months['Donald Trump']);
        $current = array_key_last(array_filter($terms, fn ($t) => $t['pres'] === 'Donald Trump'));
        self::assertSame(21, $service->buildSeries([$current], $dataset)[0]['months']);
    }
}
