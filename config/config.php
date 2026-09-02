<?php

declare(strict_types=1);

use App\Support\Env;

/**
 * إعدادات المنظومة. كل قيمة قابلة للتجاوز عبر ملف .env أو متغيرات البيئة.
 */
return [
    'app' => [
        'name' => Env::get('APP_NAME', 'منظومة تحليل إنستاجرام'),
        'debug' => Env::bool('APP_DEBUG', false),
        'timezone' => Env::get('APP_TIMEZONE', 'UTC'),
    ],

    'cache' => [
        'enabled' => Env::bool('CACHE_ENABLED', true),
        'path' => Env::get('CACHE_PATH', dirname(__DIR__) . '/storage/cache'),
        'ttl' => Env::int('CACHE_TTL', 900),
    ],

    'http' => [
        'timeout' => Env::int('HTTP_TIMEOUT', 12),
        'retries' => Env::int('HTTP_RETRIES', 2),
    ],

    'rate_limit' => [
        'enabled' => Env::bool('RATE_LIMIT_ENABLED', true),
        'max_requests' => Env::int('RATE_LIMIT_MAX', 30),
        'window_seconds' => Env::int('RATE_LIMIT_WINDOW', 60),
    ],

    'instagram' => [
        // المزوّد الرسمي: يتطلب توكن Graph API ومعرّف حساب إنستاجرام للأعمال.
        'graph' => [
            'access_token' => Env::get('IG_ACCESS_TOKEN', ''),
            'ig_user_id' => Env::get('IG_USER_ID', ''),
            'owner_username' => Env::get('IG_OWNER_USERNAME', ''),
            'api_version' => Env::get('IG_API_VERSION', 'v21.0'),
        ],

        // الواجهة العامة: بدون توكن، مناسبة للتحقق من توفّر الأسماء (أفضل جهد).
        'public_web' => [
            'enabled' => Env::bool('IG_PUBLIC_WEB_ENABLED', true),
        ],

        // وضع التجربة: بيانات مُصطنعة حتمية لتشغيل المنظومة بدون أي مفاتيح.
        'demo' => [
            'enabled' => Env::bool('IG_DEMO_ENABLED', true),
        ],
    ],

    'analysis' => [
        'default_media_limit' => Env::int('ANALYSIS_MEDIA_LIMIT', 25),
        'comment_posts_sample' => Env::int('ANALYSIS_COMMENT_POSTS', 5),
        'comments_per_post' => Env::int('ANALYSIS_COMMENTS_PER_POST', 50),
        'suggestion_limit' => Env::int('USERNAME_SUGGESTIONS', 6),
        'suggestion_checks' => Env::int('USERNAME_SUGGESTION_CHECKS', 4),
    ],
];
