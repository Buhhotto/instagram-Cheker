<?php
/**
 * @var App\View\View $view
 * @var string $username
 * @var int $limit
 * @var array<string,mixed>|null $report
 * @var string|null $error
 */
$toneLabels = ['positive' => 'إيجابية', 'negative' => 'سلبية', 'neutral' => 'محايدة'];
?>
<h1 class="page-title">قياس سلوك حساب وردوده</h1>
<p class="page-sub">متى ينشر، كيف يكتب، كيف يتفاعل جمهوره، وكيف يردّ على التعليقات.</p>

<?= $view->partial('partials/search-form', [
    'action' => '/behavior',
    'username' => $username,
    'label' => 'اسم الحساب المراد قياسه',
    'submit' => 'قِس السلوك',
    'withLimit' => true,
    'limit' => $limit,
]) ?>

<?php if (!empty($error)): ?>
    <div class="alert alert-error"><?= e($error) ?></div>
<?php endif; ?>

<?php if ($report !== null):
    $scores = $report['scores'];
    $timing = $report['timing'];
    $writing = $report['writing'];
    $responses = $report['responses'];
    $archetype = $report['archetype'];
    $maxCell = 0;
    foreach ($timing['heatmap'] as $row) {
        $maxCell = max($maxCell, ...$row);
    }
    ?>

    <div class="section">
        <div class="card">
            <div style="display:flex;gap:20px;align-items:center;flex-wrap:wrap">
                <div>
                    <h2 style="margin:0"><span class="mono">@<?= e($report['username']) ?></span> — <?= e($archetype['label']) ?></h2>
                    <p class="muted" style="margin:4px 0 0"><?= e($archetype['description']) ?></p>
                </div>
                <div style="margin-inline-start:auto;text-align:center">
                    <div class="muted small">المؤشر العام</div>
                    <div style="font-size:2.4rem;font-weight:700" class="<?= e(score_class($scores['overall'])) ?>">
                        <?= e((string) $scores['overall']) ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="section grid grid-2">
        <div class="card">
            <h2 style="margin-top:0">الدرجات السلوكية</h2>
            <?= $view->partial('partials/score', ['label' => 'النشاط', 'value' => $scores['activity'], 'note' => 'وتيرة النشر']) ?>
            <?= $view->partial('partials/score', ['label' => 'الانتظام', 'value' => $scores['consistency'], 'note' => 'ثبات الفجوات الزمنية']) ?>
            <?= $view->partial('partials/score', ['label' => 'التفاعل', 'value' => $scores['engagement'], 'note' => 'مقارنة بحجم الجمهور']) ?>
            <?= $view->partial('partials/score', ['label' => 'الاستجابة', 'value' => $scores['responsiveness'], 'note' => $responses['available'] ? 'الرد على التعليقات' : 'غير متاح']) ?>
            <?= $view->partial('partials/score', ['label' => 'الأصالة', 'value' => $scores['originality'], 'note' => 'مقابل إعادة النشر']) ?>
            <?= $view->partial('partials/score', ['label' => 'التحفيز', 'value' => $scores['interactivity'], 'note' => 'الأسئلة ودعوات التفاعل']) ?>
        </div>

        <div class="card">
            <h2 style="margin-top:0">سلوك التوقيت</h2>
            <ul class="list-clean">
                <li>أكثر ساعات النشر: <strong class="mono"><?= e($timing['peak_hours'] === [] ? '—' : implode('، ', $timing['peak_hours'])) ?></strong></li>
                <li>أكثر أيام النشر: <strong><?= e($timing['peak_days'] === [] ? '—' : implode('، ', $timing['peak_days'])) ?></strong></li>
                <li>متوسط الفجوة بين المنشورات: <strong><?= e((string) $timing['avg_gap_hours']) ?></strong> ساعة</li>
                <li>النشر الليلي (قبل 6 صباحًا): <strong><?= e(pct($timing['night_owl_ratio'])) ?></strong></li>
                <li>انتظام الإيقاع: <span class="badge <?= e(score_class($timing['rhythm_consistency'])) ?>"><?= e(pct($timing['rhythm_consistency'])) ?></span></li>
            </ul>
        </div>
    </div>

    <div class="section">
        <h2>خريطة النشاط الأسبوعية</h2>
        <div class="card table-wrap">
            <div class="heatmap">
                <div class="hm-label"></div>
                <?php for ($hour = 0; $hour < 24; $hour++): ?>
                    <div class="hm-hour"><?= $hour % 3 === 0 ? e((string) $hour) : '' ?></div>
                <?php endfor; ?>
                <?php foreach ($timing['heatmap'] as $dayIndex => $row): ?>
                    <div class="hm-label"><?= e($timing['day_labels'][$dayIndex]) ?></div>
                    <?php foreach ($row as $hour => $count):
                        $level = $maxCell > 0 && $count > 0 ? (int) ceil(($count / $maxCell) * 4) : 0;
                        ?>
                        <div class="hm-cell <?= $level > 0 ? 'hm-l' . $level : '' ?>"
                             title="<?= e($timing['day_labels'][$dayIndex]) ?> <?= e(sprintf('%02d:00', $hour)) ?> — <?= e((string) $count) ?> منشور"></div>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </div>
            <p class="muted small" style="margin:14px 0 0">كل مربع يمثّل ساعة من أيام الأسبوع؛ كلما زاد اللون زاد عدد المنشورات في تلك الساعة.</p>
        </div>
    </div>

    <div class="section grid grid-2">
        <div class="card">
            <h2 style="margin-top:0">سلوك الكتابة</h2>
            <ul class="list-clean">
                <li>أسلوب التسمية: <strong><?= e($writing['style']) ?></strong> (متوسط <?= e((string) $writing['avg_length']) ?> حرفًا)</li>
                <li>متوسط الرموز التعبيرية: <strong><?= e((string) $writing['avg_emoji_per_caption']) ?></strong> لكل منشور</li>
                <li>منشورات تحتوي سؤالًا: <strong><?= e(pct($writing['question_ratio'])) ?></strong></li>
                <li>منشورات فيها دعوة لاتخاذ إجراء: <strong><?= e(pct($writing['cta_ratio'])) ?></strong></li>
                <li>متوسط الوسوم / الإشارات: <strong><?= e((string) $writing['avg_hashtags']) ?></strong> / <?= e((string) $writing['avg_mentions']) ?></li>
                <li>النبرة العامة:
                    <span class="badge <?= $writing['tone']['label'] === 'positive' ? 'good' : ($writing['tone']['label'] === 'negative' ? 'low' : 'mid') ?>">
                        <?= e($toneLabels[$writing['tone']['label']]) ?>
                    </span>
                    <span class="muted small">(<?= e((string) $writing['tone']['positive']) ?> إيجابي · <?= e((string) $writing['tone']['negative']) ?> سلبي)</span>
                </li>
            </ul>
        </div>

        <div class="card">
            <h2 style="margin-top:0">سلوك الردود</h2>
            <?php if (!$responses['available']): ?>
                <div class="alert alert-warn" style="margin-top:0"><?= e($responses['reason']) ?></div>
                <p class="muted small" style="margin-bottom:0">
                    لقراءة التعليقات والردود يجب ضبط <code>IG_ACCESS_TOKEN</code> و<code>IG_OWNER_USERNAME</code> لحساب تملكه،
                    لأن إنستاجرام لا يتيح تعليقات حسابات الآخرين لأي تطبيق.
                </p>
            <?php else: ?>
                <ul class="list-clean">
                    <li>تعليقات الجمهور: <strong><?= e((string) $responses['audience_comments']) ?></strong>
                        · ردود صاحب الحساب: <strong><?= e((string) $responses['owner_replies']) ?></strong></li>
                    <li>نسبة الرد: <span class="badge <?= e(score_class($responses['reply_rate'])) ?>"><?= e(pct($responses['reply_rate'])) ?></span></li>
                    <li>سرعة الرد: <strong><?= e($responses['reply_speed']['label']) ?></strong>
                        (وسيط <?= e((string) $responses['reply_speed']['median_hours']) ?> ساعة)</li>
                    <li>أسلوب الرد: <strong><?= e($responses['owner']['style']) ?></strong>
                        (متوسط <?= e((string) $responses['owner']['avg_reply_length']) ?> حرفًا)</li>
                    <li>نبرة ردوده:
                        <span class="badge <?= $responses['owner']['tone']['label'] === 'positive' ? 'good' : ($responses['owner']['tone']['label'] === 'negative' ? 'low' : 'mid') ?>">
                            <?= e($toneLabels[$responses['owner']['tone']['label']]) ?></span></li>
                    <li>نبرة الجمهور تجاهه:
                        <span class="badge <?= $responses['audience_sentiment']['label'] === 'positive' ? 'good' : ($responses['audience_sentiment']['label'] === 'negative' ? 'low' : 'mid') ?>">
                            <?= e($toneLabels[$responses['audience_sentiment']['label']]) ?></span>
                        <span class="muted small">
                            (<?= e((string) $responses['audience_sentiment']['positive']) ?> إيجابي ·
                            <?= e((string) $responses['audience_sentiment']['neutral']) ?> محايد ·
                            <?= e((string) $responses['audience_sentiment']['negative']) ?> سلبي)
                        </span>
                    </li>
                </ul>

                <?php if ($responses['top_commenters'] !== []): ?>
                    <p class="small muted" style="margin:12px 0 6px">أكثر المتفاعلين تعليقًا:</p>
                    <div class="chips">
                        <?php foreach ($responses['top_commenters'] as $commenter): ?>
                            <span class="chip mono">@<?= e($commenter['value']) ?> <strong><?= e((string) $commenter['count']) ?></strong></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ($responses['sample_replies'] !== []): ?>
                    <p class="small muted" style="margin:14px 0 6px">نماذج من ردوده:</p>
                    <ul class="list-clean">
                        <?php foreach ($responses['sample_replies'] as $reply): ?>
                            <li><?= e($reply['text']) ?> <span class="muted small mono">— <?= e($reply['published_at']) ?></span></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($report['recommendations'] !== []): ?>
        <div class="section">
            <h2>توصيات مبنية على القياس</h2>
            <div class="card">
                <ul class="list-clean">
                    <?php foreach ($report['recommendations'] as $tip): ?>
                        <li>← <?= e($tip) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    <?php endif; ?>

    <?= $view->partial('partials/warnings', ['warnings' => $report['warnings'] ?? []]) ?>

    <div class="section">
        <p class="muted small">
            العيّنة: <?= e((string) $report['sample']['analyzed_posts']) ?> منشورًا
            و<?= e((string) $report['sample']['analyzed_comments']) ?> تعليقًا.
            للاستعلام البرمجي: <code>/api/account/behavior?username=<?= e($report['username']) ?>&amp;limit=<?= e((string) $limit) ?></code>
        </p>
    </div>
<?php endif; ?>
