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
 * @var \USDebt\Model\Per $per  Total, per person or per household. For per person (or household)
 *      $first, $last and the chart are already per person, in dollars (chart unit "usd").
 */

use USDebt\Service\ChartData;
use USDebt\Service\Html;
use USDebt\Service\ShareText;

/* the trend lines and their projections, when the range has at least 2 days */
$by_key = [];
foreach ($chart['series'] as $s) {
    $by_key[$s['key']] = $s;
}

/* " Projection to Nov 9, 2027: about $45.12 trillion." from a projection series */
$projection_text = static function (?array $projection, string $name) use ($chart): string {
    if ($projection === null || $projection['points'] === []) {
        return '';
    }
    $end_point = $projection['points'][count($projection['points']) - 1];
    return sprintf(
        ' %s to %s: about %s.',
        $name,
        Html::shortDate($end_point[0]),
        Html::compactMoney(ChartData::toDollars($chart, (float) $end_point[1]))
    );
};

/* one sentence pair per line, so anyone who cannot see the chart gets the same numbers */
$trend_texts = [];
if (isset($by_key['trend'])) {
    $trend_texts[] = sprintf(
        'Trend: about %s per day (straight line).',
        Html::compactMoney(ChartData::toDollars($chart, (float) $by_key['trend']['slope_per_day']), true)
    ) . $projection_text($by_key['projection'] ?? null, 'Projection');
}
if (isset($by_key['curve'])) {
    $trend_texts[] = sprintf(
        'Growth curve: about %s per year.',
        Html::signedPercent((float) $by_key['curve']['growth_pct_per_year'])
    ) . $projection_text($by_key['curve_projection'] ?? null, 'Growth projection');
}
$last_text = count($trend_texts) - 1;

$change = $last->totalDebt - $first->totalDebt;
$summary = sprintf(
    '%s went from %s on %s to %s on %s, a change of %s.',
    ShareText::summarySubject($per),
    Html::money($first->totalDebt),
    Html::shortDate($first->effectiveDate),
    Html::money($last->totalDebt),
    Html::shortDate($last->effectiveDate),
    ($change > 0 ? '+' : '') . Html::money($change)
);
?>
<figure class="chart-figure">
    <div class="chart-box">
        <canvas id="debt_chart" role="img" aria-label="<?php echo Html::e($per->isTotal() ? 'Line chart of the total U.S. debt for the chosen dates' : 'Line chart of the ' . ShareText::subject($per) . ' for the chosen dates'); ?>" aria-describedby="chart_summary"></canvas>
    </div>
    <figcaption id="chart_summary" class="chart-summary num">
        <p><?php echo Html::e($summary); ?></p>
        <?php foreach ($trend_texts as $i => $text) { ?>
        <p class="trend-summary"><?php echo Html::e($text); ?><?php if ($i === $last_text) { ?><span class="trend-off-note"<?php echo $show_trend ? ' hidden' : ''; ?>> (<?php echo count($trend_texts) > 1 ? 'trend lines' : 'trend line'; ?> hidden)</span><?php } ?></p>
        <?php } ?>
    </figcaption>
</figure>
<script type="application/json" id="chart-data"><?php echo Html::json($chart); ?></script>
