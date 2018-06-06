<?php
/**
 * Created by PhpStorm.
 * User: robert
 * Date: 6/4/18
 * Time: 10:01 PM
 */

/* build the head links */
$js_files = array(
    "bin/jquery.js?r=" . filemtime('bin/jquery.js'),
    "bin/jquery-ui/jquery-ui.min.js?r=" . filemtime('bin/jquery-ui/jquery-ui.min.js'),
    "bin/Chart.bundle.min.js?r=" . filemtime('bin/Chart.bundle.min.js'),
);
$css_files = array(
    "bin/jquery-ui/jquery-ui.min.css?r=" . filemtime('bin/jquery-ui/jquery-ui.min.css'),
    "bin/jquery-ui/jquery-ui.structure.min.css?r=" . filemtime('bin/jquery-ui/jquery-ui.structure.min.css'),
    "bin/jquery-ui/jquery-ui.theme.min.css?r=" . filemtime('bin/jquery-ui/jquery-ui.theme.min.css'),
    "css/usdebt.css?r=" . filemtime('css/usdebt.css')
);
$head = '';
foreach ($js_files as $file) {
    $head .= "<script src=\"{$file}\" type=\"text/javascript\"></script>\n";
}
foreach ($css_files as $file) {
    $head .= "<link rel=\"stylesheet\" href=\"{$file}\" />\n";
}
?>
<html>
<head>
    <?php echo $head; ?>
</head>
<body>
<h1>Our U.S. Debt</h1>
<div id="about_usdebt" style="display: none;">
    <p>The data used for this site is freely accessible from
        <a href="https://www.treasurydirect.gov/" target="_blank">treasurydirect.gov</a>,
        who publishes the national debt daily. Because the data is updated daily, it is different than the
        <a href="http://www.usdebtclock.org/" target="_blank">National Debt Clock</a>. According to
        <a href="http://zfacts.com/node/245" target="_blank">zFacts</a>, the Debt Clock gets its data from
        treasurydirect.com on a daily basis, but then uses an algorithm to show the real-time, estimated, changes
        in the debt.
    </p>
</div>
<h4>Data is from <a href="treasurydirect.gov" target="_blank">treasurydirect.gov</a>
    <img src="images/about_usdebt.png" id="about_usdebt_btn" class="small_icon" /></h4>
<div id="main_content">
    <?php echo $main_content['search_form']; ?>
    <?php
    /* only display the tabs if we have data */
    if (!empty($graph_values_arr)) {
        ?>
        <div id="tabs">
            <ul>
                <li><a href="#graph_tab">Graph</a></li>
                <li><a href="#stats_tab">Stats</a></li>
                <li><a href="#data_tab">Data</a></li>
            </ul>
            <div id="graph_tab">
                <div class="chart-container"><canvas id="debt_graph"></canvas></div>
            </div>
            <div id="data_tab">
                <table class="main_content">
                    <thead><tr><th>Date</th><th>Total Debt</th></tr></thead>
                    <tbody><?php echo $main_content; ?></tbody>
                    <tfoot></tfoot>
                </table>
            </div>
            <div id="stats_tab">
                <h3>Starting debt amount</h3>
                <p>$<?php echo number_format($first_debt->totalDebt, 2); ?></p>
                <h3>Last debt amount of date range</h3>
                <p>$<?php echo number_format($last_debt->totalDebt, 2); ?></p>
                <h3>During the given time span, the debt increased</h3>
                <p><?php echo $delta_str; ?></p>
                <h3>This works out to be</h3>
                <p><?php echo $average_per_day_str; ?> per day</p>
                <h3>Days in the date range</h3>
                <p><?php echo $working_days; ?> days<sup>*</sup> / <?php echo $days; ?> total days in range</p>
                <h3>How many days during the given time span, was the debt greater than the starting amount</h3>
                <p><?php echo $days_gt_start_debt_count; ?> days<sup>*</sup></p>
                <h3>Percentage of days debt was greater than the start date range</h3>
                <p><?php echo $days_gt_start_debt_percentage; ?>%</p>
                <h3>How many days during the given time span, was the debt lower than the starting amount</h3>
                <p><?php echo $days_lt_start_debt_count; ?> days<sup>*</sup></p>
                <h3>Percentage of days debt was lower than the start date range</h3>
                <p><?php echo $days_lt_start_debt_percentage; ?>%</p>
                <p class="stats_footer"><sup>*</sup> Includes only days when data is reported.</p>
            </div>
        </div>
    <?php } ?>
</div>
</body>
<footer>

</footer>
<script type="text/javascript">
    $(document).ready(function() {
        console.log('jquery loaded');
        $('#about_usdebt').dialog({
            autoOpen: false,
            modal: true,
            closeOnEscape: true
        });
        $('#about_usdebt_btn').on('click', function() {
            $('#about_usdebt').dialog('open');
        });
        $('#tabs').tabs();
        /* bindings */
        $('#start_date').datepicker({
            showOtherMonths: true,
            selectOtherMonths: true,
            changeMonth: true,
            changeYear: true,
            dateFormat: 'yy-mm-dd',
            minDate: new Date(1993, 0, 4),
        });
        $('#end_date').datepicker({
            showOtherMonths: true,
            selectOtherMonths: true,
            changeMonth: true,
            changeYear: true,
            dateFormat: 'yy-mm-dd',
            minDate: new Date(1993, 1, 4),
        });
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
</html>
