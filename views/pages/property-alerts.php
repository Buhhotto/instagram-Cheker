<?php

/**
 * @var App\View\View $view
 * @var list<App\PropertyAlerts\PropertySubscription> $entries
 * @var int $maxEntries
 * @var array<string,string> $types
 * @var array<string,string> $propertySources
 * @var bool $whatsappEnabled
 * @var array<string,bool> $selectorsConfigured
 * @var string $defaultSource
 * @var array{0:string,1:string}|null $flash
 */
$alertClass = ['ok' => 'alert-info', 'warn' => 'alert-warn', 'error' => 'alert-error'];
$anySourceConfigured = in_array(true, $selectorsConfigured, true);
?>
<h1 class="page-title">تنبيهات عقارية عبر واتساب</h1>
<p class="page-sub">تنبيه واتساب فور ظهور إعلان أرض جديد على عُمان ريل أو السوق المفتوح يطابق نوعًا وموقعًا تختارهما.</p>

<div class="alert alert-info">
    <strong>ما تفعله هذه الصفحة:</strong> تفحص صفحة نتائج المصدر الذي تختاره دوريًا بفلترك (نوع الأرض + نص
    الموقع/الولاية)، وعند ظهور إعلان لم يُرصد من قبل تُرسل رسالة واتساب برابطه. الفحص الأول لأي اشتراك يسجّل
    الإعلانات الحالية كخط أساس بدون تنبيه، حتى لا تصلك رسالة عن كل إعلان موجود مسبقًا.<br>
    <strong>ما لا تفعله:</strong> لا تتصفّح نيابة عنك ولا تحجز أرضًا ولا تنشئ حسابًا على أي موقع — مجرّد رصد ومقارنة
    وتنبيه على بيانات عامة معروضة أصلًا لأي زائر.
</div>

<?php if (!$anySourceConfigured): ?>
    <div class="alert alert-warn">
        <strong>الفحص غير جاهز بعد لأي مصدر:</strong> مُحدِّدات استخراج الإعلانات (<code>property_alerts.sources.*.selectors</code>
        في <code>.env</code>) غير مضبوطة لعُمان ريل ولا للسوق المفتوح. افتح الموقع المطلوب من متصفح، وحدّد بطاقة
        إعلان واحدة عبر Developer Tools → Copy → Copy XPath، واملأ القيم — راجع التعليقات في
        <code>config/config.php</code> و README. الاشتراكات تُحفظ الآن، لكن الفحص سيفشل برسالة خطأ واضحة حتى تُضبط.
    </div>
<?php else: ?>
    <?php foreach ($propertySources as $key => $label): ?>
        <?php if (!$selectorsConfigured[$key]): ?>
            <div class="alert alert-warn">
                <strong>مصدر «<?= e($label) ?>» غير جاهز:</strong> مُحدِّدات الاستخراج الخاصة به غير مضبوطة بعد —
                الاشتراكات المرتبطة به ستفشل عند الفحص برسالة خطأ واضحة حتى تُضبط.
            </div>
        <?php endif; ?>
    <?php endforeach; ?>
<?php endif; ?>

<?php if (!$whatsappEnabled): ?>
    <div class="alert alert-warn">
        <strong>إرسال واتساب غير مفعّل:</strong> اضبط <code>WHATSAPP_ENABLED=true</code> مع
        <code>WHATSAPP_ACCESS_TOKEN</code> و<code>WHATSAPP_PHONE_NUMBER_ID</code> من
        <a href="https://developers.facebook.com/docs/whatsapp/cloud-api/get-started" target="_blank" rel="noopener nofollow">WhatsApp Cloud API</a>
        حتى تُرسَل التنبيهات فعليًا. الفحص والرصد يعملان بدونها، لكن بلا إرسال.
    </div>
<?php endif; ?>

<?php if ($flash !== null): ?>
    <div class="alert <?= e($alertClass[$flash[0]] ?? 'alert-info') ?>"><?= e($flash[1]) ?></div>
<?php endif; ?>

<div class="search-card">
    <form method="post" action="/property-alerts/add">
        <div class="form-row">
            <div class="field">
                <label for="whatsapp_number">رقم واتساب (صيغة دولية، أرقام فقط)</label>
                <input type="text" id="whatsapp_number" name="whatsapp_number" dir="ltr"
                       placeholder="96879xxxxxx" maxlength="20" autocomplete="off" required>
            </div>
            <div class="field">
                <label for="source">المصدر</label>
                <select id="source" name="source">
                    <?php foreach ($propertySources as $value => $label): ?>
                        <option value="<?= e($value) ?>"><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="property_type">نوع الأرض</label>
                <select id="property_type" name="property_type">
                    <option value="">أي نوع</option>
                    <?php foreach ($types as $value => $label): ?>
                        <option value="<?= e($value) ?>"><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="location">الولاية/المحافظة (نص حر)</label>
                <input type="text" id="location" name="location" placeholder="مثال: مسقط، صلالة" maxlength="120">
            </div>
            <button class="btn" type="submit">أضف التنبيه</button>
        </div>
    </form>
</div>

<div class="section">
    <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
        <h2 style="margin:0">الاشتراكات (<?= e((string) count($entries)) ?> / <?= e((string) $maxEntries) ?>)</h2>
        <?php if ($entries !== []): ?>
            <form method="post" action="/property-alerts/check-all" style="margin-inline-start:auto">
                <button class="btn" type="submit" style="padding:8px 18px;font-size:.9rem">فحص الكل الآن</button>
            </form>
        <?php endif; ?>
    </div>

    <?php if ($entries === []): ?>
        <div class="card"><p class="muted" style="margin:0">لا توجد اشتراكات بعد.</p></div>
    <?php else: ?>
        <div class="card table-wrap">
            <table>
                <thead>
                <tr>
                    <th>واتساب</th>
                    <th>المصدر</th>
                    <th>النوع</th>
                    <th>الموقع</th>
                    <th>إعلانات مرصودة</th>
                    <th>آخر فحص</th>
                    <th>الحالة</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($entries as $entry): ?>
                    <tr>
                        <td class="mono">+<?= e($entry->whatsappNumber) ?></td>
                        <td><?= e($propertySources[$entry->source] ?? $entry->source) ?></td>
                        <td><?= e($entry->propertyType !== null ? ($types[$entry->propertyType] ?? $entry->propertyType) : 'أي نوع') ?></td>
                        <td><?= e($entry->location ?? 'أي موقع') ?></td>
                        <td class="mono"><?= e((string) count($entry->seenListingIds)) ?></td>
                        <td class="small mono"><?= e($entry->lastCheckedAt !== null ? date('Y-m-d H:i', strtotime($entry->lastCheckedAt)) : '—') ?></td>
                        <td>
                            <?php if ($entry->lastError !== null): ?>
                                <span class="badge low" title="<?= e($entry->lastError) ?>">خطأ</span>
                            <?php elseif ($entry->lastCheckedAt !== null): ?>
                                <span class="badge good">سليم</span>
                            <?php else: ?>
                                <span class="badge mid">لم يُفحص بعد</span>
                            <?php endif; ?>
                        </td>
                        <td style="white-space:nowrap">
                            <form method="post" action="/property-alerts/check" style="display:inline">
                                <input type="hidden" name="id" value="<?= e($entry->id) ?>">
                                <button class="btn" type="submit" style="padding:5px 12px;font-size:.82rem">فحص</button>
                            </form>
                            <form method="post" action="/property-alerts/remove" style="display:inline" onsubmit="return confirm('إزالة هذا الاشتراك؟');">
                                <input type="hidden" name="id" value="<?= e($entry->id) ?>">
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
            (كل 15–30 دقيقة، عدّل المسار حسب مكان المشروع):
        </p>
        <p><code>*/20 * * * * /usr/bin/php /path/to/project/bin/check_property_alerts.php >> /path/to/project/storage/property_alerts.log 2>&1</code></p>
    </div>
</div>

<div class="section">
    <p class="muted small">
        للاستعلام البرمجي: <code>GET /api/property-alerts</code> ·
        <code>POST /api/property-alerts/add {whatsapp_number, source?, property_type?, location?}</code>
        (source: <?= e(implode(' أو ', array_keys($propertySources))) ?>، افتراضيًا <?= e($defaultSource) ?>) ·
        <code>POST /api/property-alerts/check {id?}</code> (بلا id لفحص الكل) ·
        <code>POST /api/property-alerts/remove {id}</code>
    </p>
</div>
