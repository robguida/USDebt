<?php
/**
 * Created by PhpStorm.
 * User: robert
 * Date: 6/5/18
 * Time: 9:43 PM
 */
/* we need the first and last debt to get the delta,
            current($data)and to compute the debt per day for the date range */
/* we need the first and last debt to get the delta, and to compute the debt per day for the date range */
$lastDebt = current($datas);
$firstDebt = end($datas);
$firstDate = new DateTime($lastDebt->effectiveDate);
$lastDate = new DateTime($firstDebt->effectiveDate);
$working_days = count($datas);
$days = $firstDate->diff($lastDate)->days;
$delta = $lastDebt->totalDebt - $firstDebt->totalDebt;
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
foreach ($datas as $d) {
    /* if the amount is greather than the starting date amount, then add it to the array of days */
    if ($firstDebt->totalDebt < $d->totalDebt) {
        $days_gt_start_debt[] = $d;
    }
    /* if the amount is less than the starting date amount, then add it to the array of days */
    if ($firstDebt->totalDebt > $d->totalDebt) {
        $days_lt_start_debt[] = $d;
    }
    /* enter the debt values into an array to get the average */
    $average_per_time_span += $d->totalDebt;
}

/* calculate greater than debt */
$days_gt_start_debt_count = count($days_gt_start_debt);
$days_gt_start_debt_percentage = round(($days_gt_start_debt_count / $working_days) * 100, 2);

/* calculate greater than debt */
$days_lt_start_debt_count = count($days_lt_start_debt);
$days_lt_start_debt_percentage = round(($days_lt_start_debt_count / $working_days) * 100, 2);

/* calucate the average for the time span */
$average_per_time_span /= $working_days;
$firstDate = new DateTime($lastDebt->effectiveDate);
$lastDate = new DateTime($firstDebt->effectiveDate);

$first_debt = round($firstDebt->totalDebt/1000000000000, 2);

?>
<h3>Starting debt amount</h3>
<!--<p>$--><?php //echo number_format($firstDebt->totalDebt, 2) . ' ($' . number_format($first_debt, 2) . 'T)'; ?><!--</p>-->
<p>$<?php echo number_format($firstDebt->totalDebt, 2); ?></p>
<h3>Last debt amount of date range</h3>
<p>$<?php echo number_format($lastDebt->totalDebt, 2); ?></p>
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
