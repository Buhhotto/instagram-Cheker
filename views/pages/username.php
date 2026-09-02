<?php
/**
 * @var App\View\View $view
 * @var string $username
 * @var array<string,mixed>|null $result
 * @var string|null $error
 */
$statusMap = [
    'available' => ['result-available', '✅', 'الاسم متاح'],
    'taken' => ['result-taken', '❌', 'الاسم مستخدم'],
    'reserved' => ['result-taken', '🚫', 'اسم محجوز'],
    'invalid' => ['result-taken', '⚠️', 'اسم غير صالح'],
    'unknown' => ['result-unknown', '❔', 'الحالة غير مؤكدة'],
];
?>
<h1 class="page-title">التحقق من توفّر اسم مستخدم</h1>
<p class="page-sub">فحص قواعد إنستاجرام للاسم ثم التأكد من وجوده فعليًا، مع اقتراح بدائل عند كونه محجوزًا.</p>

<?= $view->partial('partials/search-form', [
    'action' => '/username',
    'username' => $username,
    'label' => 'اسم المستخدم المطلوب',
    'submit' => 'تحقّق',
]) ?>

<?php if (!empty($error)): ?>
    <div class="alert alert-error"><?= e($error) ?></div>
<?php endif; ?>

<?php if ($result !== null): ?>
    <?php [$class, $icon, $title] = $statusMap[$result['status']] ?? $statusMap['unknown']; ?>

    <div class="section">
        <div class="result-banner <?= e($class) ?>">
            <div class="icon"><?= $icon ?></div>
            <div>
                <h2><?= e($title) ?> — <span class="mono">@<?= e($result['username']) ?></span></h2>
                <p><?= e($result['message'] ?? '') ?></p>
            </div>
            <div style="margin-inline-start:auto" class="chips">
                <span class="chip">الثقة: <strong><?= e($result['confidence'] === 'high' ? 'مرتفعة' : 'منخفضة') ?></strong></span>
                <?php if (!empty($result['source'])): ?>
                    <span class="chip">المصدر: <strong><?= e($result['source']) ?></strong></span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="section grid grid-2">
        <div class="card">
            <h2 style="margin-top:0">فحص القواعد</h2>
            <ul class="list-clean">
                <li>الطول: <strong><?= e((string) $result['validation']['length']) ?></strong> / 30 حرفًا</li>
                <li>مطابقة الصيغة:
                    <?php if ($result['validation']['valid']): ?>
                        <span class="badge good">مطابق</span>
                    <?php else: ?>
                        <span class="badge low">غير مطابق</span>
                    <?php endif; ?>
                </li>
                <li>ضمن الأسماء المحجوزة:
                    <span class="badge <?= $result['validation']['reserved'] ? 'low' : 'good' ?>">
                        <?= $result['validation']['reserved'] ? 'نعم' : 'لا' ?>
                    </span>
                </li>
                <li>الرابط: <a class="mono" href="<?= e($result['profile_url']) ?>" target="_blank" rel="noopener nofollow">
                        <?= e($result['profile_url']) ?></a></li>
            </ul>

            <?php if ($result['validation']['errors'] !== []): ?>
                <div class="alert alert-error" style="margin-bottom:0">
                    <strong>أخطاء تمنع التسجيل:</strong>
                    <ul><?php foreach ($result['validation']['errors'] as $item): ?><li><?= e($item) ?></li><?php endforeach; ?></ul>
                </div>
            <?php endif; ?>

            <?php if ($result['validation']['notes'] !== []): ?>
                <div class="alert alert-warn" style="margin-bottom:0">
                    <strong>ملاحظات:</strong>
                    <ul><?php foreach ($result['validation']['notes'] as $item): ?><li><?= e($item) ?></li><?php endforeach; ?></ul>
                </div>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2 style="margin-top:0">أسماء بديلة مقترحة</h2>
            <?php if (empty($result['suggestions'])): ?>
                <p class="muted">لا توجد اقتراحات — الاسم متاح أو يتعذّر توليد بدائل صالحة منه.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>الاسم المقترح</th><th>الحالة</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($result['suggestions'] as $suggestion): ?>
                            <tr>
                                <td class="mono">@<?= e($suggestion['username']) ?></td>
                                <td>
                                    <?php if ($suggestion['available'] === true): ?>
                                        <span class="badge good">متاح</span>
                                    <?php elseif ($suggestion['available'] === false): ?>
                                        <span class="badge low">مستخدم</span>
                                    <?php else: ?>
                                        <span class="badge">لم يُفحص</span>
                                    <?php endif; ?>
                                </td>
                                <td><a href="/username?username=<?= e(rawurlencode($suggestion['username'])) ?>">افحصه</a></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?= $view->partial('partials/warnings', ['warnings' => $result['warnings'] ?? []]) ?>

    <div class="section">
        <p class="muted small">
            نتيجة "متاح" تعني أن إنستاجرام لا يعرض حسابًا بهذا الاسم وقت الفحص، ولا تضمن قبوله عند التسجيل
            إذا كان محجوزًا داخليًا أو مرتبطًا بحساب معطّل. للاستعلام البرمجي:
            <code>/api/username/check?username=<?= e($result['username']) ?></code>
        </p>
    </div>
<?php endif; ?>
