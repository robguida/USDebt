<?php
/**
 * "Show numbers as: Total | Per person | Per household" (Your Share).
 *
 * A segmented control made of plain links, so it works without JavaScript and
 * with the keyboard (Tab to an option, Enter to pick it). Each link opens this
 * same view (dates or Compare picks, and trend=0) in that mode; the page
 * script keeps trend=0 in step with the trend switch (data-trend-link). The
 * picked option is marked with aria-current.
 *
 * @var list<array{label: string, href: string, current: bool, value: string}> $options
 * @var string $label  Visible label (plain text).
 * @var string $aria   Screen-reader name of the switch (plain text).
 * @var string $help   One line on what the picked mode means (plain text).
 */

use USDebt\Service\Html;

?>
<section class="per-bar" id="per_switch" aria-label="<?php echo Html::e($aria); ?>">
    <span class="per-label" id="per_label"><?php echo Html::e($label); ?></span>
    <nav class="per-switch" aria-labelledby="per_label">
        <?php foreach ($options as $option) { ?>
        <a class="per-option" href="<?php echo Html::e($option['href']); ?>" data-trend-link data-per="<?php echo Html::e($option['value']); ?>"<?php echo $option['current'] ? ' aria-current="true"' : ''; ?>><?php echo Html::e($option['label']); ?></a>
        <?php } ?>
    </nav>
    <p class="per-help"><?php echo Html::e($help); ?></p>
</section>
