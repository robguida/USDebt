<?php
/**
 * Created by PhpStorm.
 * User: robert
 * Date: 6/5/18
 * Time: 10:13 PM
 *
 * @var array{labels: list<string>, values: list<float>} $chart  Oldest first; values in trillions.
 */

use USDebt\Service\Html;

?>
<div class="chart-container"><canvas id="debt_graph"></canvas></div>
<script type="text/javascript">
    $(function() {
        var chartData = <?php echo Html::json($chart); ?>;
        var ctx = $("#debt_graph");
        var debt_graph = new Chart(ctx, {
            type: 'line',
            data: {
                labels: chartData.labels,
                datasets: [{
                    label: 'Our U.S. Debt (trillions)',
                    data: chartData.values,
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
