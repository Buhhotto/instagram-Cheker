<?php
/**
 * @var App\View\View $view
 * @var string $username
 * @var int $limit
 * @var array<string,mixed>|null $report
 * @var string|null $error
 */
?>
<h1 class="page-title">تحليل حساب</h1>
<p class="page-sub">المتابعون واللايكات والتعليقات، معدّل التفاعل، أنواع المحتوى، أفضل المنشورات، ونسبة إعادة النشر.</p>

<?= $view->partial('partials/search-form', [
    'action' => '/analyze',
    'username' => $username,
    'label' => 'اسم الحساب المراد تحليله',
    'submit' => 'حلّل',
    'withLimit' => true,
    'limit' => $limit,
]) ?>

<?php if (!empty($error)): ?>
    <div class="alert alert-error"><?= e($error) ?></div>
<?php endif; ?>

<?php if ($report !== null):
    $profile = $report['profile'];
    $engagement = $report['engagement'];
    $reposts = $report['reposts'];
    ?>

    <div class="section">
        <div class="card">
            <div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap">
                <div>
                    <h2 style="margin:0">
                        <span class="mono">@<?= e($profile['username']) ?></span>
                        <?php if ($profile['is_verified']): ?><span class="badge good">موثّق</span><?php endif; ?>
                        <?php if ($profile['is_private']): ?><span class="badge low">خاص</span><?php endif; ?>
                    </h2>
                    <p class="muted" style="margin:4px 0 0"><?= e($profile['full_name'] ?? '') ?></p>
                </div>
                <div style="margin-inline-start:auto" class="chips">
                    <span class="chip">المصدر: <strong><?= e($report['source']) ?></strong></span>
                    <span class="chip">عيّنة: <strong><?= e((string) $report['sample']['analyzed_posts']) ?></strong> منشورًا</span>
                    <span class="chip">تغطية: <strong><?= e((string) $report['sample']['covers_days']) ?></strong> يومًا</span>
                </div>
            </div>
            <?php if (!empty($profile['biography'])): ?>
                <p class="muted small" style="margin-bottom:0"><?= e($profile['biography']) ?></p>
            <?php endif; ?>
        </div>
    </div>

    <div class="section grid grid-4">
        <?= $view->partial('partials/stat', ['label' => 'المتابعون', 'value' => num($profile['followers']), 'hint' => number_format($profile['followers']) . ' متابع']) ?>
        <?= $view->partial('partials/stat', ['label' => 'يتابع', 'value' => num($profile['following'])]) ?>
        <?= $view->partial('partials/stat', ['label' => 'المنشورات', 'value' => num($profile['media_count'])]) ?>
        <?= $view->partial('partials/stat', ['label' => 'معدّل التفاعل', 'value' => pct($engagement['engagement_rate'], 2), 'hint' => $engagement['engagement_rate_label']]) ?>
        <?= $view->partial('partials/stat', ['label' => 'متوسط اللايكات', 'value' => num($engagement['avg_likes']), 'hint' => 'وسيط: ' . num($report['likes']['median'])]) ?>
        <?= $view->partial('partials/stat', ['label' => 'متوسط التعليقات', 'value' => num($engagement['avg_comments'])]) ?>
        <?= $view->partial('partials/stat', ['label' => 'منشورات أسبوعيًا', 'value' => (string) $report['posting']['posts_per_week'], 'hint' => $report['posting']['is_active'] ? 'نشط' : 'غير نشط مؤخرًا']) ?>
        <?= $view->partial('partials/stat', ['label' => 'نسبة إعادة النشر', 'value' => pct($reposts['ratio']), 'hint' => $reposts['count'] . ' من ' . $reposts['total'] . ' منشورًا']) ?>
    </div>

    <div class="section grid grid-2">
        <div class="card">
            <h2 style="margin-top:0">اللايكات</h2>
            <ul class="list-clean">
                <li>الإجمالي في العيّنة: <strong><?= e(number_format($report['likes']['total'])) ?></strong></li>
                <li>الأعلى / الأدنى: <strong><?= e(num($report['likes']['max'])) ?></strong> / <?= e(num($report['likes']['min'])) ?></li>
                <li>الانحراف المعياري: <strong><?= e(num($report['likes']['std_dev'], 1)) ?></strong></li>
                <li>ثبات الأداء: <span class="badge <?= e(score_class($report['likes']['consistency'])) ?>"><?= e(pct($report['likes']['consistency'])) ?></span></li>
                <li>لايكات لكل متابع: <strong><?= e(pct($report['audience']['avg_likes_per_follower_percent'], 2)) ?></strong></li>
            </ul>
        </div>

        <div class="card">
            <h2 style="margin-top:0">التعليقات</h2>
            <ul class="list-clean">
                <li>الإجمالي في العيّنة: <strong><?= e(number_format($report['comments']['total'])) ?></strong></li>
                <li>الوسيط لكل منشور: <strong><?= e(num($report['comments']['median'], 1)) ?></strong></li>
                <li>أعلى منشور: <strong><?= e(num($report['comments']['max'])) ?></strong> تعليق</li>
                <li>منشورات بلا تعليقات: <strong><?= e((string) $report['comments']['silent_posts']) ?></strong></li>
                <li>نسبة التعليقات إلى اللايكات: <strong><?= e((string) $engagement['comments_to_likes_ratio']) ?></strong></li>
            </ul>
        </div>
    </div>

    <div class="section">
        <h2>أنواع المحتوى وأداؤها</h2>
        <div class="card table-wrap">
            <table>
                <thead><tr><th>النوع</th><th>العدد</th><th>الحصة</th><th>متوسط اللايكات</th><th>متوسط التعليقات</th></tr></thead>
                <tbody>
                <?php foreach ($report['content']['types'] as $type): ?>
                    <tr>
                        <td><?= e($type['label']) ?></td>
                        <td class="num"><?= e((string) $type['count']) ?></td>
                        <td class="num"><?= e(pct($type['share'])) ?></td>
                        <td class="num"><?= e(num($type['avg_likes'])) ?></td>
                        <td class="num"><?= e(num($type['avg_comments'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p class="muted small" style="margin-bottom:0">
                أفضل نوع أداءً: <strong><?= e((string) ($report['content']['best_type'] ?? '—')) ?></strong>
                · متوسط طول التسمية: <?= e((string) $report['content']['avg_caption_length']) ?> حرفًا
                · متوسط الوسوم: <?= e((string) $report['content']['avg_hashtags_per_post']) ?>
            </p>
        </div>
    </div>

    <div class="section">
        <h2>أفضل المنشورات تفاعلًا</h2>
        <div class="card table-wrap">
            <table>
                <thead><tr><th>التاريخ</th><th>النوع</th><th>اللايكات</th><th>التعليقات</th><th>المحتوى</th></tr></thead>
                <tbody>
                <?php foreach ($report['top_posts'] as $post): ?>
                    <tr>
                        <td class="mono small"><?= e($post['published_at']) ?></td>
                        <td><?= e($post['type']) ?></td>
                        <td class="num"><?= e(num($post['likes'])) ?></td>
                        <td class="num"><?= e(num($post['comments'])) ?></td>
                        <td>
                            <?= e($post['caption_preview'] ?: '—') ?>
                            <?php if (!empty($post['permalink'])): ?>
                                <a class="small" href="<?= e($post['permalink']) ?>" target="_blank" rel="noopener nofollow">↗</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="section">
        <h2>إعادة النشر (Repost)</h2>
        <div class="card">
            <div class="grid grid-3" style="margin-bottom:16px">
                <?= $view->partial('partials/stat', ['label' => 'منشورات معاد نشرها', 'value' => (string) $reposts['count']]) ?>
                <?= $view->partial('partials/stat', ['label' => 'نسبتها من العيّنة', 'value' => pct($reposts['ratio'])]) ?>
                <?= $view->partial('partials/stat', ['label' => 'درجة الأصالة', 'value' => (string) $reposts['originality_score'] . '/100']) ?>
            </div>

            <?php if ($reposts['top_sources'] !== []): ?>
                <p class="small muted" style="margin:0 0 8px">أكثر المصادر المنسوب إليها:</p>
                <div class="chips" style="margin-bottom:16px">
                    <?php foreach ($reposts['top_sources'] as $source): ?>
                        <span class="chip mono">@<?= e($source['value']) ?> <strong><?= e((string) $source['count']) ?></strong></span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($reposts['samples'] !== []): ?>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>التاريخ</th><th>الثقة</th><th>المؤشرات</th><th>المحتوى</th></tr></thead>
                        <tbody>
                        <?php foreach ($reposts['samples'] as $sample): ?>
                            <tr>
                                <td class="mono small"><?= e($sample['published_at']) ?></td>
                                <td><span class="badge <?= e(score_class($sample['confidence'] * 100)) ?>"><?= e((string) round($sample['confidence'] * 100)) ?>%</span></td>
                                <td class="small muted"><?= e(implode('، ', $sample['signals'])) ?></td>
                                <td class="small"><?= e($sample['caption_preview']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="muted" style="margin:0">لم تُرصد مؤشرات إعادة نشر في هذه العيّنة.</p>
            <?php endif; ?>

            <p class="muted small" style="margin-bottom:0">الطريقة: <?= e($reposts['method']) ?></p>
        </div>
    </div>

    <div class="section grid grid-2">
        <div class="card">
            <h2 style="margin-top:0">أكثر الوسوم استخدامًا</h2>
            <?php if ($report['hashtags'] === []): ?>
                <p class="muted">لا توجد وسوم في العيّنة.</p>
            <?php else: ?>
                <div class="chips">
                    <?php foreach ($report['hashtags'] as $tag): ?>
                        <span class="chip">#<?= e($tag['value']) ?> <strong><?= e((string) $tag['count']) ?></strong></span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2 style="margin-top:0">مؤشرات الجمهور</h2>
            <ul class="list-clean">
                <li>نسبة المتابِعين إلى المتابَعين: <strong><?= e((string) $report['audience']['follower_ratio']) ?></strong></li>
                <li>جمهور نشط تقديري: <strong><?= e(num($report['audience']['estimated_active_audience'])) ?></strong></li>
                <li>آخر منشور: <strong><?= e((string) ($report['posting']['last_post'] ?? '—')) ?></strong>
                    (<?= e((string) ($report['posting']['days_since_last_post'] ?? '—')) ?> يومًا)</li>
            </ul>
            <?php if ($report['audience']['flags'] !== []): ?>
                <div class="alert alert-warn" style="margin-bottom:0">
                    <ul style="margin:0"><?php foreach ($report['audience']['flags'] as $flag): ?><li><?= e($flag) ?></li><?php endforeach; ?></ul>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?= $view->partial('partials/warnings', ['warnings' => $report['warnings'] ?? []]) ?>

    <div class="section">
        <p class="muted small">
            للاستعلام البرمجي: <code>/api/account/analyze?username=<?= e($report['username']) ?>&amp;limit=<?= e((string) $limit) ?></code>
        </p>
    </div>
<?php endif; ?>
