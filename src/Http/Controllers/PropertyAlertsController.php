<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Instagram\Exception\RateLimitException;
use App\PropertyAlerts\PropertySubscription;
use App\PropertyAlerts\PropertyType;
use Throwable;

/**
 * تنبيهات واتساب عند ظهور إعلان أرض جديد على omanreal.com يطابق نوعًا
 * ومنطقة مختارة. راجع README لقيد مهم: مُحدِّدات استخراج الإعلانات من
 * الموقع يجب ضبطها يدويًا قبل أن يعمل الفحص فعليًا — هذا المستودع طُوّر
 * بلا وصول شبكي لذلك الموقع.
 */
final class PropertyAlertsController extends Controller
{
    /** @var array<string,array{0:string,1:string}> */
    private const FLASH_MESSAGES = [
        'added' => ['ok', 'أُضيف الاشتراك، وسيُسجَّل خط الأساس من الإعلانات الحالية عند أول فحص.'],
        'invalid_number' => ['error', 'رقم واتساب غير صالح — أدخله بصيغة دولية بدون + أو مسافات (مثال: 96879xxxxxx).'],
        'invalid_type' => ['error', 'نوع الأرض غير معروف.'],
        'limit_reached' => ['error', 'وصلت قائمة الاشتراكات إلى الحد الأقصى.'],
        'removed' => ['ok', 'أُزيل الاشتراك.'],
        'checked' => ['ok', 'تم الفحص الآن.'],
        'check_failed' => ['error', 'تعذّر إجراء الفحص الآن — راجع رسالة الخطأ أسفل الاشتراك.'],
    ];

    public function page(Request $request): Response
    {
        $data = $this->pageContext('property-alerts') + [
            'entries' => $this->container->propertySubscriptionRepository()->all(),
            'maxEntries' => $this->container->config()->int('property_alerts.max_entries', 50),
            'types' => PropertyType::labels(),
            'whatsappEnabled' => $this->container->config()->bool('property_alerts.whatsapp.enabled', false),
            'selectorsConfigured' => trim($this->container->config()->string('property_alerts.selectors.listing_item')) !== '',
            'flash' => self::FLASH_MESSAGES[(string) $request->input('flash', '')] ?? null,
        ];

        return Response::html($this->view->render('pages/property-alerts', $data));
    }

    public function add(Request $request): Response
    {
        $result = $this->tryAdd($request);

        return $this->redirectFlash($result['code']);
    }

    public function remove(Request $request): Response
    {
        $this->container->propertySubscriptionRepository()->remove((string) $request->input('id', ''));

        return $this->redirectFlash('removed');
    }

    public function checkOneWeb(Request $request): Response
    {
        try {
            $this->throttle($request, 'property_alerts_check');
            $result = $this->container->propertyAlertChecker()->checkOne((string) $request->input('id', ''));

            return $this->redirectFlash($result['ok'] ? 'checked' : 'check_failed');
        } catch (Throwable) {
            return $this->redirectFlash('check_failed');
        }
    }

    public function checkAllWeb(Request $request): Response
    {
        try {
            $this->throttle($request, 'property_alerts_check_all');
            $this->container->propertyAlertChecker()->checkAll();

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
                static fn (PropertySubscription $e): array => $e->jsonSerialize(),
                $this->container->propertySubscriptionRepository()->all()
            ),
        ]);
    }

    public function apiAdd(Request $request): Response
    {
        $result = $this->tryAdd($request);

        if (!$result['ok']) {
            return Response::jsonError(self::FLASH_MESSAGES[$result['code']][1], 422);
        }

        return Response::json(['ok' => true, 'data' => $result['entry']->jsonSerialize()], 201);
    }

    public function apiRemove(Request $request): Response
    {
        $removed = $this->container->propertySubscriptionRepository()->remove((string) $request->input('id', ''));

        return $removed
            ? Response::json(['ok' => true])
            : Response::jsonError('الاشتراك غير موجود.', 404);
    }

    public function apiCheck(Request $request): Response
    {
        try {
            $this->throttle($request, 'property_alerts_check');
        } catch (RateLimitException $e) {
            return Response::jsonError($e->getMessage(), 429);
        }

        $id = (string) $request->input('id', '');

        try {
            if ($id !== '') {
                return Response::json(['ok' => true, 'data' => $this->container->propertyAlertChecker()->checkOne($id)]);
            }

            return Response::json(['ok' => true, 'data' => $this->container->propertyAlertChecker()->checkAll()]);
        } catch (Throwable $e) {
            return Response::jsonError($e->getMessage(), 404);
        }
    }

    /**
     * منطق الإضافة المشترك بين نموذج الويب وواجهة الـ API.
     *
     * @return array{ok:bool,code:string,entry?:PropertySubscription}
     */
    private function tryAdd(Request $request): array
    {
        $number = preg_replace('/\D+/', '', (string) $request->input('whatsapp_number', ''));
        $notifier = $this->container->whatsAppNotifier();

        if ($number === null || !$notifier->isValidRecipient($number)) {
            return ['ok' => false, 'code' => 'invalid_number'];
        }

        $type = trim((string) $request->input('property_type', ''));
        if ($type !== '' && !PropertyType::isValid($type)) {
            return ['ok' => false, 'code' => 'invalid_type'];
        }

        $location = trim((string) $request->input('location', ''));

        $repo = $this->container->propertySubscriptionRepository();

        if ($repo->count() >= $this->container->config()->int('property_alerts.max_entries', 50)) {
            return ['ok' => false, 'code' => 'limit_reached'];
        }

        $entry = $repo->add(new PropertySubscription(
            id: bin2hex(random_bytes(8)),
            whatsappNumber: $number,
            propertyType: $type !== '' ? $type : null,
            location: $location !== '' ? $location : null,
            createdAt: date(DATE_ATOM),
        ));

        // فحص أولي فوري لتسجيل خط الأساس مباشرة دون انتظار دورة الـ cron التالية.
        try {
            $this->container->propertyAlertChecker()->checkOne($entry->id);
        } catch (Throwable) {
            // تجاهل فشل الفحص الأولي؛ سيُعاد المحاولة في الدورة التالية، والخطأ محفوظ في lastError.
        }

        return ['ok' => true, 'code' => 'added', 'entry' => $repo->find($entry->id) ?? $entry];
    }

    private function redirectFlash(string $code): Response
    {
        return Response::redirect('/property-alerts?flash=' . rawurlencode($code));
    }
}
