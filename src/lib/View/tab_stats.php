<?php
/**
 * Created by PhpStorm.
 * User: robert
 * Date: 6/5/18
 * Time: 9:43 PM
 */
?>
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
