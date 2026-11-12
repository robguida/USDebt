<?php
/**
 * Created by PhpStorm.
 * User: robert
 * Date: 6/5/18
 * Time: 6:19 AM
 */

declare(strict_types=1);

namespace USDebt\Service;

/**
 * Fixed list of presidential terms. One entry per term; a president with two
 * terms that are not back to back (e.g. Donald Trump) has two entries with the
 * same 'pres' name. The array key is the term's id used by the Compare form.
 *
 * Term dates are inclusive. A term ends the day before the next term starts,
 * so no day belongs to two terms.
 */
class PresidentService
{
    /**
     * @param int|string $key
     * @return array{pres: string, start: string, end: string, img: string, grfcolor: string}|null
     */
    public static function getPresident(int|string $key): ?array
    {
        $pres = self::getPresidentConfig();
        return $pres[$key] ?? null;
    }

    /**
     * @return array<int, array{pres: string, start: string, end: string, img: string, grfcolor: string}>
     */
    public static function getPresidentConfig(): array
    {
        return [
            727506000 => [
                'pres' => 'Bill Clinton',
                'start' => 'January 20, 1993',
                'end' => 'January 19, 2001',
                'img' => 'Bill_Clinton.jpg',
                'grfcolor' => 'rgba(54, 162, 235, 0.2)',
            ],
            979966800 => [
                'pres' => 'George W. Bush',
                'start' => 'January 20, 2001',
                'end' => 'January 19, 2009',
                'img' => 'George-W-Bush.jpg',
                'grfcolor' => 'rgba(255, 99, 132, 0.2)',
            ],
            1232427600 => [
                'pres' => 'Barack Obama',
                'start' => 'January 20, 2009',
                'end' => 'January 19, 2017',
                'img' => 'Barack_Obama.jpg',
                'grfcolor' => 'rgba(54, 162, 235, 0.2)',
            ],
            1484888400 => [
                'pres' => 'Donald Trump',
                'start' => 'January 20, 2017',
                'end' => 'January 19, 2021',
                'img' => 'Donald_Trump.jpg',
                'grfcolor' => 'rgba(255, 99, 132, 0.2)',
            ],
            1611100800 => [
                'pres' => 'Joseph Biden',
                'start' => 'January 20, 2021',
                'end' => 'January 19, 2025',
                'img' => 'Joe_Biden.jpg',
                'grfcolor' => 'rgba(247, 10, 0, 0.2)',
            ],
            1863561600 => [
                'pres' => 'Donald Trump',
                'start' => 'January 20, 2025',
                'end' => 'January 19, 2029',
                'img' => 'Donald_Trump.jpg',
                'grfcolor' => 'rgba(255, 99, 132, 0.2)',
            ],
        ];
    }
}
