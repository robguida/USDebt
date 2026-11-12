<?php
/**
 * Created by PhpStorm.
 * User: robert
 * Date: 6/5/18
 * Time: 10:13 PM
 *
 * Date-range chart. The page script reads the JSON data block and draws it.
 *
 * @var array{type: string, series: list<array{key: string, label: string, points: list<array{0: string, 1: float}>}>} $chart
 * @var \USDebt\Model\DebtEntry $first  Oldest day in the range.
 * @var \USDebt\Model\DebtEntry $last   Newest day in the range.
 */

use USDebt\Service\Html;

$change = $last->totalDebt - $first->totalDebt;
$summary = sprintf(
    'Total debt went from %s on %s to %s on %s, a change of %s.',
    Html::money($first->totalDebt),
    Html::shortDate($first->effectiveDate),
    Html::money($last->totalDebt),
    Html::shortDate($last->effectiveDate),
    ($change > 0 ? '+' : '') . Html::money($change)
);
?>
<figure class="chart-figure">
    <div class="chart-box">
        <canvas id="debt_chart" role="img" aria-label="Line chart of the total U.S. debt for the chosen dates" aria-describedby="chart_summary"></canvas>
    </div>
    <figcaption id="chart_summary" class="chart-summary num"><?php echo Html::e($summary); ?></figcaption>
</figure>
<script type="application/json" id="chart-data"><?php echo Html::json($chart); ?></script>
