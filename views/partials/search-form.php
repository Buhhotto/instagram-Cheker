<?php
/**
 * نموذج البحث المشترك بين الصفحات.
 *
 * @var string $action
 * @var string $username
 * @var string $label
 * @var string $submit
 * @var bool $withLimit
 * @var int $limit
 */
$withLimit ??= false;
$limit ??= 25;
?>
<form class="search-card" method="get" action="<?= e($action) ?>">
    <div class="form-row">
        <div class="field">
            <label for="username"><?= e($label) ?></label>
            <input type="text" id="username" name="username" dir="ltr" placeholder="username أو @username"
                   value="<?= e($username ?? '') ?>" maxlength="120" autocomplete="off" required>
        </div>
        <?php if ($withLimit): ?>
            <div class="field" style="flex:0 1 160px">
                <label for="limit">عدد المنشورات</label>
                <input type="number" id="limit" name="limit" min="1" max="50" value="<?= e((string) $limit) ?>">
            </div>
        <?php endif; ?>
        <button class="btn" type="submit"><?= e($submit) ?></button>
    </div>
</form>
