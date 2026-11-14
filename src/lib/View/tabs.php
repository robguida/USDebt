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
 * @var string $cookie_path  The app's folder ("/ourusdebt/" or "/"): the saved-tab cookie's Path.
 */

use USDebt\Service\Html;
use USDebt\Service\ShareText;
?>
<div class="tab-bar">
<div class="segmented" role="tablist" aria-label="Show the results as">
    <button type="button" role="tab" id="tab_chart" aria-controls="graph_tab" aria-selected="true">Chart</button>
    <button type="button" role="tab" id="tab_stats" aria-controls="stats_tab" aria-selected="false">Stats</button>
    <button type="button" role="tab" id="tab_table" aria-controls="data_tab" aria-selected="false">Table</button>
</div>
<?php /* hidden until the page script runs: without it all three panels show, so there is no tab to remember */ ?>
<div class="tab-memory" id="tab_memory" hidden
     data-path="<?php echo Html::e($cookie_path); ?>"
     data-saved="<?php echo Html::e(ShareText::TAB_MEMORY_SAVED); ?>"
     data-removed="<?php echo Html::e(ShareText::TAB_MEMORY_REMOVED); ?>"
     data-failed="<?php echo Html::e(ShareText::TAB_MEMORY_FAILED); ?>">
    <label class="tab-memory-label"><input type="checkbox" id="tab_memory_box" aria-describedby="tab_memory_note"> <?php echo Html::e(ShareText::TAB_MEMORY_LABEL); ?></label>
    <p class="tab-memory-note" id="tab_memory_note"><?php echo Html::e(ShareText::TAB_MEMORY_NOTE); ?></p>
    <p class="share-status" id="tab_memory_status" role="status"></p>
</div>
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
