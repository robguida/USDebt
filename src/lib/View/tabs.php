<?php
/**
 * Created by PhpStorm.
 * User: robert
 * Date: 6/5/18
 * Time: 9:31 PM
 *
 * Chart / Stats / Table switch. Without JavaScript all three panels show,
 * one after another; the page script turns them into a switch.
 *
 * @var string $graph  Already-rendered HTML.
 * @var string $stats  Already-rendered HTML.
 * @var string $data   Already-rendered HTML.
 */
?>
<div class="segmented" role="tablist" aria-label="Show the results as">
    <button type="button" role="tab" id="tab_chart" aria-controls="graph_tab" aria-selected="true">Chart</button>
    <button type="button" role="tab" id="tab_stats" aria-controls="stats_tab" aria-selected="false">Stats</button>
    <button type="button" role="tab" id="tab_table" aria-controls="data_tab" aria-selected="false">Table</button>
</div>
<div class="panel" id="graph_tab" role="tabpanel" aria-labelledby="tab_chart">
    <?php echo $graph; ?>
</div>
<div class="panel" id="stats_tab" role="tabpanel" aria-labelledby="tab_stats">
    <?php echo $stats; ?>
</div>
<div class="panel" id="data_tab" role="tabpanel" aria-labelledby="tab_table">
    <?php echo $data; ?>
</div>
