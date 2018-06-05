<?php
/**
 * Created by PhpStorm.
 * User: robert
 * Date: 6/4/18
 * Time: 10:01 PM
 */

$pres_nav = '';
foreach ($pres_array as $pres) {
    $pres_start = new DateTime($pres['start']);
    $pres_end = new DateTime($pres['end']);
    $pres_nav .= "<a href=\"?start_date={$pres_start->format('Y-m-d')}&end_date={$pres_end->format('Y-m-d')}\">" .
        "<img src=\"images/{$pres['img']}\" title=\"{$pres['pres']}\"></a>";
}

/* build the head links */
$js_files = array(
    "bin/jquery.js?r=" . filemtime('bin/jquery.js'),
    "bin/jquery-ui/jquery-ui.min.js?r=" . filemtime('bin/jquery-ui/jquery-ui.min.js'),
    "bin/Chart.bundle.min.js?r=" . filemtime('bin/Chart.bundle.min.js'),
);
$css_files = array(
    "bin/jquery-ui/jquery-ui.min.css?r=" . filemtime('bin/jquery-ui/jquery-ui.min.css'),
    "bin/jquery-ui/jquery-ui.structure.min.css?r=" . filemtime('bin/jquery-ui/jquery-ui.structure.min.css'),
    "bin/jquery-ui/jquery-ui.theme.min.css?r=" . filemtime('bin/jquery-ui/jquery-ui.theme.min.css')
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
    <style>
        body {
            padding: 16px;
        }
        div.pres_nav {
            display: inline;
        }
        div.pres_nav img{
            width: 50px;
            height: 60px;
            border: 1px solid black;
            margin: 0px 10px 0px 0px;
        }
        div.pres_nav img.home{
            width: 50px;
            height: 60px;
            border: 0px;
            margin: 0px 10px 0px 0px;
        }
        form.search {
            display: inline;
        }
        .chart-container {
            position: relative;
            margin: auto;
            height: 60vh;
            width: 80vw;
        }
        canvas {
            /*border: 1px dotted red;*/
        }
        table.main_content {
            width: 500px;s
            /*border: 1px solid silver;*/
        }
        table.main_content thead th {
            border-bottom: 1px solid silver;
        }
        table.main_content tbody td {
            padding: 3px 10px;
        }
        table.main_content tbody td.date {
            text-align: center;
        }
        table.main_content tbody td.currency {
            text-align: right;
        }
        table.main_content tr:nth-child(even){background-color: #f2f2f2}
        h4 {
            margin-top: -20px;
        }
        h3 {
            margin-top: 20px;
            color: darkred;
        }
        p.stats_footer {
            border-top: 1px solid silver;
            font-size: x-small;
        }
    </style>
</head>
<body>
<h1>Our U.S. Debt</h1>
<div id="about_usdebt" style="display: none;">
    <p>The data is pulled from <a href="https://www.treasurydirect.gov/" target="_blank">treasurydirect.gov</a>,
        which publishes the data daily. Unfortunately, since May 31, 2018 this site is no longer publishing
        current data.
    </p>
</div>
<h4>Data is from treasurydirect.gov</h4>
<table>
    <tr>
        <td>
            <h2>Select a date range, or a President</h2>
            <form id="search" class="search">
                <label for="start_date">Start Date:</label>
                <input type="text" id="start_date" name="start_date" value="<?php echo $start_date; ?>" />
                <label for="end_date">End Date:</label>
                <input type="text" id="end_date" name="end_date" value="<?php echo $end_date; ?>" />
                <input type="submit" id="submit" name="submit" value="Fetch" />
            </form>
        </td>
        <td>&nbsp;</td>
        <td><div class="pres_nav">
                <?php echo $pres_nav; ?>
                <a href="index.php">
                    <img class="home" src="images/home.png" />
                </a>
            </div>
        </td>
    </tr>
</table>
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
            <div class="chart-container"><canvas id="debt_graph"></canvas></div></div>
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
<?php } else {
    echo $main_content;
} ?>
</body>
<footer>

</footer>
<script type="text/javascript">
    $(document).ready(function() {
        console.log('jquery loaded');
        $('about_usdebt').dialog(
            autoOpen: false,
            modal: true
        );
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
