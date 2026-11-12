<?php
/**
 * Headline number cards.
 *
 * @var list<array{label: string, value: string, detail: string}> $cards  Plain text; escaped here.
 */

use USDebt\Service\Html;

?>
<section class="headline" aria-label="Headline numbers">
    <?php foreach ($cards as $card) { ?>
    <div class="stat-card">
        <p class="stat-label"><?php echo Html::e($card['label']); ?></p>
        <p class="stat-value num"><?php echo Html::e($card['value']); ?></p>
        <p class="stat-detail num"><?php echo Html::e($card['detail']); ?></p>
    </div>
    <?php } ?>
</section>
