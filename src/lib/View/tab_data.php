<?php
/**
 * Created by PhpStorm.
 * User: robert
 * Date: 6/5/18
 * Time: 10:17 PM
 *
 * @var list<\USDebt\Model\DebtEntry> $datas  Newest first.
 */

use USDebt\Service\Html;

$data = '';
foreach ($datas as $d) {
    $dateDt = new DateTimeImmutable($d->effectiveDate);
    $data .= '<tr><td class="date">' . Html::e($dateDt->format('m/d/Y')) . '</td>'
        . '<td class="currency">' . Html::e(Html::money($d->totalDebt)) . '</td></tr>';
}
?>
<table class="main_content">
    <thead><tr><th>Date</th><th>Total Debt</th></tr></thead>
    <tbody><?php echo $data; ?></tbody>
    <tfoot></tfoot>
</table>
