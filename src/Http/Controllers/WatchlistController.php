<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Instagram\Exception\InstagramException;
use App\Watchlist\WatchedUsername;
use Throwable;

/**
 * قائمة مراقبة أسماء المستخدمين: تنبيه عند تحوّل حالة اسم، وليست حجزًا أو
 * نقلًا فعليًا — لا يوجد أي تسجيل دخول أو تغيير اسم آلي هنا، فعل التسجيل
 * يبقى بيد صاحب القرار داخل تطبيق إنستاجرام نفسه. راجع README لتفاصيل السبب.
 */
final class WatchlistController extends Controller
{
    /** @var array<string,array{0:string,1:string}> رمز → [نوع التنبيه، نص عربي ثابت] */
    private const FLASH_MESSAGES = [
        'added' => ['ok', 'أُضيف الاسم إلى قائمة المراقبة، وتم فحصه الآن.'],
        'exists' => ['warn', 'هذا الاسم موجود بالفعل في القائمة.'],
        'invalid' => ['error', 'اسم المستخدم غير صالح أو محجوز، لا يمكن مراقبته.'],
        'webhook_invalid' => ['error', 'رابط الـ webhook غير صالح، أو يشير إلى عنوان داخلي غير مسموح به.'],
        'limit_reached' => ['error', 'وصلت قائمة المراقبة إلى الحد الأقصى لعدد الأسماء.'],
        'removed' => ['ok', 'أُزيل الاسم من القائمة.'],
        'checked' => ['ok', 'تم تحديث الحالة الآن.'],
        'check_failed' => ['error', 'تعذّر إجراء الفحص الآن، حاول لاحقًا.'],
    ];

    public function page(Request $request): Response
    {
        $data = $this->pageContext('watchlist') + [
            'entries' => $this->container->watchlistRepository()->all(),
            'maxEntries' => $this->container->config()->int('watchlist.max_entries', 30),
            'flash' => self::FLASH_MESSAGES[(string) $request->input('flash', '')] ?? null,
        ];

        return Response::html($this->view->render('pages/watchlist', $data));
    }

    public function add(Request $request): Response
    {
        try {
            $this->throttle($request, 'watchlist_write');
        } catch (InstagramException) {
            return $this->redirectFlash('check_failed');
        }

        $result = $this->tryAdd(
            (string) $request->input('username', ''),
            (string) $request->input('webhook_url', '')
        );

        return $this->redirectFlash($result['code']);
    }

    public function remove(Request $request): Response
    {
        $this->container->watchlistRepository()->remove((string) $request->input('username', ''));

        return $this->redirectFlash('removed');
    }

    public function checkOneWeb(Request $request): Response
    {
        try {
            $this->throttle($request, 'watchlist_check');
            $this->container->watchlistChecker()->checkOne((string) $request->input('username', ''));

            return $this->redirectFlash('checked');
        } catch (Throwable) {
            return $this->redirectFlash('check_failed');
        }
    }

    public function checkAllWeb(Request $request): Response
    {
        try {
            $this->throttle($request, 'watchlist_check_all');
            $this->container->watchlistChecker()->checkAll();

            return $this->redirectFlash('checked');
        } catch (Throwable) {
            return $this->redirectFlash('check_failed');
        }
    }

    // ==== API ====

    public function apiList(Request $request): Response
    {
        return Response::json([
            'ok' => true,
            'data' => array_map(
                static fn (WatchedUsername $e): array => $e->jsonSerialize(),
                $this->container->watchlistRepository()->all()
            ),
        ]);
    }

    public function apiAdd(Request $request): Response
    {
        try {
            $this->throttle($request, 'watchlist_write');
        } catch (InstagramException $e) {
            return Response::jsonError($e->getMessage(), 429);
        }

        $result = $this->tryAdd(
            (string) $request->input('username', ''),
            (string) $request->input('webhook_url', '')
        );

        if (!$result['ok']) {
            return Response::jsonError(self::FLASH_MESSAGES[$result['code']][1], 422);
        }

        return Response::json(['ok' => true, 'data' => $result['entry']->jsonSerialize()], 201);
    }

    public function apiRemove(Request $request): Response
    {
        $removed = $this->container->watchlistRepository()->remove((string) $request->input('username', ''));

        return $removed
            ? Response::json(['ok' => true])
            : Response::jsonError('الاسم غير موجود في القائمة.', 404);
    }

    public function apiCheck(Request $request): Response
    {
        try {
            $this->throttle($request, 'watchlist_check');
        } catch (InstagramException $e) {
            return Response::jsonError($e->getMessage(), 429);
        }

        $username = (string) $request->input('username', '');

        try {
            if ($username !== '') {
                return Response::json(['ok' => true, 'data' => $this->container->watchlistChecker()->checkOne($username)]);
            }

            return Response::json(['ok' => true, 'data' => $this->container->watchlistChecker()->checkAll()]);
        } catch (Throwable $e) {
            return Response::jsonError($e->getMessage(), 404);
        }
    }

    /**
     * منطق الإضافة المشترك بين نموذج الويب وواجهة الـ API.
     *
     * @return array{ok:bool,code:string,entry?:WatchedUsername}
     */
    private function tryAdd(string $rawUsername, string $webhookUrl): array
    {
        $checker = $this->container->usernameChecker();

        try {
            $username = $checker->normalize($rawUsername);
        } catch (InstagramException) {
            return ['ok' => false, 'code' => 'invalid'];
        }

        $validation = $checker->validate($username);
        if (!$validation['valid'] || $validation['reserved']) {
            return ['ok' => false, 'code' => 'invalid'];
        }

        $webhookUrl = trim($webhookUrl);
        if ($webhookUrl !== '' && !$this->container->webhookNotifier()->isSafeUrl($webhookUrl)) {
            return ['ok' => false, 'code' => 'webhook_invalid'];
        }

        $repo = $this->container->watchlistRepository();

        if ($repo->find($username) !== null) {
            return ['ok' => false, 'code' => 'exists'];
        }

        if ($repo->count() >= $this->container->config()->int('watchlist.max_entries', 30)) {
            return ['ok' => false, 'code' => 'limit_reached'];
        }

        $repo->add(new WatchedUsername($username, $webhookUrl !== '' ? $webhookUrl : null, date(DATE_ATOM)));

        // فحص أولي فوري حتى تظهر الحالة مباشرة دون انتظار دورة الـ cron التالية.
        try {
            $this->container->watchlistChecker()->checkOne($username);
        } catch (Throwable) {
            // تجاهل فشل الفحص الأولي؛ سيُعاد المحاولة في الدورة التالية.
        }

        return ['ok' => true, 'code' => 'added', 'entry' => $repo->find($username)];
    }

    private function redirectFlash(string $code): Response
    {
        return Response::redirect('/watchlist?flash=' . rawurlencode($code));
    }
}
