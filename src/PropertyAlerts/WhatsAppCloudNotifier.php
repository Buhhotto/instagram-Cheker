<?php

declare(strict_types=1);

namespace App\PropertyAlerts;

use App\Http\HttpClient;
use App\Instagram\Exception\TransportException;
use App\PropertyAlerts\Contracts\Notifier;

/**
 * إرسال رسائل واتساب عبر WhatsApp Cloud API الرسمي من Meta.
 * https://developers.facebook.com/docs/whatsapp/cloud-api/reference/messages
 *
 * ملاحظة على القيود: خارج نافذة الـ 24 ساعة من آخر رسالة يرسلها المستخدم
 * للرقم، لا يقبل واتساب إلا "رسائل قوالب" (message templates) معتمدة
 * مسبقًا من Meta — رسالة نصية حرّة تُرفض بخطأ من واجهة الـ API. هذا قيد من
 * واتساب نفسه لمنع الإزعاج غير المرغوب، ولا يمكن تجاوزه برمجيًا؛ لتنبيهات
 * دورية طويلة المدى أنشئ قالبًا معتمدًا في Meta Business Manager واستبدل
 * جسم الرسالة هنا بنوع "template" بدل "text".
 */
final class WhatsAppCloudNotifier implements Notifier
{
    public function __construct(
        private HttpClient $http,
        private bool $enabled,
        private string $accessToken,
        private string $phoneNumberId,
        private string $apiVersion = 'v21.0',
    ) {
    }

    /** يقبل أرقامًا بصيغة E.164 (رمز الدولة + الرقم، أرقام فقط، بدون + أو مسافات أو رموز). */
    public function isValidRecipient(string $phone): bool
    {
        return preg_match('/^[1-9][0-9]{7,14}$/', $phone) === 1;
    }

    public function send(string $toPhone, string $message): bool
    {
        if (!$this->enabled || $this->accessToken === '' || $this->phoneNumberId === '') {
            return false;
        }

        if (!$this->isValidRecipient($toPhone)) {
            return false;
        }

        $url = sprintf(
            'https://graph.facebook.com/%s/%s/messages',
            rawurlencode($this->apiVersion),
            rawurlencode($this->phoneNumberId)
        );

        try {
            $response = $this->http->postJson($url, [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $toPhone,
                'type' => 'text',
                'text' => ['preview_url' => true, 'body' => $message],
            ], [
                'Authorization' => 'Bearer ' . $this->accessToken,
            ]);
        } catch (TransportException) {
            return false;
        }

        return $response['status'] >= 200 && $response['status'] < 300;
    }
}
