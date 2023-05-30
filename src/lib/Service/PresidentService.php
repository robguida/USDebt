<?php
/**
 * Created by PhpStorm.
 * User: robert
 * Date: 6/5/18
 * Time: 6:19 AM
 */

namespace USDebt\Service;


class PresidentService
{
    /**
     * @param $timestamp
     * @return mixed|null
     */
    public static function getPresident($timestamp)
    {
        $output = null;
        $ps = new PresidentService();
        $pres = $ps->getPresidentConfig();
        if (array_key_exists($timestamp, $pres)) {
            $output = $pres[$timestamp];
        }
        return $output;
    }

    /**
     * @return array
     */
    public static function getPresidentConfig()
    {
        return [
            727506000 => [
                'pres' => 'Bill Clinton',
                'start' => 'January 20, 1993',
                'end' => 'January 20, 2001',
                'img' => 'Bill_Clinton.jpg',
                'grfcolor' => 'rgba(54, 162, 235, 0.2)',
            ],
            979966800 => [
                'pres' => 'George W. Bush',
                'start' => 'January 20, 2001',
                'end' => 'January 20, 2009',
                'img' => 'George-W-Bush.jpg',
                'grfcolor' => 'rgba(255, 99, 132, 0.2)',
            ],
            1232427600 => [
                'pres' => 'Barack Obama',
                'start' => 'January 20, 2009',
                'end' => 'January 20, 2017',
                'img' => 'Barack_Obama.jpg',
                'grfcolor' => 'rgba(54, 162, 235, 0.2)',
            ],
            1484888400 => [
                'pres' => 'Donald Trump',
                'start' => 'January 20, 2017',
                'end' => '',
                'img' => 'Donald_Trump.jpg',
                'grfcolor' => 'rgba(255, 99, 132, 0.2)',
            ],
            1611100800 => [
                'pres' => 'Joseph Biden',
                'start' => 'January 20, 2021',
                'end' => '',
                'img' => 'Donald_Trump.jpg',
                'grfcolor' => 'rgba(247, 10, 0, 0.2)',
            ],
        ];
    }
}
