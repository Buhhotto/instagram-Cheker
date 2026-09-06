<?php
/** @var list<string> $sources */
$features = [
    ['/username', '🔎', 'التحقق من توفّر اسم مستخدم', 'فحص قواعد إنستاجرام للاسم، ثم التأكد من وجوده فعليًا، مع اقتراح بدائل متاحة عند كونه محجوزًا.'],
    ['/analyze', '📊', 'تحليل حساب', 'المتابعون واللايكات والتعليقات، معدّل التفاعل، أنواع المحتوى، أفضل المنشورات، ونسبة إعادة النشر (Repost).'],
    ['/behavior', '🧠', 'قياس السلوك والردود', 'أوقات النشر، نبرة الكتابة، مدى تفاعل الجمهور، ونسبة وسرعة ردود صاحب الحساب على التعليقات.'],
];
$endpoints = [
    ['GET', '/api/username/check?username=nasa', 'حالة الاسم + اقتراحات بديلة'],
    ['GET', '/api/account/analyze?username=nasa&limit=25', 'تقرير تحليلي كامل للحساب'],
    ['GET', '/api/account/behavior?username=nasa&limit=25', 'مؤشرات السلوك والاستجابة'],
    ['GET', '/api/health', 'حالة المنظومة والمزوّدين المفعّلين'],
];
?>
<h1 class="page-title">منظومة تحليل إنستاجرام</h1>
<p class="page-sub">ثلاث أدوات متكاملة مبنية بـ PHP فوق واجهات إنستاجرام الرسمية، مع واجهة ويب وواجهة JSON لكل أداة.</p>

<div class="grid grid-3">
    <?php foreach ($features as [$href, $emoji, $title, $description]): ?>
        <a class="card feature-card" href="<?= e($href) ?>">
            <div class="emoji"><?= $emoji ?></div>
            <h3><?= e($title) ?></h3>
            <p><?= e($description) ?></p>
        </a>
    <?php endforeach; ?>
</div>

<div class="section">
    <h2>واجهة JSON</h2>
    <div class="card table-wrap">
        <table>
            <thead><tr><th>الطريقة</th><th>المسار</th><th>الوصف</th></tr></thead>
            <tbody>
            <?php foreach ($endpoints as [$method, $path, $description]): ?>
                <tr>
                    <td><span class="badge"><?= e($method) ?></span></td>
                    <td><code><?= e($path) ?></code></td>
                    <td class="muted"><?= e($description) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="section">
    <h2>مصادر البيانات</h2>
    <div class="grid grid-3">
        <div class="card">
            <h3 style="margin-top:0">Graph API الرسمي</h3>
            <p class="muted small">أدقّ مصدر: بيانات المتابعين والمنشورات لأي حساب أعمال عبر <code>business_discovery</code>،
                وقراءة التعليقات والردود للحساب المالك للتوكن. يتطلب <code>IG_ACCESS_TOKEN</code> و<code>IG_USER_ID</code>.</p>
        </div>
        <div class="card">
            <h3 style="margin-top:0">الواجهة العامة</h3>
            <p class="muted small">تعمل بدون توكن وتُستخدم أساسًا للتحقق من توفّر الأسماء. قد يحدّ إنستاجرام الطلبات،
                ولذلك تُرجع المنظومة "غير معروف" بدل تخمين نتيجة غير مؤكدة.</p>
        </div>
        <div class="card">
            <h3 style="margin-top:0">وضع التجربة</h3>
            <p class="muted small">بيانات مُصطنعة حتمية (نفس الاسم ⇒ نفس النتيجة) لتشغيل كل الشاشات بدون مفاتيح.
                كل مخرجاته موسومة بـ <code>demo</code>.</p>
        </div>
    </div>
</div>

<div class="section">
    <div class="alert alert-info">
        <strong>حدود ما تستطيع الواجهات تقديمه:</strong> لا يوفّر إنستاجرام قائمة المتابعين، ولا حقلًا صريحًا لإعادة النشر،
        ولا تعليقات حسابات لا تملكها. لذلك تُحسب نسبة إعادة النشر بمؤشرات نصية تقديرية، ويُذكر مصدر كل رقم ودرجة الثقة فيه.
    </div>
</div>
