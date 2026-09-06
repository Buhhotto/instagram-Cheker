<?php
/** @var list<string> $warnings */
$warnings = array_values(array_filter($warnings ?? []));
?>
<?php if ($warnings !== []): ?>
    <div class="alert alert-warn">
        <strong>ملاحظات على دقّة البيانات:</strong>
        <ul>
            <?php foreach ($warnings as $warning): ?>
                <li><?= e($warning) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>
