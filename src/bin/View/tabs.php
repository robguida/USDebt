<?php
/**
 * Created by PhpStorm.
 * User: robert
 * Date: 6/5/18
 * Time: 9:31 PM
 */
?>
<div id="tabs">
    <ul>
        <li><a href="#graph_tab">Graph</a></li>
        <li><a href="#stats_tab">Stats</a></li>
        <li><a href="#data_tab">Data</a></li>
    </ul>
    <div id="graph_tab">
        <?php echo $graph; ?>
    </div>
    <div id="data_tab">
        <?php echo $data; ?>
    </div>
    <div id="stats_tab">

    </div>
</div>
<script type="text/javascript">
    $('#tabs').tabs();
</script>