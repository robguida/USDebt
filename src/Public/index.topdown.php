<?php
$starttime = microtime(true);
$version = round(filemtime(__FILE__)/100000000, 3);

/**
 * This is a self contained file for view our U.S. Debt from the U.S. Department of Treasury
 *
 * @author: Robert Guida
 * @date: June 7th, 2017
 */
$debug = false;
if (isset($_GET['debug'])) {
    $debug = ('547875' == $_GET['debug']);
}

if ("usdebt.robguida.com" == $_SERVER['SERVER_NAME'] && !$debug) {
    error_reporting(0);
} else {
    error_reporting(E_ALL & ~E_NOTICE);
}

/* presidential array so we can link the candidate to the debt
    The key for each president is the timestamp of the start values */
$pres_array = array (
    727506000 => array(
        'pres' => 'Bill Clinton',
        'start' => 'January 20, 1993',
        'end' => 'January 20, 2001',
        'img' => 'Bill_Clinton.jpg',
        'grfcolor' => 'rgba(54, 162, 235, 0.2)',
    ),
    979966800 => array(
        'pres' => 'George W. Bush',
        'start' => 'January 20, 2001',
        'end' => 'January 20, 2009',
        'img' => 'George-W-Bush.jpg',
        'grfcolor' => 'rgba(255, 99, 132, 0.2)',
    ),
    1232427600 => array(
        'pres' => 'Barack Obama',
        'start' => 'January 20, 2009',
        'end' => 'January 20, 2017',
        'img' => 'Barack_Obama.jpg',
        'grfcolor' => 'rgba(54, 162, 235, 0.2)',
    ),
    1484888400 => array(
        'pres' => 'Donald Trump',
        'start' => 'January 20, 2017',
        'end' => '',
        'img' => 'Donald_Trump.jpg',
        'grfcolor' => 'rgba(255, 99, 132, 0.2)',
    ),
);
$pres_nav = '';
foreach ($pres_array as $pres) {
    $pres_start = new DateTime($pres['start']);
    $pres_end = new DateTime($pres['end']);
    $pres_nav .= "<a href=\"?start_date={$pres_start->format('Y-m-d')}&end_date={$pres_end->format('Y-m-d')}\">" .
        "<img src=\"images/{$pres['img']}\" title=\"{$pres['pres']}\"></a>";
}

/* get the chart data */
$startDt = new DateTime();
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : $startDt->modify('-1 month')->format('Y-m-d');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
$dot_url = "https://www.treasurydirect.gov/NP_WS/debt/search?startdate={$start_date}&enddate={$end_date}&format=json";
$cache_key = md5($dot_url);

/* init statistics data */
$first_debt = null;
$last_debt = null;
$delta_str = null;
$average_per_day_str = null;
$working_days = null;
$days_gt_start_debt_count = null;
$days_gt_start_debt_percentage = null;
$days_lt_start_debt_count = null;
$days_lt_start_debt_percentage = null;

$delta = 0;
/* if we have data, build the output */
$output = "<h3>No data for {$start_date} to {$end_date}.<br />https://www.treasurydirect.gov/NP_WS/ is down.</h3>";
if (!apc_exists($cache_key)) {
    $response = file_get_contents($dot_url);
    apc_add($cache_key, $response);
} else {
    $response = apc_fetch($cache_key);
}
$graph_values_arr = [];

if ($response) {
    $datas = json_decode($response);
    if (!empty($datas)) {
        if ($data = current($datas)) {
            /* start the tabs and contents for each */
            $output = '';
            $graph_labels = '[]';
            $graph_labels_arr = array();
            $graph_values = '[]';
            $graph_values_arr = array();
            $graph_colors = '[]';
            $graph_colors_arr = array();
            $graph_colors_toggle = false;

            /* we need the first and last debt to get the delta, and to compute the debt per day for the date range */
            $last_debt = current($data);
            $first_debt = end($data);
            $firstDate = new DateTime($last_debt->effectiveDate);
            $lastDate = new DateTime($first_debt->effectiveDate);
            $working_days = count($data);
            $days = $firstDate->diff($lastDate)->days;
            $delta = $last_debt->totalDebt - $first_debt->totalDebt;
            $average_per_day = round($delta / $working_days, 2);
            if (0 > $delta) {
                $delta_str = '-$' . number_format(abs($delta), 2) . '';
            } else {
                $delta_str = '$' . number_format($delta, 2);
            }
            if (0 > $average_per_day) {
                $average_per_day_str = '-$' . number_format(abs($average_per_day), 2) . '';
            } else {
                $average_per_day_str = '$' . number_format($average_per_day, 2);
            }
            $days_lt_start_debt = [];
            $days_gt_start_debt = [];
            $average_per_time_span = 0;
            /* put the data into graph format */
            foreach ($data as $d) {
                /* if the amount is greather than the starting date amount, then add it to the array of days */
                if ($first_debt->totalDebt < $d->totalDebt) {
                    $days_gt_start_debt[] = $d;
                }
                /* if the amount is less than the starting date amount, then add it to the array of days */
                if ($first_debt->totalDebt > $d->totalDebt) {
                    $days_lt_start_debt[] = $d;
                }
                /* enter the debt values into an array to get the average */
                $average_per_time_span += $d->totalDebt;

                /* add a record to the data table */
                $dateDt = new DateTime($d->effectiveDate);
                $debt_amount = '$' . number_format($d->totalDebt, 2);
                $output .= "<tr><td class=\"date\">{$dateDt->format('m/d/Y')}</td>" .
                    "<td class=\"currency\">{$debt_amount}</td></tr>";

                /* is this a day when a president took office? if so switch the color. */
                if (array_key_exists($dateDt->getTimestamp(), $pres_array)) {
                    array_unshift($graph_colors_arr, $pres_array[$dateDt->getTimestamp()]['grfcolor']);
                } else {
                    /* get the last color and add it to the array */
                    array_unshift($graph_colors_arr, end($graph_colors_arr));
                }

                /* use the date for the labels on the graph */
                array_unshift($graph_labels_arr, $dateDt->format('n/j/y'));

                /* use the debt for the points on the graph */
                array_unshift($graph_values_arr, round($d->totalDebt / 1000000000000, 10));
            }

            /* calculate greater than debt */
            $days_gt_start_debt_count = count($days_gt_start_debt);
            $days_gt_start_debt_percentage = round(($days_gt_start_debt_count / $working_days) * 100, 2);

            /* calculate greater than debt */
            $days_lt_start_debt_count = count($days_lt_start_debt);
            $days_lt_start_debt_percentage = round(($days_lt_start_debt_count / $working_days) * 100, 2);

            /* calucate the average for the time span */
            $average_per_time_span /= count($data);

            /* set up graph data */
            if (!empty($graph_labels_arr)) {
                $graph_labels = '["' . implode('", "', $graph_labels_arr) . '"]';
            }
            if (!empty($graph_values_arr)) {
                $graph_values = '["' . implode('", "', $graph_values_arr) . '"]';
            }
            if (!empty($graph_colors_arr)) {
                $graph_colors = '["' . implode('", "', $graph_colors_arr) . '"]';
            }
        }
    }
}

/* build the head links */
$js_files = array(
    "lib/jquery.js?r=" . filemtime('lib/jquery.js'),
    "lib/jquery-ui/jquery-ui.min.js?r=" . filemtime('lib/jquery-ui/jquery-ui.min.js'),
    "lib/Chart.bundle.min.js?r=" . filemtime('lib/Chart.bundle.min.js'),
);
$css_files = array(
    "lib/jquery-ui/jquery-ui.min.css?r=" . filemtime('lib/jquery-ui/jquery-ui.min.css'),
    "lib/jquery-ui/jquery-ui.structure.min.css?r=" . filemtime('lib/jquery-ui/jquery-ui.structure.min.css'),
    "lib/jquery-ui/jquery-ui.theme.min.css?r=" . filemtime('lib/jquery-ui/jquery-ui.theme.min.css')
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
        table.output {
            width: 500px;s
            /*border: 1px solid silver;*/
        }
        table.output thead th {
            border-bottom: 1px solid silver;
        }
        table.output tbody td {
            padding: 3px 10px;
        }
        table.output tbody td.date {
            text-align: center;
        }
        table.output tbody td.currency {
            text-align: right;
        }
        table.output tr:nth-child(even){background-color: #f2f2f2}
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
        img.small_icon {
            width: 22px;
            height: 22px;
        }
    </style>
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
            <table class="output">
                <thead><tr><th>Date</th><th>Total Debt</th></tr></thead>
                <tbody><?php echo $output; ?></tbody>
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
    echo $output;
} ?>
</body>
<footer>

</footer>
<script type="text/javascript">
    $(function() {
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
<?php
$endtime = microtime(true);
echo "<span style=\"font-size: xx-small; display: inline; margin-right:.5em;\">v.{$version}</span>";
printf('<span style="font-size: xx-small; display: inline;">TM%f</span>', $endtime - $starttime);
