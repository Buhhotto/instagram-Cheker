<?php
/**
 * @var string $label
 * @var string $value
 * @var string|null $hint
 */
?>
<div class="stat">
    <div class="label"><?= e($label) ?></div>
    <div class="value"><?= e($value) ?></div>
    <?php if (!empty($hint)): ?><div class="hint"><?= e($hint) ?></div><?php endif; ?>
</div>
