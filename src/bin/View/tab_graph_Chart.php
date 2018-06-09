<?php
/**
 * Created by PhpStorm.
 * User: robert
 * Date: 6/5/18
 * Time: 10:13 PMload
 */
$graph_labels = '[]';
$graph_labels_arr = array();
$graph_values = '[]';
$graph_values_arr = array();

foreach ($datas as $d) {
    /* add a record to the data table */
    $dateDt = new DateTime($d->effectiveDate);
    /* use the date for the labels on the graph */
    array_unshift($graph_labels_arr, $dateDt->format('n/j/y'));
    /* use the debt for the points on the graph */
    array_unshift($graph_values_arr, round($d->totalDebt / 1000000000000, 10));
}

/* set up graph data */
if (!empty($graph_labels_arr)) {
    $graph_labels = '["' . implode('", "', $graph_labels_arr) . '"]';
}
if (!empty($graph_values_arr)) {
    $graph_values = '["' . implode('", "', $graph_values_arr) . '"]';
}
?>
<div class="chart-container"><canvas id="debt_graph"></canvas></div>
<script type="text/javascript">
    $(document).ready(function() {
        var ctx = $("#debt_graph");
        var debt_graph = new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?php echo $graph_labels; ?>,
                datasets: [{
                    label: 'Our U.S. Debt (trillions)',
                    data: <?php echo $graph_values; ?>,
                    backgroundColor: 'rgba(255, 99, 132, 0.2)',
                    borderColor: 'rgba(255, 99, 132, 0.2)',
                    borderWidth: 1
                }]
            },
            options: {
                maintainAspectRatio: false,
            },
        });
    })
</script>
