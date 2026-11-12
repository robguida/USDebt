<?php
/**
 * Created by PhpStorm.
 * User: robert
 * Date: 6/5/18
 * Time: 9:43 PM
 *
 * @var array<string, int|float|null>|null $stats  From StatsCalculator::summarize(); null when there is no data.
 */

use USDebt\Service\Html;

if ($stats === null) {
    echo '<p class="message">There is no data for this date range.</p>';
    return;
}

$delta = (float) $stats['delta'];
$rows = [
    ['Starting debt amount', Html::money((float) $stats['first_debt'])],
    ['Last debt amount of date range', Html::money((float) $stats['last_debt'])],
    ['Change during the date range', ($delta > 0 ? '+' : '') . Html::money($delta)],
    ['Average change per day', ($stats['average_per_day'] > 0 ? '+' : '') . Html::money((float) $stats['average_per_day'])],
    ['Days with reported data*', number_format((int) $stats['working_days']) . ' of ' . number_format((int) $stats['total_days']) . ' calendar days'],
    ['Days the debt was above the start*', number_format((int) $stats['days_above_start']) . ' (' . $stats['pct_above_start'] . '%)'],
    ['Days the debt was below the start*', number_format((int) $stats['days_below_start']) . ' (' . $stats['pct_below_start'] . '%)'],
];
if ($stats['trend_per_day'] !== null) {
    // Slope of the straight trend line on the chart, in dollars per calendar day.
    $rows[] = ['Trend (straight line)', 'About ' . Html::compactMoney((float) $stats['trend_per_day'], true) . ' per day'];
}
if (($stats['growth_pct_per_year'] ?? null) !== null) {
    // Yearly growth of the growth curve on the chart, in percent.
    $rows[] = ['Growth curve', 'About ' . Html::signedPercent((float) $stats['growth_pct_per_year']) . ' per year'];
}
?>
<dl class="stats-grid">
    <?php foreach ($rows as [$label, $value]) { ?>
    <div class="stats-item">
        <dt><?php echo Html::e($label); ?></dt>
        <dd class="num"><?php echo Html::e($value); ?></dd>
    </div>
    <?php } ?>
</dl>
<p class="footnote">* Counts only days when the Treasury reported data (business days).</p>
