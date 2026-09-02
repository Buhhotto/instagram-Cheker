<?php

declare(strict_types=1);

namespace App;

use App\Cache\CacheInterface;
use App\Cache\FileCache;
use App\Http\HttpClient;
use App\Http\RateLimiter;
use App\Instagram\AccountAnalyzer;
use App\Instagram\Analysis\RepostDetector;
use App\Instagram\Analysis\Sentiment;
use App\Instagram\BehaviorAnalyzer;
use App\Instagram\Providers\DemoProvider;
use App\Instagram\Providers\GraphApiProvider;
use App\Instagram\Providers\PublicWebProvider;
use App\Instagram\ProviderChain;
use App\Instagram\UsernameChecker;
use App\Support\Config;
use App\Support\Env;

/**
 * حاوية بسيطة تبني خدمات المنظومة وتحتفظ بنسخة واحدة من كل خدمة.
 */
final class Container
{
    /** @var array<string,object> */
    private array $instances = [];

    public function __construct(private Config $config)
    {
    }

    /** يفترض أن المحمّل التلقائي مُسجّل مسبقًا عبر bootstrap.php. */
    public static function boot(string $rootDir): self
    {
        Env::load($rootDir . '/.env');

        $config = Config::fromFile($rootDir . '/config/config.php');
        date_default_timezone_set($config->string('app.timezone', 'UTC'));

        return new self($config);
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function cache(): CacheInterface
    {
        return $this->instances['cache'] ??= new FileCache(
            $this->config->string('cache.path', __DIR__ . '/../storage/cache'),
            $this->config->bool('cache.enabled', true)
        );
    }

    public function http(): HttpClient
    {
        return $this->instances['http'] ??= new HttpClient(
            $this->config->int('http.timeout', 12),
            $this->config->int('http.retries', 2)
        );
    }

    public function rateLimiter(): RateLimiter
    {
        return $this->instances['rate_limiter'] ??= new RateLimiter(
            $this->cache(),
            $this->config->bool('rate_limit.enabled', true) ? $this->config->int('rate_limit.max_requests', 30) : 0,
            $this->config->int('rate_limit.window_seconds', 60)
        );
    }

    /** ترتيب المزوّدين: الرسمي أولًا، ثم العام، ثم التجريبي كملاذ أخير. */
    public function providers(): ProviderChain
    {
        return $this->instances['providers'] ??= new ProviderChain([
            new GraphApiProvider(
                $this->http(),
                $this->cache(),
                $this->config->string('instagram.graph.access_token'),
                $this->config->string('instagram.graph.ig_user_id'),
                $this->config->string('instagram.graph.owner_username'),
                $this->config->string('instagram.graph.api_version', 'v21.0'),
                $this->config->int('cache.ttl', 900)
            ),
            new PublicWebProvider(
                $this->http(),
                $this->cache(),
                $this->config->bool('instagram.public_web.enabled', true),
                $this->config->int('cache.ttl', 900)
            ),
            new DemoProvider(
                $this->config->bool('instagram.demo.enabled', true)
            ),
        ]);
    }

    public function usernameChecker(): UsernameChecker
    {
        return $this->instances['username_checker'] ??= new UsernameChecker(
            $this->providers(),
            $this->config->int('analysis.suggestion_limit', 6),
            $this->config->int('analysis.suggestion_checks', 4)
        );
    }

    public function sentiment(): Sentiment
    {
        return $this->instances['sentiment'] ??= new Sentiment();
    }

    public function repostDetector(): RepostDetector
    {
        return $this->instances['repost_detector'] ??= new RepostDetector();
    }

    public function accountAnalyzer(): AccountAnalyzer
    {
        return $this->instances['account_analyzer'] ??= new AccountAnalyzer(
            $this->providers(),
            $this->repostDetector(),
            $this->usernameChecker(),
            $this->config->int('analysis.default_media_limit', 25)
        );
    }

    public function behaviorAnalyzer(): BehaviorAnalyzer
    {
        return $this->instances['behavior_analyzer'] ??= new BehaviorAnalyzer(
            $this->providers(),
            $this->usernameChecker(),
            $this->sentiment(),
            $this->repostDetector(),
            $this->config->int('analysis.default_media_limit', 25),
            $this->config->int('analysis.comment_posts_sample', 5),
            $this->config->int('analysis.comments_per_post', 50)
        );
    }

    /** هل نعمل حاليًا على بيانات تجريبية فقط؟ */
    public function isDemoOnly(): bool
    {
        return $this->providers()->activeNames() === ['demo'];
    }
}
