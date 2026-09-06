<?php
/**
 * @var App\View\View $view
 * @var list<App\Watchlist\WatchedUsername> $entries
 * @var int $maxEntries
 * @var array{0:string,1:string}|null $flash
 */
$statusMap = [
    'available' => ['good', 'متاح'],
    'taken' => ['low', 'مستخدم'],
    'reserved' => ['low', 'محجوز'],
    'invalid' => ['low', 'غير صالح'],
    'unknown' => ['mid', 'غير مؤكد'],
    null => ['mid', 'لم يُفحص بعد'],
];
$alertClass = ['ok' => 'alert-info', 'warn' => 'alert-warn', 'error' => 'alert-error'];
?>
<h1 class="page-title">قائمة مراقبة أسماء المستخدمين</h1>
<p class="page-sub">تنبيه فور تحوّل حالة اسم — <strong>وليست حجزًا ولا نقلًا فعليًا</strong>.</p>

<div class="alert alert-info">
    <strong>ما تفعله هذه الصفحة:</strong> تفحص الأسماء التي تضيفها دوريًا (عبر نفس محرّك التحقق)، وتُنبّهك — على الصفحة
    وعبر webhook اختياري — فور تحوّل الحالة، لتذهب أنت وتُسجّل الاسم يدويًا من تطبيق إنستاجرام.<br>
    <strong>ما لا تفعله:</strong> لا تُسجّل دخولًا لأي حساب، ولا تُغيّر اسم مستخدم تلقائيًا، ولا تحجز الاسم فعليًا —
    لا توجد وسيلة رسمية من إنستاجرام لذلك، وأي أتمتة لتسجيل الدخول وتغيير الاسم آليًا تُعدّ قنص أسماء (username
    sniping) مخالفًا لشروط استخدام المنصّة، ولذلك لا تقدّمها هذه المنظومة.
</div>

<?php if ($flash !== null): ?>
    <div class="alert <?= e($alertClass[$flash[0]] ?? 'alert-info') ?>"><?= e($flash[1]) ?></div>
<?php endif; ?>

<div class="search-card">
    <form method="post" action="/watchlist/add">
        <div class="form-row">
            <div class="field">
                <label for="username">اسم المستخدم المراد مراقبته</label>
                <input type="text" id="username" name="username" dir="ltr" placeholder="username أو @username"
                       maxlength="120" autocomplete="off" required>
            </div>
            <div class="field">
                <label for="webhook_url">رابط webhook (اختياري)</label>
                <input type="text" id="webhook_url" name="webhook_url" dir="ltr"
                       placeholder="https://discord.com/api/webhooks/... أو Slack/Zapier" maxlength="500">
            </div>
            <button class="btn" type="submit">أضف للمراقبة</button>
        </div>
    </form>
</div>

<div class="section">
    <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
        <h2 style="margin:0">الأسماء المراقَبة (<?= e((string) count($entries)) ?> / <?= e((string) $maxEntries) ?>)</h2>
        <?php if ($entries !== []): ?>
            <form method="post" action="/watchlist/check-all" style="margin-inline-start:auto">
                <button class="btn" type="submit" style="padding:8px 18px;font-size:.9rem">فحص الكل الآن</button>
            </form>
        <?php endif; ?>
    </div>

    <?php if ($entries === []): ?>
        <div class="card"><p class="muted" style="margin:0">لا توجد أسماء في القائمة بعد.</p></div>
    <?php else: ?>
        <div class="card table-wrap">
            <table>
                <thead>
                <tr>
                    <th>الاسم</th>
                    <th>الحالة</th>
                    <th>آخر فحص</th>
                    <th>آخر تغيّر</th>
                    <th>webhook</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($entries as $entry): [$badgeClass, $badgeLabel] = $statusMap[$entry->lastStatus] ?? $statusMap['unknown']; ?>
                    <tr>
                        <td class="mono">
                            <a href="<?= e($entry->profileUrl()) ?>" target="_blank" rel="noopener nofollow">@<?= e($entry->username) ?></a>
                        </td>
                        <td><span class="badge <?= e($badgeClass) ?>"><?= e($badgeLabel) ?></span></td>
                        <td class="small mono"><?= e($entry->lastCheckedAt !== null ? date('Y-m-d H:i', strtotime($entry->lastCheckedAt)) : '—') ?></td>
                        <td class="small mono"><?= e($entry->statusChangedAt !== null ? date('Y-m-d H:i', strtotime($entry->statusChangedAt)) : '—') ?></td>
                        <td><?= $entry->webhookUrl !== null ? '<span class="badge good">مفعّل</span>' : '<span class="muted small">—</span>' ?></td>
                        <td style="white-space:nowrap">
                            <form method="post" action="/watchlist/check" style="display:inline">
                                <input type="hidden" name="username" value="<?= e($entry->username) ?>">
                                <button class="btn" type="submit" style="padding:5px 12px;font-size:.82rem">فحص</button>
                            </form>
                            <form method="post" action="/watchlist/remove" style="display:inline" onsubmit="return confirm('إزالة @<?= e($entry->username) ?> من القائمة؟');">
                                <input type="hidden" name="username" value="<?= e($entry->username) ?>">
                                <button class="btn" type="submit" style="padding:5px 12px;font-size:.82rem;background:#3a3f4d">إزالة</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="section">
    <h2>الفحص الدوري عبر cron</h2>
    <div class="card">
        <p class="muted small" style="margin-top:0">
            الفحص من الصفحة يدوي. لتفعيل التنبيه التلقائي أضف هذا السطر إلى crontab الخادم
            (كل 15 دقيقة، عدّل المسار حسب مكان المشروع):
        </p>
        <p><code>*/15 * * * * /usr/bin/php /path/to/project/bin/check_watchlist.php >> /path/to/project/storage/watchlist.log 2>&1</code></p>
        <p class="muted small" style="margin-bottom:0">
            دورية معقولة (١٥–٣٠ دقيقة) تكفي لالتقاط التحوّل مبكرًا دون إثقال الواجهة العامة لإنستاجرام بطلبات متكررة.
        </p>
    </div>
</div>

<div class="section">
    <p class="muted small">
        للاستعلام البرمجي: <code>GET /api/watchlist</code> ·
        <code>POST /api/watchlist/add {username, webhook_url?}</code> ·
        <code>POST /api/watchlist/check {username?}</code> (بلا username لفحص الكل) ·
        <code>POST /api/watchlist/remove {username}</code>
    </p>
</div>
