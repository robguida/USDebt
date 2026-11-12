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
    $data .= '<tr><td class="date">' . Html::e($dateDt->format('M j, Y')) . '</td>'
        . '<td class="currency">' . Html::e(Html::money($d->totalDebt)) . '</td></tr>';
}
?>
<div class="table-scroll" tabindex="0" role="region" aria-label="Daily debt table">
    <table class="data-table num">
        <caption class="sr-only">Total debt for each reported day, newest first</caption>
        <thead><tr><th scope="col">Date</th><th scope="col" class="currency">Total debt</th></tr></thead>
        <tbody><?php echo $data; ?></tbody>
    </table>
</div>
