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
 * @var bool $show_trend  Trend line on (false when the URL says trend=0).
 */

use USDebt\Service\Html;

/* the trend and projection series, when the range has at least 2 days */
$trend = null;
$projection = null;
foreach ($chart['series'] as $s) {
    if ($s['key'] === 'trend') {
        $trend = $s;
    } elseif ($s['key'] === 'projection') {
        $projection = $s;
    }
}
$trend_text = '';
if ($trend !== null) {
    $trend_text = sprintf(
        'Trend: about %s per day (straight line).',
        Html::compactMoney($trend['slope_per_day'] * 1_000_000_000_000, true)
    );
    if ($projection !== null && $projection['points'] !== []) {
        $end_point = $projection['points'][count($projection['points']) - 1];
        $trend_text .= sprintf(
            ' Projection to %s: about %s.',
            Html::shortDate($end_point[0]),
            Html::compactMoney($end_point[1] * 1_000_000_000_000)
        );
    }
}

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
    <figcaption id="chart_summary" class="chart-summary num">
        <p><?php echo Html::e($summary); ?></p>
        <?php if ($trend_text !== '') { ?>
        <p class="trend-summary"><?php echo Html::e($trend_text); ?><span class="trend-off-note"<?php echo $show_trend ? ' hidden' : ''; ?>> (trend line hidden)</span></p>
        <?php } ?>
    </figcaption>
</figure>
<script type="application/json" id="chart-data"><?php echo Html::json($chart); ?></script>
