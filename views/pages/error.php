<?php
/** @var string $title @var string $message */
?>
<h1 class="page-title"><?= e($title ?? 'خطأ') ?></h1>
<p class="page-sub"><?= e($message ?? 'حدث خطأ.') ?></p>
<p><a href="/">← العودة إلى الصفحة الرئيسية</a></p>
