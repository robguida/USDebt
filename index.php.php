<?php
/**
 * This is a self contained file for view our U.S. Debt from the U.S. Department of Treasury
 *
 * @author: Robert Guida
 * @date: June 7th, 2017
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
    "bin/jquery-ui/jquery-ui.theme.min.css?r=" . filemtime('bin/jquery-ui/jquery-ui.theme.min.css')
);
$head = '';
foreach ($js_files as $file) {
    $head .= "<script src=\"{$file}\" type=\"text/javascript\"></script>\n";
}
foreach ($css_files as $file) {
    $head .= "<link rel=\"stylesheet\" href=\"{$file}\" />\n";
}

/* get the chart data */
$startDt = new DateTime();
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : $startDt->modify('-1 month')->format('Y-m-d');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
$dot_url = "https://www.treasurydirect.gov/NP_WS/debt/search?startdate={$start_date}&enddate={$end_date}&format=json";

/* if we have data, build the output */
if ($data = current(json_decode(file_get_contents($dot_url)))) {
    $output = '<div id="tabs">' .
                    '<ul>' .
                    '<li><a href="#t1">Graph</a></li>' .
                    '<li><a href="#t2">Data</a></li>' .
                    '</ul>' .
                '<div id="t1"><div class="chart-container"><canvas id="myChart"></canvas></div></div>' .
                '<div id="t2"><table class="output"><thead><tr><th>Date</th><th>Total Debt</th></tr></thead><tbody>';
    $graph_labels = '[]';
    $graph_labels_arr = array();
    $graph_values = '[]';
    $graph_values_arr = array();
    foreach ($data as $d) {
        $dateDt = new DateTime($d->effectiveDate);
        $debt_amount = '$' . number_format($d->totalDebt, 2);
        $output .= "<tr><td class=\"date\">{$dateDt->format('m/d/Y')}</td>" .
                    "<td class=\"currency\">{$debt_amount}</td></tr>";
        /* use the date for the labels on the graph */
        $graph_labels_arr[] = $dateDt->format('n/j/y');
        /* use the debt for the points on the graph */
        $graph_values_arr[] = round($d->totalDebt/1000000000000, 2);
    }
    if (!empty($graph_labels_arr)) {
        $graph_labels = '["' . implode('", "', $graph_labels_arr) . '"]';
    }
    if (!empty($graph_values_arr)) {
        $graph_values = '["' . implode('", "', $graph_values_arr) . '"]';
    }
    $output .= '</tbody><tfoot></tfoot></table></div></div>';
} else {
    $output = "<h3>No data found for {$start_date} through {$end_date}.";
}


?>
<html>
<head>
    <?php echo $head; ?>
    <style>
        body {
            padding: 16px;
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
    </style>
</head>
<body>
<h1>Our U.S. Debt</h1>
<form id="search">
    <label for="start_date">Start Date:</label>
    <input type="text" id="start_date" name="start_date" value="<?php echo $start_date; ?>" />
    <label for="end_date">End Date:</label>
    <input type="text" id="end_date" name="end_date" value="<?php echo $end_date; ?>" />
    <input type="submit" id="submit" name="submit" value="Fetch" />
</form>
<?php echo $output; ?>
</body>
<footer>

</footer>
<script type="text/javascript">
    $(document).ready(function() {
        console.log('jquery loaded');
        $('#tabs').tabs();
        /* bindings */
        $('#start_date').datepicker({
            showOtherMonths: true,
            selectOtherMonths: true,
            changeMonth: true,
            changeYear: true,
            dateFormat: 'yy-mm-dd',
        });
        $('#end_date').datepicker({
            showOtherMonths: true,
            selectOtherMonths: true,
            changeMonth: true,
            changeYear: true,
            dateFormat: 'yy-mm-dd',
        });
        var ctx = $("#myChart");
        var myChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?php echo $graph_labels; ?>,
                datasets: [{
                    label: 'Our U.S. Debt (trillions)',
                    data: <?php echo $graph_values; ?>,
                    backgroundColor: [
                        'rgba(255, 99, 132, 0.2)',
//                        'rgba(54, 162, 235, 0.2)',
//                        'rgba(255, 206, 86, 0.2)',
//                        'rgba(75, 192, 192, 0.2)',
//                        'rgba(153, 102, 255, 0.2)',
//                        'rgba(255, 159, 64, 0.2)'
                    ],
                    borderColor: [
                        'rgba(255,99,132,1)',
//                        'rgba(54, 162, 235, 1)',
//                        'rgba(255, 206, 86, 1)',
//                        'rgba(75, 192, 192, 1)',
//                        'rgba(153, 102, 255, 1)',
                        'rgba(255, 159, 64, 1)'
                    ],
                    borderWidth: 1
                }]
            },
            options: {
                maintainAspectRatio: false,
//                scales: {
//                    yAxes: [{
//                        stacked: true,
//                        gridLines: {
//                            display: true,
//                            color: "rgba(255,99,132,0.2)"
//                        }
//                    }],
//                    xAxes: [{
//                        gridLines: {
//                            display: false
//                        }
//                    }]
//                }
            }
        });
    })
</script>
</html>
