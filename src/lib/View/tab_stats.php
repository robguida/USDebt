<?php
/**
 * Created by PhpStorm.
 * User: robert
 * Date: 6/5/18
 * Time: 9:43 PM
 *
 * @var array<string, int|float>|null $stats  From StatsCalculator::summarize(); null when there is no data.
 */

use USDebt\Service\Html;

if ($stats === null) {
    echo '<p class="message">There is no data for this date range.</p>';
    return;
}
?>
<h3>Starting debt amount</h3>
<p><?php echo Html::e(Html::money($stats['first_debt'])); ?></p>
<h3>Last debt amount of date range</h3>
<p><?php echo Html::e(Html::money($stats['last_debt'])); ?></p>
<h3>During the given time span, the debt increased</h3>
<p><?php echo Html::e(Html::money($stats['delta'])); ?></p>
<h3>This works out to be</h3>
<p><?php echo Html::e(Html::money($stats['average_per_day'])); ?> per day</p>
<h3>Days in the date range</h3>
<p><?php echo Html::e($stats['working_days']); ?> days<sup>*</sup> / <?php echo Html::e($stats['total_days']); ?> total days in range</p>
<h3>How many days during the given time span, was the debt greater than the starting amount</h3>
<p><?php echo Html::e($stats['days_above_start']); ?> days<sup>*</sup></p>
<h3>Percentage of days debt was greater than the start date range</h3>
<p><?php echo Html::e($stats['pct_above_start']); ?>%</p>
<h3>How many days during the given time span, was the debt lower than the starting amount</h3>
<p><?php echo Html::e($stats['days_below_start']); ?> days<sup>*</sup></p>
<h3>Percentage of days debt was lower than the start date range</h3>
<p><?php echo Html::e($stats['pct_below_start']); ?>%</p>
<p class="stats_footer"><sup>*</sup> Includes only days when data is reported.</p>
