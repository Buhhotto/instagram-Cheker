<?php
/**
 * @var string $label
 * @var float $value
 * @var string|null $note
 */
$class = score_class($value);
?>
<div class="score">
    <div class="top">
        <span><?= e($label) ?><?php if (!empty($note)): ?> <span class="muted small">— <?= e($note) ?></span><?php endif; ?></span>
        <span class="mono"><?= e(number_format((float) $value, 1)) ?></span>
    </div>
    <div class="track"><div class="fill <?= e($class) ?>" style="width: <?= e((string) max(2, min(100, (float) $value))) ?>%"></div></div>
</div>
