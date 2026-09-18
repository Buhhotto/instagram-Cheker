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
use App\PropertyAlerts\Contracts\Notifier as PropertyNotifier;
use App\PropertyAlerts\Contracts\Scraper;
use App\PropertyAlerts\DTO\Listing;
use App\PropertyAlerts\Exception\ScraperConfigurationException;
use App\PropertyAlerts\PropertyAlertChecker;
use App\PropertyAlerts\PropertySource;
use App\PropertyAlerts\PropertySubscription;
use App\PropertyAlerts\PropertySubscriptionRepository;
use App\PropertyAlerts\PropertyType;
use App\PropertyAlerts\Scraper\OmanRealScraper;
use App\PropertyAlerts\Scraper\OpenSooqScraper;
use App\PropertyAlerts\WhatsAppCloudNotifier;
use App\Watchlist\Contracts\Notifier;
use App\Watchlist\WatchedUsername;
use App\Watchlist\WatchlistChecker;
use App\Watchlist\WatchlistRepository;
use App\Watchlist\WebhookNotifier;
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

// ===== قائمة المراقبة: نموذج البيانات والتخزين =====
$t->group('WatchedUsername — نموذج البيانات');
$entry = WatchedUsername::fromArray([
    'username' => 'Example',
    'webhook_url' => 'https://example.test/hook',
    'added_at' => '2026-01-01T00:00:00+00:00',
    'last_status' => 'taken',
    'check_count' => 3,
]);
$t->assertSame('example', $entry->username, 'توحيد حروف الاسم إلى صغيرة عند القراءة');
$t->assertSame('https://www.instagram.com/example/', $entry->profileUrl(), 'بناء رابط الملف الشخصي');
$t->assertSame(3, $entry->jsonSerialize()['check_count'], 'الحفاظ على عدد مرات الفحص عبر jsonSerialize');
$noWebhook = WatchedUsername::fromArray(['username' => 'x', 'webhook_url' => '']);
$t->assertSame(null, $noWebhook->webhookUrl, 'سلسلة فارغة لرابط الـ webhook تُعامل كغياب القيمة');

$t->group('WatchlistRepository — التخزين والقفل الملفي');
$watchlistFile = sys_get_temp_dir() . '/ig_watchlist_test_' . bin2hex(random_bytes(4)) . '.json';
$repo = new WatchlistRepository($watchlistFile);
$t->assertSame([], $repo->all(), 'قائمة فارغة عند الإنشاء');
$repo->add(new WatchedUsername('nasa', null, date(DATE_ATOM)));
$t->assertSame(1, $repo->count(), 'إضافة إدخال واحد');
$repo->add(new WatchedUsername('nasa', null, date(DATE_ATOM)));
$t->assertSame(1, $repo->count(), 'عدم تكرار نفس الاسم عند الإضافة مرتين');
$t->assert($repo->find('nasa') !== null, 'العثور على إدخال موجود');
$t->assert($repo->find('missing') === null, 'عدم العثور على إدخال غير موجود');
$repo->update('nasa', function (WatchedUsername $e): WatchedUsername {
    $e->lastStatus = 'available';

    return $e;
});
$t->assertSame('available', $repo->find('nasa')?->lastStatus, 'تحديث حالة إدخال موجود');
$reopened = new WatchlistRepository($watchlistFile);
$t->assertSame('available', $reopened->find('nasa')?->lastStatus, 'استمرار البيانات عبر فتح جديد لنفس الملف');
$t->assert($repo->remove('nasa'), 'إزالة إدخال موجود تُرجع true');
$t->assert(!$repo->remove('nasa'), 'إزالة إدخال محذوف مسبقًا تُرجع false');
$t->assertSame(0, $repo->count(), 'القائمة فارغة بعد الحذف');
@unlink($watchlistFile);

// ===== حماية الـ webhook من SSRF =====
$t->group('WebhookNotifier — رفض عناوين webhook غير آمنة');
$notifier = new WebhookNotifier(new App\Http\HttpClient(2, 0));
$t->assert(!$notifier->isSafeUrl('ftp://example.test/hook'), 'رفض بروتوكول غير HTTP(S)');
$t->assert(!$notifier->isSafeUrl('ليس رابطًا'), 'رفض نص ليس رابطًا');
$t->assert(!$notifier->isSafeUrl('http://127.0.0.1/hook'), 'رفض loopback');
$t->assert(!$notifier->isSafeUrl('http://localhost:8080/hook'), 'رفض اسم المضيف localhost');
$t->assert(!$notifier->isSafeUrl('http://10.1.2.3/hook'), 'رفض نطاق IP خاص (10.0.0.0/8)');
$t->assert(!$notifier->isSafeUrl('http://169.254.169.254/latest/meta-data'), 'رفض نطاق link-local (بيانات تعريف السحابة)');
$t->assert(!$notifier->isSafeUrl('http://this-host-should-not-exist-zzz123.invalid/hook'), 'رفض مضيف لا يُحلّ إلى أي عنوان');
$t->assert($notifier->isSafeUrl('http://8.8.8.8/hook'), 'قبول عنوان IP عام صريح');

// ===== قائمة المراقبة: منطق الفحص والتنبيه =====
$t->group('WatchlistChecker — تحوّل الحالة والتنبيه');

/** مزوّد وهمي يُرجع حالة وجود قابلة للتبديل يدويًا، لمحاكاة تحوّل حقيقي بين فحصين. */
$flipState = new class { public bool $exists = true; };
$flippableProvider = new class ($flipState) implements ProfileProvider {
    public function __construct(private object $state)
    {
    }
    public function name(): string { return 'fake_source'; }
    public function isConfigured(): bool { return true; }
    public function fetchProfile(string $u): ?App\Instagram\DTO\Profile { return null; }
    public function fetchMedia(string $u, int $limit = 25): array { return []; }
    public function fetchComments(string $u, string $m, int $limit = 50): array { return []; }
    public function supportsComments(string $u): bool { return false; }
    public function usernameExists(string $u): ?bool { return $this->state->exists; }
};

/** يسجّل كل نداء بدل إرسال طلب شبكة حقيقي. */
$recorder = new class implements Notifier {
    /** @var list<array{url:string,payload:array<string,mixed>}> */
    public array $calls = [];
    public function send(string $url, array $payload): bool
    {
        $this->calls[] = ['url' => $url, 'payload' => $payload];

        return true;
    }
};

$flipChain = new ProviderChain([$flippableProvider]);
$flipChecker = new UsernameChecker($flipChain);
$flipFile = sys_get_temp_dir() . '/ig_watchlist_flip_' . bin2hex(random_bytes(4)) . '.json';
$flipRepo = new WatchlistRepository($flipFile);
$flipRepo->add(new WatchedUsername('flip_test_user', 'https://example.test/webhook', date(DATE_ATOM)));
$flipWatcher = new WatchlistChecker($flipRepo, $flipChecker, $recorder);

$flipState->exists = true;
$first = $flipWatcher->checkOne('flip_test_user');
$t->assertSame('taken', $first['status'], 'الفحص الأول يعكس حالة المزوّد');
$t->assertSame(0, count($recorder->calls), 'لا تنبيه عند أول فحص (لا حالة سابقة للمقارنة)');

$flipState->exists = false;
$second = $flipWatcher->checkOne('flip_test_user');
$t->assertSame('available', $second['status'], 'الفحص الثاني يعكس التحوّل إلى متاح');
$t->assertSame(1, count($recorder->calls), 'تنبيه واحد عند تحوّل واثق للحالة');
$t->assertSame('username_available', $recorder->calls[0]['payload']['event'], 'حدث التنبيه الأول: أصبح متاحًا');
$t->assertSame('https://example.test/webhook', $recorder->calls[0]['url'], 'إرسال التنبيه إلى رابط الإدخال');

$flipState->exists = true;
$flipWatcher->checkOne('flip_test_user');
$t->assertSame(2, count($recorder->calls), 'تنبيه ثانٍ عند رجوع الاسم لحالة مستخدم');
$t->assertSame('username_taken', $recorder->calls[1]['payload']['event'], 'حدث التنبيه الثاني: أصبح مستخدمًا مجددًا');
@unlink($flipFile);

$t->group('WatchlistChecker — كتم تنبيهات وضع التجربة');
$demoRecorder = new class implements Notifier {
    public int $callCount = 0;
    public function send(string $url, array $payload): bool
    {
        $this->callCount++;

        return true;
    }
};
$demoFile = sys_get_temp_dir() . '/ig_watchlist_demo_' . bin2hex(random_bytes(4)) . '.json';
$demoRepo = new WatchlistRepository($demoFile);
$demoRepo->add(new WatchedUsername('nasa', 'https://example.test/webhook', date(DATE_ATOM)));
// نُجبر حالة سابقة مغايرة لما سيُرجعه DemoProvider يدويًا (nasa دائمًا "مستخدم" فيه)
// لمحاكاة تحوّل واثق ظاهريًا، والتحقق من أن مصدر demo يكتم التنبيه رغم ذلك.
$demoRepo->update('nasa', function (WatchedUsername $e): WatchedUsername {
    $e->lastStatus = 'available';

    return $e;
});
$demoWatcher = new WatchlistChecker($demoRepo, $checker, $demoRecorder);
$demoResult = $demoWatcher->checkOne('nasa');
$t->assertSame('taken', $demoResult['status'], 'DemoProvider يُرجع nasa كاسم مستخدم دائمًا');
$t->assertSame('demo', $demoResult['source'], 'مصدر النتيجة demo كما هو متوقّع');
$t->assertSame(0, $demoRecorder->callCount, 'كتم التنبيه عند مصدر demo رغم وجود تحوّل ظاهري');
@unlink($demoFile);

$t->group('WatchlistChecker — checkAll يغطي كل القائمة');
$bulkFile = sys_get_temp_dir() . '/ig_watchlist_bulk_' . bin2hex(random_bytes(4)) . '.json';
$bulkRepo = new WatchlistRepository($bulkFile);
$bulkRepo->add(new WatchedUsername('nasa', null, date(DATE_ATOM)));
$bulkRepo->add(new WatchedUsername('some_free_name_42', null, date(DATE_ATOM)));
$bulkWatcher = new WatchlistChecker($bulkRepo, $checker, $demoRecorder);
$bulkResults = $bulkWatcher->checkAll();
$t->assertSame(2, count($bulkResults), 'نتيجة لكل اسم في القائمة');
$t->assert(isset($bulkResults['nasa']['status']), 'كل نتيجة تحمل حالة');
@unlink($bulkFile);

// ===== تنبيهات عقارية =====
$t->group('PropertyType — الأنواع والتسميات');
$t->assert(PropertyType::isValid('agricultural'), 'agricultural نوع صالح');
$t->assert(!PropertyType::isValid('bogus'), 'نوع غير معروف مرفوض');
$t->assertSame('زراعي', PropertyType::label('agricultural'), 'تسمية عربية للنوع الزراعي');

$t->group('PropertySource — المصادر المدعومة');
$t->assert(PropertySource::isValid('omanreal'), 'omanreal مصدر صالح');
$t->assert(PropertySource::isValid('opensooq'), 'opensooq مصدر صالح');
$t->assert(!PropertySource::isValid('bogus'), 'مصدر غير معروف مرفوض');
$t->assertSame('omanreal', PropertySource::DEFAULT, 'المصدر الافتراضي هو omanreal (للتوافق مع اشتراكات سابقة)');

$t->group('Listing::makeId — كشف التكرار');
$t->assertSame(
    Listing::makeId('https://x.test/a', 'عنوان مختلف', null, null),
    Listing::makeId('https://x.test/a', 'عنوان آخر تمامًا', 'مسقط', '10000'),
    'نفس الرابط ⇒ نفس المعرّف بغض النظر عن باقي الحقول'
);
$t->assert(
    Listing::makeId(null, 'عنوان', 'مسقط', '1000') !== Listing::makeId(null, 'عنوان', 'صلالة', '1000'),
    'بلا رابط: تغيّر الموقع يُغيّر بصمة المعرّف'
);

$t->group('PropertySubscriptionRepository — التخزين والقفل الملفي');
$subFile = sys_get_temp_dir() . '/property_alerts_test_' . bin2hex(random_bytes(4)) . '.json';
$subRepo = new PropertySubscriptionRepository($subFile);
$t->assertSame([], $subRepo->all(), 'قائمة فارغة عند الإنشاء');
$sub = $subRepo->add(new PropertySubscription('sub1', '96879000000', 'agricultural', 'مسقط', date(DATE_ATOM)));
$t->assertSame(1, $subRepo->count(), 'إضافة اشتراك واحد');
$t->assertSame('sub1', $subRepo->find('sub1')?->id, 'العثور على اشتراك موجود');
$subRepo->update('sub1', function (PropertySubscription $e): PropertySubscription {
    $e->seenListingIds = ['a', 'b'];

    return $e;
});
$t->assertSame(['a', 'b'], $subRepo->find('sub1')?->seenListingIds, 'تحديث قائمة الإعلانات المرصودة');
$reopenedSubs = new PropertySubscriptionRepository($subFile);
$t->assertSame(['a', 'b'], $reopenedSubs->find('sub1')?->seenListingIds, 'استمرار البيانات عبر فتح جديد لنفس الملف');
$t->assert($subRepo->remove('sub1'), 'إزالة اشتراك موجود تُرجع true');
$t->assertSame(0, $subRepo->count(), 'القائمة فارغة بعد الحذف');
@unlink($subFile);

$t->group('PropertySubscription::fromArray — التوافق مع اشتراكات قبل تعدّد المصادر');
$legacyEntry = PropertySubscription::fromArray(['id' => 'old1', 'whatsapp_number' => '96879000000']);
$t->assertSame('omanreal', $legacyEntry->source, 'اشتراك محفوظ بلا حقل source يُفترض أنه omanreal');
$opensooqEntry = PropertySubscription::fromArray(['id' => 'old3', 'whatsapp_number' => '96879000000', 'source' => 'opensooq']);
$t->assertSame('opensooq', $opensooqEntry->source, 'قيمة source صالحة تُقرأ كما هي');
// قيمة موجودة فعليًا في التخزين تبقى كما هي حتى لو لم تعد مصدرًا مدعومًا — نفس
// معاملة propertyType وlocation، بدل استبدال صامت قد يوجّه الفحص لمصدر خطأ
// (راجع اختبار PropertyAlertChecker أدناه لسلوك الخطأ الواضح عند الفحص الفعلي).
$staleSourceEntry = PropertySubscription::fromArray(['id' => 'old2', 'whatsapp_number' => '96879000000', 'source' => 'discontinued_source']);
$t->assertSame('discontinued_source', $staleSourceEntry->source, 'قيمة source غير مدعومة حاليًا تُقرأ كما هي، بلا استبدال صامت');

$t->group('WhatsAppCloudNotifier — التحقق من صيغة الرقم');
$waNotifier = new WhatsAppCloudNotifier(new App\Http\HttpClient(2, 0), false, '', '');
$t->assert($waNotifier->isValidRecipient('96879123456'), 'قبول رقم دولي صالح بدون رموز');
$t->assert(!$waNotifier->isValidRecipient('+96879123456'), 'رفض رقم يحتوي على +');
$t->assert(!$waNotifier->isValidRecipient('0079123456'), 'رفض رقم يبدأ بصفر');
$t->assert(!$waNotifier->isValidRecipient('123'), 'رفض رقم قصير جدًا');
$t->assert(!$waNotifier->send('96879123456', 'رسالة'), 'send تُرجع false عندما تكون الخدمة غير مفعّلة (enabled=false)');

$t->group('OmanRealScraper — رفض العمل بلا مُحدِّدات مضبوطة');
$unconfiguredScraper = new OmanRealScraper(
    new App\Http\HttpClient(2, 0),
    ['base_url' => 'https://omanreal.com/Properties', 'location_param' => '', 'type_param' => ''],
    ['listing_item' => '', 'title' => '.', 'url' => './/a/@href', 'location' => '.', 'price' => '.'],
    []
);
$t->assertThrows(
    ScraperConfigurationException::class,
    fn () => $unconfiguredScraper->search(null, null),
    'يرمي استثناء واضح بدل إرجاع نتيجة فارغة عند عدم ضبط listing_item'
);

$t->group('OpenSooqScraper — نفس السلوك، مصدر مختلف');
$unconfiguredOpenSooq = new OpenSooqScraper(
    new App\Http\HttpClient(2, 0),
    ['base_url' => 'https://om.opensooq.com/ar/عقارات-للبيع', 'location_param' => '', 'type_param' => ''],
    ['listing_item' => '', 'title' => '.', 'url' => './/a/@href', 'location' => '.', 'price' => '.'],
    []
);
$t->assertThrows(
    ScraperConfigurationException::class,
    fn () => $unconfiguredOpenSooq->search(null, null),
    'يرمي استثناء واضح بدل إرجاع نتيجة فارغة عند عدم ضبط listing_item (OpenSooq أيضًا)'
);

$t->group('PropertyAlertChecker — الفحص الأول يسجّل خط أساس بلا تنبيه');

/** كاشف وهمي يُرجع قائمة إعلانات ثابتة قابلة للتبديل، بدل طلب شبكة حقيقي. */
$scraperState = new class { /** @var list<Listing> */ public array $listings = []; };
$fakeScraper = new class ($scraperState) implements Scraper {
    public function __construct(private object $state)
    {
    }
    public function search(?string $propertyType, ?string $location): array
    {
        return $this->state->listings;
    }
};

$waRecorder = new class implements PropertyNotifier {
    /** @var list<array{to:string,message:string}> */
    public array $calls = [];
    public function send(string $toPhone, string $message): bool
    {
        $this->calls[] = ['to' => $toPhone, 'message' => $message];

        return true;
    }
};

$paFile = sys_get_temp_dir() . '/property_alerts_flow_' . bin2hex(random_bytes(4)) . '.json';
$paRepo = new PropertySubscriptionRepository($paFile);
$paRepo->add(new PropertySubscription('sub1', '96879000000', 'agricultural', 'مسقط', date(DATE_ATOM)));
$paChecker = new PropertyAlertChecker($paRepo, [PropertySource::OMANREAL => $fakeScraper], $waRecorder, 5);

$scraperState->listings = [
    new Listing(Listing::makeId('https://x.test/1', 'أرض 1', 'مسقط', '10000'), 'أرض 1', 'https://x.test/1', 'مسقط', '10000'),
    new Listing(Listing::makeId('https://x.test/2', 'أرض 2', 'مسقط', '12000'), 'أرض 2', 'https://x.test/2', 'مسقط', '12000'),
];
$firstRun = $paChecker->checkOne('sub1');
$t->assertSame(2, $firstRun['new_listings'], 'الفحص الأول يعتبر كل النتائج جديدة من ناحية العدّ');
$t->assertSame(0, $firstRun['notified'], 'لكن لا يُرسل أي تنبيه عند الفحص الأول (خط أساس فقط)');
$t->assertSame(0, count($waRecorder->calls), 'لا نداءات إرسال فعلية عند الفحص الأول');
$t->assertSame(2, count($paRepo->find('sub1')?->seenListingIds ?? []), 'حفظ الإعلانات كخط أساس');

$scraperState->listings[] = new Listing(
    Listing::makeId('https://x.test/3', 'أرض 3', 'مسقط', '9000'),
    'أرض 3',
    'https://x.test/3',
    'مسقط',
    '9000'
);
$secondRun = $paChecker->checkOne('sub1');
$t->assertSame(1, $secondRun['new_listings'], 'الفحص الثاني يكتشف إعلانًا جديدًا واحدًا فقط');
$t->assertSame(1, $secondRun['notified'], 'يُرسل تنبيه واحد للإعلان الجديد');
$t->assertSame(1, count($waRecorder->calls), 'نداء إرسال واحد فعليًا');
$t->assertSame('96879000000', $waRecorder->calls[0]['to'], 'الإرسال إلى رقم الاشتراك');
$t->assert(str_contains($waRecorder->calls[0]['message'], 'أرض 3'), 'نص الرسالة يذكر عنوان الإعلان الجديد');

$thirdRun = $paChecker->checkOne('sub1');
$t->assertSame(0, $thirdRun['new_listings'], 'لا إعلانات جديدة عند عدم تغيّر النتائج');
$t->assertSame(1, count($waRecorder->calls), 'لا تنبيه إضافي بلا إعلانات جديدة فعليًا');
@unlink($paFile);

$t->group('PropertyAlertChecker — يختار الكاشف الصحيح حسب مصدر كل اشتراك');

/** كاشف وهمي ثانٍ منفصل، ليتحقق الاختبار من عدم خلط الاشتراكات بين المصدرين. */
$openSooqState = new class { /** @var list<Listing> */ public array $listings = []; };
$fakeOpenSooqScraper = new class ($openSooqState) implements Scraper {
    public function __construct(private object $state)
    {
    }
    public function search(?string $propertyType, ?string $location): array
    {
        return $this->state->listings;
    }
};
$openSooqState->listings = [
    new Listing(Listing::makeId('https://opensooq.test/1', 'أرض سوق مفتوح', 'صلالة', '5000'), 'أرض سوق مفتوح', 'https://opensooq.test/1', 'صلالة', '5000'),
];

$multiFile = sys_get_temp_dir() . '/property_alerts_multi_' . bin2hex(random_bytes(4)) . '.json';
$multiRepo = new PropertySubscriptionRepository($multiFile);
$multiRepo->add(new PropertySubscription('om1', '96879000001', null, null, date(DATE_ATOM), source: PropertySource::OMANREAL));
$multiRepo->add(new PropertySubscription('os1', '96879000002', null, null, date(DATE_ATOM), source: PropertySource::OPENSOOQ));
$multiRepo->add(new PropertySubscription('bad1', '96879000003', null, null, date(DATE_ATOM), source: 'unsupported_source'));

$multiChecker = new PropertyAlertChecker(
    $multiRepo,
    [PropertySource::OMANREAL => $fakeScraper, PropertySource::OPENSOOQ => $fakeOpenSooqScraper],
    $waRecorder,
    5
);

$scraperState->listings = [
    new Listing(Listing::makeId('https://x.test/1', 'أرض 1', 'مسقط', '10000'), 'أرض 1', 'https://x.test/1', 'مسقط', '10000'),
];
$multiChecker->checkOne('om1');
$t->assertSame(1, count($multiRepo->find('om1')?->seenListingIds ?? []), 'اشتراك omanreal يستخدم كاشف omanreal (إعلان واحد من قائمته)');

$multiChecker->checkOne('os1');
$t->assertSame(1, count($multiRepo->find('os1')?->seenListingIds ?? []), 'اشتراك opensooq يستخدم كاشف opensooq وليس كاشف omanreal');
$osEntry = $multiRepo->find('os1');
$t->assert(
    $osEntry !== null && in_array(Listing::makeId('https://opensooq.test/1', 'أرض سوق مفتوح', 'صلالة', '5000'), $osEntry->seenListingIds, true),
    'المعرّف المحفوظ لاشتراك opensooq هو فعلًا من نتائج كاشف opensooq'
);

$badResult = $multiChecker->checkOne('bad1');
$t->assertSame(false, $badResult['ok'], 'اشتراك بمصدر غير مدعوم في المسجّل يفشل بوضوح');
$t->assert(str_contains((string) ($badResult['error'] ?? ''), 'unsupported_source'), 'رسالة الخطأ تذكر المصدر غير المدعوم');
@unlink($multiFile);

array_map('unlink', glob($cacheDir . '/*') ?: []);
@rmdir($cacheDir);

exit($t->summary());
