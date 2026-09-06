<?php
/**
 * @var string $content
 * @var string $appName
 * @var string $active
 * @var bool $demoOnly
 * @var list<string> $sources
 */
$active ??= '';
$demoOnly ??= false;
$sources ??= [];
$nav = [
    '/' => 'الرئيسية',
    '/username' => 'توفّر الاسم',
    '/analyze' => 'تحليل حساب',
    '/behavior' => 'قياس السلوك',
    '/watchlist' => 'قائمة المراقبة',
];
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($appName ?? 'منظومة إنستاجرام') ?></title>
    <meta name="description" content="منظومة PHP لتحليل حسابات إنستاجرام: توفّر الأسماء، اللايكات والمتابعين وإعادة النشر، وقياس السلوك والردود.">
    <link rel="stylesheet" href="/assets/app.css">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>📊</text></svg>">
</head>
<body>
<header class="site-header">
    <div class="container">
        <a class="brand" href="/"><span class="dot"></span><?= e($appName ?? 'منظومة إنستاجرام') ?></a>
        <nav class="nav">
            <?php foreach ($nav as $href => $label): ?>
                <a href="<?= e($href) ?>" class="<?= ($active === trim($href, '/') || ($href === '/' && $active === 'home')) ? 'active' : '' ?>"><?= e($label) ?></a>
            <?php endforeach; ?>
            <a href="/api/health">API</a>
        </nav>
    </div>
</header>

<main>
    <div class="container">
        <?php if ($demoOnly): ?>
            <div class="alert alert-warn">
                <strong>وضع التجربة مفعّل:</strong> لا يوجد مزوّد بيانات حقيقي مهيّأ، وكل الأرقام المعروضة
                <strong>مُصطنعة</strong> لغرض العرض فقط. أضف توكن Graph API في ملف <code>.env</code> للحصول على بيانات فعلية.
            </div>
        <?php endif; ?>

        <?= $content ?>
    </div>
</main>

<footer class="site-footer">
    <div class="container">
        مصادر البيانات المفعّلة: <?= $sources === [] ? 'لا يوجد' : e(implode('، ', $sources)) ?>
        · تعمل المنظومة على بيانات إنستاجرام العامة فقط وتحترم قيود المنصّة.
    </div>
</footer>
</body>
</html>
