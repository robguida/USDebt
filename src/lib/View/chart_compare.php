<?php
/**
 * Created by PhpStorm.
 * User: robert
 * Date: 6/5/18
 * Time: 10:13 PM
 *
 * Compare chart: change in debt since each president took office, by month.
 * The page script reads the JSON data block and draws it.
 *
 * @var list<array{label: string, president: string, term_starts: list<int>, data: list<array{0: int, 1: float}>, change: float, months: int}> $series  From CompareService::buildSeries().
 * @var array{type: string, series: list<array<string, mixed>>} $chart  From ChartData::compare(), same order as $series.
 * @var bool $show_trend  Trend lines on (false when the URL says trend=0).
 * @var \USDebt\Model\Per $per  Total, per person or per household (change per person, in dollars).
 */

use USDebt\Service\ChartData;
use USDebt\Service\Html;
use USDebt\Service\ShareText;

$lines = [];
$notes = [];
$has_trend = false;
foreach ($series as $i => $s) {
    // The exact change to the last data day (the same number as the share card),
    // not the last monthly chart point.
    $line = sprintf(
        '%s: %s after %d %s in office.',
        $s['label'],
        Html::compactMoney($s['change'], true),
        $s['months'],
        $s['months'] === 1 ? 'month' : 'months'
    );
    $trend = $chart['series'][$i]['trend'] ?? null;
    if ($trend !== null) {
        $has_trend = true;
        $line .= sprintf(
            ' Trend: about %s per month (straight line).',
            Html::compactMoney(ChartData::toDollars($chart, (float) $trend['slope_per_month']), true)
        );
    }
    $lines[] = $line;
    foreach ($s['term_starts'] as $month) {
        $notes[] = sprintf(
            '%s: the second term starts at month %d. The years between the terms are left out.',
            $s['president'],
            $month
        );
    }
}
?>
<p class="lede">Each line shows how much the <?php echo Html::e(lcfirst(ShareText::debtWord($per))); ?> changed from a president's first day in office,
    month by month. The x axis is the month in office. <?php echo Html::e(ShareText::compareAxis($per)); ?></p>
<p class="caption">Congress sets spending and taxes. Presidential terms are shown for reference only.</p>
<figure class="chart-figure">
    <div class="chart-box chart-box-tall">
        <canvas id="debt_chart" role="img" aria-label="Line chart comparing the change in debt during each picked presidential term" aria-describedby="chart_summary"></canvas>
    </div>
    <figcaption id="chart_summary" class="chart-summary num">
        <ul>
            <?php foreach ($lines as $line) { ?>
            <li><?php echo Html::e($line); ?></li>
            <?php } ?>
        </ul>
        <?php if ($has_trend) { ?>
        <p class="trend-summary">Each dashed line is that term's trend (straight line), in the same color.<span class="trend-off-note"<?php echo $show_trend ? ' hidden' : ''; ?>> (trend lines hidden)</span></p>
        <?php } ?>
        <?php foreach ($notes as $note) { ?>
        <p class="term-note"><?php echo Html::e($note); ?></p>
        <?php } ?>
    </figcaption>
</figure>
<script type="application/json" id="chart-data"><?php echo Html::json($chart); ?></script>
