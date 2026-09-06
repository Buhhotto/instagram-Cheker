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

    'watchlist' => [
        // قائمة مراقبة أسماء المستخدمين: تنبيه عند تحوّل الحالة، بدون أي تسجيل
        // دخول أو تغيير اسم آلي — راجع README لتفاصيل الحدود المتعمّدة.
        'storage_path' => Env::get('WATCHLIST_STORAGE_PATH', dirname(__DIR__) . '/storage/watchlist.json'),
        'max_entries' => Env::int('WATCHLIST_MAX_ENTRIES', 30),
    ],

    'analysis' => [
        'default_media_limit' => Env::int('ANALYSIS_MEDIA_LIMIT', 25),
        'comment_posts_sample' => Env::int('ANALYSIS_COMMENT_POSTS', 5),
        'comments_per_post' => Env::int('ANALYSIS_COMMENTS_PER_POST', 50),
        'suggestion_limit' => Env::int('USERNAME_SUGGESTIONS', 6),
        'suggestion_checks' => Env::int('USERNAME_SUGGESTION_CHECKS', 4),
    ],

    'property_alerts' => [
        // تنبيهات واتساب عند ظهور إعلان أرض جديد يطابق الفلاتر — راجع README
        // لتفاصيل القيد الأهم: مُحدِّدات الاستخراج (selectors) أدناه **يجب
        // ضبطها يدويًا** بفحص صفحة الموقع فعليًا؛ هذا المستودع لم يتمكن من
        // الوصول للموقع (حجب شبكي)، فلا توجد قيم افتراضية صحيحة يمكن تخمينها.
        'source' => [
            'base_url' => Env::get('PROPERTY_SOURCE_URL', 'https://omanreal.com/Properties'),
            // اسم معامل الاستعلام (query param) للولاية/المحافظة في رابط الموقع، مثل ?region=
            'location_param' => Env::get('PROPERTY_LOCATION_PARAM', ''),
            // اسم معامل الاستعلام لنوع العقار/الأرض، مثل ?type=
            'type_param' => Env::get('PROPERTY_TYPE_PARAM', ''),
        ],

        // مُحدِّدات XPath لاستخراج بيانات كل إعلان من صفحة النتائج. اتركها
        // فارغة = الفحص يفشل بخطأ واضح بدل إرجاع نتيجة فارغة يمكن أن تُقرأ
        // خطأً على أنها "لا توجد إعلانات جديدة".
        //
        // طريقة اكتشافها: افتح رابط الصفحة بعد تطبيق فلتر يدويًا من المتصفح،
        // ثم Developer Tools → Elements → انقر بيمين الفأرة على بطاقة إعلان
        // واحدة → Copy → Copy XPath، وكرّر لعنوان الإعلان ورابطه وموقعه وسعره.
        'selectors' => [
            'listing_item' => Env::get('PROPERTY_XPATH_ITEM', ''),   // XPath يطابق كل بطاقة إعلان
            'title' => Env::get('PROPERTY_XPATH_TITLE', '.'),        // XPath نسبي داخل البطاقة
            'url' => Env::get('PROPERTY_XPATH_URL', './/a/@href'),
            'location' => Env::get('PROPERTY_XPATH_LOCATION', '.'),
            'price' => Env::get('PROPERTY_XPATH_PRICE', '.'),
        ],

        // خريطة أنواع الأرض الداخلية ⇄ القيمة التي يتوقّعها الموقع في رابط
        // الفلترة. عدّلها بعد اكتشاف القيم الفعلية من نموذج الفلاتر بالموقع.
        'types' => [
            'agricultural' => Env::get('PROPERTY_TYPE_AGRICULTURAL', 'agricultural'),
            'residential' => Env::get('PROPERTY_TYPE_RESIDENTIAL', 'residential'),
            'industrial' => Env::get('PROPERTY_TYPE_INDUSTRIAL', 'industrial'),
            'commercial' => Env::get('PROPERTY_TYPE_COMMERCIAL', 'commercial'),
        ],

        'whatsapp' => [
            // WhatsApp Cloud API الرسمي من Meta: https://developers.facebook.com/docs/whatsapp/cloud-api
            'enabled' => Env::bool('WHATSAPP_ENABLED', false),
            'access_token' => Env::get('WHATSAPP_ACCESS_TOKEN', ''),
            'phone_number_id' => Env::get('WHATSAPP_PHONE_NUMBER_ID', ''),
            'api_version' => Env::get('WHATSAPP_API_VERSION', 'v21.0'),
        ],

        'storage_path' => Env::get('PROPERTY_ALERTS_STORAGE_PATH', dirname(__DIR__) . '/storage/property_alerts.json'),
        'max_entries' => Env::int('PROPERTY_ALERTS_MAX_ENTRIES', 50),
        // أقصى عدد رسائل واتساب تُرسل في دورة فحص واحدة لكل اشتراك، لمنع إغراق
        // الرقم برسائل عند أول فحص أو عند تغيّر جذري في نتائج الموقع.
        'max_notifications_per_run' => Env::int('PROPERTY_ALERTS_MAX_NOTIFY', 5),
    ],
];
