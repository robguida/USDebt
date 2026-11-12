<?php

declare(strict_types=1);

namespace USDebt\Tests\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use USDebt\Service\PresidentService;

final class PresidentServiceTest extends TestCase
{
    public function testTermsAreInOrderAndNeverOverlap(): void
    {
        $previousEnd = null;
        foreach (PresidentService::getPresidentConfig() as $key => $term) {
            $start = new DateTimeImmutable($term['start']);
            $end = new DateTimeImmutable($term['end']);
            self::assertLessThan($end, $start, "term {$key} ends after it starts");
            if ($previousEnd !== null) {
                self::assertGreaterThan($previousEnd, $start, "term {$key} overlaps the term before it");
            }
            $previousEnd = $end;
        }
    }

    public function testEveryTermHasItsImage(): void
    {
        $images = dirname(__DIR__, 2) . '/src/Public/images/';
        foreach (PresidentService::getPresidentConfig() as $term) {
            self::assertFileExists($images . $term['img']);
        }
    }

    public function testLookupByKey(): void
    {
        self::assertSame('Bill Clinton', PresidentService::getPresident(727506000)['pres'] ?? null);
        self::assertSame('Bill Clinton', PresidentService::getPresident('727506000')['pres'] ?? null);
        self::assertNull(PresidentService::getPresident(1));
    }
}
