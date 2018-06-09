<?php
/**
 * Created by PhpStorm.
 * User: robert
 * Date: 6/5/18
 * Time: 10:13 PM
 */
?>
<h2>Debt Compare</h2>
<p>In this view, each president is compared on how they have improved the debt since taking office.
    This is done by calculating the difference between the debt each month after taking office and the debt when
    sworn in. The data is aligned starting from the first day of office, and then each month thereafter.
    So each number on the x axis represents the month in office, and each number on the y axis represents the
    change in the debt, in trillions USD.
</p>
<div class="flot_containter"><div id="debt_graph" class="flot-placeholder"></div></div>
<script type="text/javascript">
    $(function() {
        $("<div id='tooltip'></div>").addClass('flot-tooltip').appendTo("body");

        var d1 = [[1, 0], [2, 15], [3, 10], [4, 5], [5, 10], [6, 8]];
        var d2 = [[1, 0], [2, 5], [3, 8], [4, 15], [5, 20], [6, 18]];
        var d3 = [[1, 0], [2, 10], [3, 20], [4, 30], [5, 40], [6, 50]];
        var d4 = [[1, 0], [2, -5], [3, -8], [4, 2], [5, 10], [6, -1]];
        var data = [
            {data:d1, lines:{show:true, fill:true}, label: 'Bill Clinton'},
            {data:d2, lines:{show:true, fill:true}, label: 'George Bush'},
            {data:d3, lines:{show:true, fill:true}, label: 'Barack Obama'},
            {data:d4, lines:{show:true, fill:true}, label: 'Donald Trump'}
        ];
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

                $("#tooltip").html(item.series.label + " - debt change for month " + parseInt(x) + ": $" + y + "T")
                    .css({top: item.pageY+5, left: item.pageX+5})
                    .fadeIn(200);
            } else {
                $("#tooltip").hide();
            }
        });
    })
</script>
