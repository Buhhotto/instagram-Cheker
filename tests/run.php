<?php

declare(strict_types=1);

/**
 * اختبارات المنظومة: php tests/run.php
 *
 * تعمل بالكامل دون اتصال بالشبكة بالاعتماد على مزوّد البيانات التجريبية.
 */

use App\Cache\FileCache;
use App\Http\RateLimiter;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Instagram\AccountAnalyzer;
use App\Instagram\Analysis\RepostDetector;
use App\Instagram\Analysis\Sentiment;
use App\Instagram\BehaviorAnalyzer;
use App\Instagram\Contracts\ProfileProvider;
use App\Instagram\DTO\Media;
use App\Instagram\Exception\NotFoundException;
use App\Instagram\Exception\TransportException;
use App\Instagram\Exception\ValidationException;
use App\Instagram\Providers\DemoProvider;
use App\Instagram\ProviderChain;
use App\Instagram\UsernameChecker;
use App\Support\SeededRandom;
use App\Support\Stats;
use App\Support\Text;
use Tests\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/TestCase.php';

$t = new TestCase();

// ===== أدوات النصوص =====
$t->group('Text — معالجة النصوص');
$t->assertSame('احمد', Text::normalize('أَحْمَد'), 'توحيد الهمزة والتشكيل');
$t->assertSame(['tech', 'تقنية'], Text::hashtags('منشور #tech ووسم #تقنية'), 'استخراج الوسوم');
$t->assertSame(['user.name'], Text::mentions('شكرًا @user.name على المشاركة'), 'استخراج الإشارات');
$t->assert(Text::countEmoji('رائع 🚀🔥') === 2, 'عدّ الرموز التعبيرية');
$t->assert(Text::containsQuestion('ما رأيك؟'), 'كشف علامة الاستفهام العربية');
$t->assert(mb_strlen(Text::truncate(str_repeat('ا', 300), 50)) === 50, 'قصّ النص الطويل');

// ===== الإحصاء =====
$t->group('Stats — الدوال الإحصائية');
$t->assertSame(3.0, Stats::mean([1, 2, 3, 4, 5]), 'المتوسط');
$t->assertSame(2.5, Stats::median([1, 2, 3, 4]), 'الوسيط لعدد زوجي');
$t->assertSame(0.0, Stats::stdDev([5]), 'الانحراف المعياري لعنصر واحد');
$t->assertSame(25.0, Stats::percent(1, 4), 'النسبة المئوية');
$t->assertSame(100.0, Stats::clamp(150), 'قصّ القيمة عند الحد الأعلى');
$top = Stats::topCounts(['a', 'b', 'a', 'c', 'a', 'b'], 2);
$t->assertSame('a', $top[0]['value'], 'أكثر العناصر تكرارًا');
$t->assertSame(3, $top[0]['count'], 'عدد تكرارات العنصر الأول');

// ===== العشوائية الحتمية =====
$t->group('SeededRandom — ثبات البيانات التجريبية');
$first = new SeededRandom('seed');
$second = new SeededRandom('seed');
$t->assertSame($first->int(1, 1000), $second->int(1, 1000), 'نفس البذرة تعطي نفس النتيجة');
$t->assert((new SeededRandom('a'))->int(1, 1000) !== (new SeededRandom('b'))->int(1, 1000), 'بذور مختلفة تعطي نتائج مختلفة');

// ===== تحليل النبرة =====
$t->group('Sentiment — تحليل النبرة');
$sentiment = new Sentiment();
$t->assertSame('positive', $sentiment->analyze('محتوى رائع ومفيد شكرًا')['label'], 'نص عربي إيجابي');
$t->assertSame('negative', $sentiment->analyze('this is bad and boring')['label'], 'نص إنجليزي سلبي');
$t->assertSame('negative', $sentiment->analyze('مش حلو')['label'], 'معالجة النفي');
$t->assertSame('neutral', $sentiment->analyze('نشرت اليوم صورة')['label'], 'نص محايد');
$summary = $sentiment->summarize(['ممتاز جدًا', 'سيء', 'صورة']);
$t->assertSame(3, $summary['total'], 'تلخيص مجموعة نصوص');

// ===== كشف إعادة النشر =====
$t->group('RepostDetector — كشف إعادة النشر');
$detector = new RepostDetector();
$repost = Media::fromArray(['id' => '1', 'caption' => 'ريبوست من @creator #repost', 'timestamp' => time()]);
$original = Media::fromArray(['id' => '2', 'caption' => 'صورة من تصويري اليوم 📸', 'timestamp' => time()]);
$t->assert($detector->inspect($repost)['is_repost'], 'كشف منشور معاد نشره');
$t->assertSame('creator', $detector->inspect($repost)['credited'], 'نسب المصدر الصحيح');
$t->assert(!$detector->inspect($original)['is_repost'], 'عدم وسم المحتوى الأصلي كإعادة نشر');
$summaryReposts = $detector->summarize([$repost, $original]);
$t->assertSame(50.0, $summaryReposts['ratio'], 'حساب نسبة إعادة النشر');
$t->assertSame(50.0, $summaryReposts['originality_score'], 'درجة الأصالة مكمّلة للنسبة');

// ===== التخزين المؤقت =====
$t->group('FileCache — التخزين المؤقت');
$cacheDir = sys_get_temp_dir() . '/ig_cache_test_' . bin2hex(random_bytes(4));
$cache = new FileCache($cacheDir);
$cache->set('k', ['v' => 1], 60);
$t->assertSame(['v' => 1], $cache->get('k'), 'استرجاع قيمة مخزّنة');
$cache->set('expired', 'x', -1);
$t->assertSame(null, $cache->get('expired'), 'تجاهل القيم منتهية الصلاحية');
$t->assertSame('produced', $cache->remember('lazy', 60, fn (): string => 'produced'), 'remember ينفّذ المولّد');
$t->assertSame('produced', $cache->get('lazy'), 'remember يخزّن الناتج');
$cache->forget('k');
$t->assertSame(null, $cache->get('k'), 'حذف مفتاح');

// ===== حدّ الطلبات =====
$t->group('RateLimiter — حدّ الطلبات');
$limiter = new RateLimiter($cache, 3, 60);
$allowed = 0;
for ($i = 0; $i < 4; $i++) {
    $allowed += $limiter->hit('tester')['allowed'] ? 1 : 0;
}
$t->assertSame(3, $allowed, 'السماح بثلاثة طلبات فقط داخل النافذة');
$t->assert(!$limiter->hit('tester')['allowed'], 'رفض الطلب بعد تجاوز الحد');
$t->assert((new RateLimiter($cache, 0, 60))->hit('any')['allowed'], 'تعطيل الحد عند القيمة صفر');

// ===== التحقق من أسماء المستخدمين =====
$t->group('UsernameChecker — قواعد الأسماء');
$providers = new ProviderChain([new DemoProvider(true)]);
$checker = new UsernameChecker($providers, 6, 2);
$t->assert($checker->validate('valid.user_1')['valid'], 'اسم صالح');
$t->assert(!$checker->validate('.leading')['valid'], 'رفض النقطة في البداية');
$t->assert(!$checker->validate('trailing.')['valid'], 'رفض النقطة في النهاية');
$t->assert(!$checker->validate(str_repeat('a', 31))['valid'], 'رفض تجاوز 30 حرفًا');
$t->assert(!$checker->validate('اسم_عربي')['valid'], 'رفض الحروف غير الإنجليزية');
$t->assert(!$checker->validate('...')['valid'], 'رفض النقاط فقط');
$t->assert($checker->validate('instagram')['reserved'], 'كشف الأسماء المحجوزة');
$t->assertSame('nasa', $checker->normalize('@NASA'), 'تنظيف علامة @');
$t->assertSame('nasa', $checker->normalize('https://www.instagram.com/nasa/'), 'استخراج الاسم من الرابط');
$t->assertThrows(ValidationException::class, fn () => $checker->normalize('  '), 'رفض المدخل الفارغ');

$t->group('UsernameChecker — نتيجة الفحص');
$taken = $checker->check('instagram');
$t->assertSame('reserved', $taken['status'], 'الاسم المحجوز يُصنّف reserved');
$t->assert(!$taken['available'], 'الاسم المحجوز غير متاح');
$t->assert($taken['suggestions'] !== [], 'توليد بدائل للاسم المحجوز');
$invalid = $checker->check('bad name!');
$t->assertSame('invalid', $invalid['status'], 'الاسم غير الصالح يُصنّف invalid');
$known = $checker->check('nasa', false);
$t->assertSame('taken', $known['status'], 'حساب معروف يُصنّف taken');
$t->assertSame('demo', $known['source'], 'تسجيل مصدر النتيجة');
$t->assertSame('low', $known['confidence'], 'خفض الثقة عند اعتماد وضع التجربة');
$t->assert($known['warnings'] !== [], 'تحذير صريح بأن البيانات مُصطنعة');
foreach ($checker->suggest('nasa', false) as $suggestion) {
    $t->assert($checker->validate($suggestion['username'])['valid'], 'الاقتراح "' . $suggestion['username'] . '" صالح');
}

// ===== سلسلة المزوّدين =====
$t->group('ProviderChain — تسلسل المصادر');
$failing = new class implements ProfileProvider {
    public function name(): string { return 'failing'; }
    public function isConfigured(): bool { return true; }
    public function fetchProfile(string $u): ?App\Instagram\DTO\Profile { throw new TransportException('انقطاع محاكى'); }
    public function fetchMedia(string $u, int $limit = 25): array { return []; }
    public function fetchComments(string $u, string $mediaId, int $limit = 50): array { return []; }
    public function supportsComments(string $u): bool { return false; }
    public function usernameExists(string $u): ?bool { return null; }
};
$chain = new ProviderChain([$failing, new DemoProvider(true)]);
$profile = $chain->fetchProfile('nasa');
$t->assert($profile !== null, 'التحوّل إلى المزوّد التالي عند فشل الأول');
$t->assertSame('demo', $chain->lastSource(), 'تسجيل المزوّد الذي نجح');
$t->assert($chain->warnings() !== [], 'تسجيل تحذير عن المزوّد الفاشل');
$t->assertSame(['failing', 'demo'], $chain->activeNames(), 'سرد المزوّدين المفعّلين');
$t->assert($chain->sourceWarnings() !== [], 'تحذير عند الاعتماد على المزوّد التجريبي');
$disabled = new ProviderChain([new DemoProvider(false)]);
$t->assertThrows(
    App\Instagram\Exception\ProviderUnavailableException::class,
    fn () => $disabled->fetchProfile('nasa'),
    'رمي استثناء عند غياب أي مزوّد'
);

// ===== تحليل الحساب =====
$t->group('AccountAnalyzer — تحليل الحساب');
$analyzer = new AccountAnalyzer($providers, $detector, $checker, 25);
$report = $analyzer->analyze('nasa', 20);
$t->assertSame('nasa', $report['username'], 'اسم الحساب في التقرير');
$t->assertSame(20, $report['sample']['analyzed_posts'], 'عدد المنشورات المحلَّلة');
$t->assert($report['profile']['followers'] > 0, 'قراءة عدد المتابعين');
$t->assert($report['engagement']['engagement_rate'] > 0, 'حساب معدّل التفاعل');
$t->assert($report['likes']['total'] >= $report['likes']['max'], 'اتساق إجمالي اللايكات');
$t->assertBetween((float) $report['likes']['consistency'], 0, 100, 'ثبات اللايكات ضمن المدى');
$t->assert(count($report['top_posts']) <= 5, 'حصر أفضل المنشورات بخمسة');
$t->assert($report['top_posts'][0]['engagement'] >= $report['top_posts'][1]['engagement'], 'ترتيب أفضل المنشورات تنازليًا');
$t->assertSame(20, $report['reposts']['total'], 'تغطية كل العيّنة في فحص إعادة النشر');
$t->assertBetween((float) $report['reposts']['ratio'], 0, 100, 'نسبة إعادة النشر ضمن المدى');
$t->assert(array_sum(array_column($report['content']['types'], 'count')) === 20, 'توزيع الأنواع يغطي العيّنة');
$t->assertThrows(NotFoundException::class, function () use ($checker, $detector): void {
    $empty = new ProviderChain([new class implements ProfileProvider {
        public function name(): string { return 'empty'; }
        public function isConfigured(): bool { return true; }
        public function fetchProfile(string $u): ?App\Instagram\DTO\Profile { return null; }
        public function fetchMedia(string $u, int $limit = 25): array { return []; }
        public function fetchComments(string $u, string $m, int $limit = 50): array { return []; }
        public function supportsComments(string $u): bool { return false; }
        public function usernameExists(string $u): ?bool { return false; }
    }]);
    (new AccountAnalyzer($empty, $detector, new UsernameChecker($empty), 25))->analyze('ghost', 5);
}, 'رمي NotFoundException لحساب غير موجود');

// ===== قياس السلوك =====
$t->group('BehaviorAnalyzer — قياس السلوك');
$behavior = new BehaviorAnalyzer($providers, $checker, $sentiment, $detector, 25, 3, 20);
$measured = $behavior->analyze('nasa', 20);
$t->assertSame(7, count($measured['timing']['heatmap']), 'خريطة النشاط تغطي 7 أيام');
$t->assertSame(24, count($measured['timing']['heatmap'][0]), 'كل يوم يغطي 24 ساعة');
$t->assertSame(20, array_sum(array_map('array_sum', $measured['timing']['heatmap'])), 'مجموع الخريطة يساوي عدد المنشورات');
foreach (['activity', 'consistency', 'engagement', 'responsiveness', 'originality', 'interactivity', 'overall'] as $key) {
    $t->assertBetween((float) $measured['scores'][$key], 0, 100, "الدرجة \"{$key}\" ضمن المدى 0–100");
}
$t->assert($measured['responses']['available'], 'توفّر بيانات الردود من المزوّد التجريبي');
$t->assertBetween((float) $measured['responses']['reply_rate'], 0, 100, 'نسبة الرد ضمن المدى');
$t->assert($measured['responses']['owner_replies'] <= $measured['responses']['total_comments'], 'الردود جزء من إجمالي التعليقات');
$t->assert(in_array($measured['writing']['tone']['label'], ['positive', 'negative', 'neutral'], true), 'تصنيف نبرة الكتابة');
$t->assert($measured['archetype']['label'] !== '', 'تحديد النمط السلوكي');

// مزوّد يغلّف التجريبي لكنه يمنع قراءة التعليقات، لمحاكاة غياب توكن Graph API.
$commentless = new class (new DemoProvider(true)) implements ProfileProvider {
    public function __construct(private DemoProvider $inner) {}
    public function name(): string { return 'demo_no_comments'; }
    public function isConfigured(): bool { return true; }
    public function fetchProfile(string $u): ?App\Instagram\DTO\Profile { return $this->inner->fetchProfile($u); }
    public function fetchMedia(string $u, int $limit = 25): array { return $this->inner->fetchMedia($u, $limit); }
    public function fetchComments(string $u, string $mediaId, int $limit = 50): array { return []; }
    public function supportsComments(string $u): bool { return false; }
    public function usernameExists(string $u): ?bool { return $this->inner->usernameExists($u); }
};

$noComments = new BehaviorAnalyzer(
    new ProviderChain([$commentless]),
    $checker,
    $sentiment,
    $detector
);
$withoutComments = $noComments->analyze('nasa', 10);
$t->assert(!$withoutComments['responses']['available'], 'الإفصاح عن تعذّر قراءة التعليقات');
$t->assertSame(0.0, $withoutComments['scores']['responsiveness'], 'تصفير درجة الاستجابة عند غياب البيانات');
$t->assert($withoutComments['warnings'] !== [], 'تسجيل تحذير عن القيد');

// ===== طبقة HTTP =====
$t->group('HTTP — الموجّه والاستجابة');
$router = (new Router())
    ->get('/ping', fn (): Response => Response::json(['ok' => true]))
    ->fallback(fn (): Response => Response::jsonError('غير موجود', 404));
$ok = $router->dispatch(new Request('GET', '/ping'));
$t->assertSame(200, $ok->status(), 'مسار مسجّل يُرجع 200');
$t->assertSame(404, $router->dispatch(new Request('GET', '/missing'))->status(), 'المسار غير المسجّل يُرجع 404');
$t->assertSame(404, $router->dispatch(new Request('POST', '/ping'))->status(), 'الطريقة غير المطابقة تُرجع 404');
$t->assert(str_contains($ok->headers()['Content-Type'], 'application/json'), 'نوع محتوى JSON');
$t->assert(str_contains(Response::json(['م' => 'نص'])->body(), 'نص'), 'إبقاء العربية بدون ترميز يونيكود');
$request = new Request('GET', '/api/test', ['username' => ' NASA ', 'limit' => '15']);
$t->assertSame('NASA', $request->input('username'), 'قراءة معامل مع إزالة الفراغات');
$t->assertSame(15, $request->intInput('limit', 25), 'قراءة معامل رقمي');
$t->assertSame(25, $request->intInput('missing', 25), 'القيمة الافتراضية للمعامل المفقود');
$t->assert($request->wantsJson(), 'كشف طلبات JSON من المسار');

array_map('unlink', glob($cacheDir . '/*') ?: []);
@rmdir($cacheDir);

exit($t->summary());
