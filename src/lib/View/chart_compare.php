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
 * @var list<array{label: string, president: string, term_starts: list<int>, data: list<array{0: int, 1: float}>}> $series
 * @var array{type: string, series: list<array<string, mixed>>} $chart  From ChartData::compare().
 */

use USDebt\Service\Html;

$lines = [];
$notes = [];
foreach ($series as $s) {
    $lastPoint = $s['data'][count($s['data']) - 1];
    $lines[] = sprintf(
        '%s: %s after %d %s in office.',
        $s['label'],
        Html::compactMoney($lastPoint[1] * 1_000_000_000_000, true),
        $lastPoint[0],
        $lastPoint[0] === 1 ? 'month' : 'months'
    );
    foreach ($s['term_starts'] as $month) {
        $notes[] = sprintf(
            '%s: the second term starts at month %d. The years between the terms are left out.',
            $s['president'],
            $month
        );
    }
}
?>
<p class="lede">Each line shows how much the debt changed from a president's first day in office,
    month by month. The x axis is the month in office. The y axis is the change, in trillions of U.S. dollars.</p>
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
        <?php foreach ($notes as $note) { ?>
        <p class="term-note"><?php echo Html::e($note); ?></p>
        <?php } ?>
    </figcaption>
</figure>
<script type="application/json" id="chart-data"><?php echo Html::json($chart); ?></script>
