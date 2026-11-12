<?php
/**
 * Created by PhpStorm.
 * User: robert
 * Date: 6/5/18
 * Time: 10:13 PM
 *
 * @var list<array{label: string, data: list<array{0: int, 1: float}>}> $series  Built by CompareService.
 */

use USDebt\Service\Html;

/* Flot wants {data, lines, label} per series; the numbers are already shaped in PHP. */
$flot_series = [];
foreach ($series as $s) {
    $flot_series[] = [
        'data' => $s['data'],
        'lines' => ['show' => true, 'fill' => true],
        'label' => $s['label'],
    ];
}
?>
<h2>Debt Compare</h2>
<p>In this view, each president is compared on how the debt changed since taking office.
    This is done by calculating the difference between the debt each month after taking office and the debt when
    sworn in. The data is aligned starting from the first day of office, and then each month thereafter.
    So each number on the x axis represents the month in office, and each number on the y axis represents the
    change in the debt, in trillions USD.
    When both terms of a president with a gap between terms are picked, the second term continues right after
    the first; the years in between are left out.
</p>
<div class="flot_containter"><div id="debt_graph" class="flot-placeholder"></div></div>
<script type="text/javascript">
    $(function() {
        $("<div id='tooltip'></div>").addClass('flot-tooltip').appendTo("body");
        var data = <?php echo Html::json($flot_series); ?>;
        var options = {
            legend: { position: "nw" },
            grid: {hoverable: true},
        };
        $.plot('#debt_graph', data, options);
        $("#debt_graph").bind("plothover", function (event, pos, item) {
            if (item) {
                var x = item.datapoint[0].toFixed(2),
                    y = item.datapoint[1].toFixed(2);
                if (0 > y) {
                    $('#tooltip').addClass('flot-tooltip-green');
                } else {
                    $('#tooltip').removeClass('flot-tooltip-green');
                }

                $("#tooltip").text(item.series.label + " - debt change for month " + parseInt(x) + ": $" + y + "T")
                    .css({top: item.pageY+5, left: item.pageX+5})
                    .fadeIn(200);
            } else {
                $("#tooltip").hide();
            }
        });
    })
</script>
