<?php

declare(strict_types=1);

namespace App\PropertyAlerts\Scraper;

use App\Http\HttpClient;
use App\Instagram\Exception\TransportException;
use App\PropertyAlerts\Contracts\Scraper;
use App\PropertyAlerts\DTO\Listing;
use App\PropertyAlerts\Exception\ScraperConfigurationException;
use DOMDocument;
use DOMXPath;

/**
 * يجلب صفحة نتائج omanreal.com/Properties بفلتر (نوع أرض + موقع) عبر
 * معاملات استعلام، ويستخرج بطاقات الإعلانات بمُحدِّدات XPath قابلة للضبط.
 *
 * تنبيه مهم وصريح: لم يتمكن هذا المستودع من الوصول لشبكة omanreal.com أثناء
 * التطوير (حجب على مستوى الشبكة)، لذلك لا تحتوي هذه الفئة على أي بنية HTML
 * أو أسماء معاملات مُخمَّنة لذلك الموقع تحديدًا. جميع القيم الخاصة بالموقع
 * (رابط الأساس، أسماء معاملات الفلترة، ومُحدِّدات XPath لكل بطاقة) تُقرأ من
 * config('property_alerts') ويجب ضبطها يدويًا بعد فحص الموقع فعليًا من
 * متصفح — راجع التعليقات في config/config.php لطريقة الاكتشاف.
 *
 * عند عدم ضبط `selectors.listing_item` تُرمى ScraperConfigurationException
 * فورًا بدل تنفيذ طلب لن يستخرج شيئًا مفيدًا.
 */
final class OmanRealScraper implements Scraper
{
    /**
     * @param array{base_url:string,location_param:string,type_param:string} $source
     * @param array{listing_item:string,title:string,url:string,location:string,price:string} $selectors
     * @param array<string,string> $typeMap
     */
    public function __construct(
        private HttpClient $http,
        private array $source,
        private array $selectors,
        private array $typeMap,
    ) {
    }

    public function search(?string $propertyType, ?string $location): array
    {
        if (trim($this->selectors['listing_item'] ?? '') === '') {
            throw new ScraperConfigurationException(
                'مُحدِّدات استخراج الإعلانات (property_alerts.selectors.listing_item) غير مضبوطة. '
                . 'افحص صفحة omanreal.com/Properties من متصفح واملأ القيم في .env — راجع README.'
            );
        }

        $baseUrl = trim($this->source['base_url'] ?? '');
        if ($baseUrl === '') {
            throw new ScraperConfigurationException('رابط مصدر الإعلانات (property_alerts.source.base_url) غير مضبوط.');
        }

        $query = [];

        if ($propertyType !== null && $propertyType !== '') {
            $paramName = trim($this->source['type_param'] ?? '');
            if ($paramName === '') {
                throw new ScraperConfigurationException(
                    'اسم معامل الفلترة حسب نوع الأرض (property_alerts.source.type_param) غير مضبوط.'
                );
            }
            $query[$paramName] = $this->typeMap[$propertyType] ?? $propertyType;
        }

        if ($location !== null && $location !== '') {
            $paramName = trim($this->source['location_param'] ?? '');
            if ($paramName === '') {
                throw new ScraperConfigurationException(
                    'اسم معامل الفلترة حسب الموقع (property_alerts.source.location_param) غير مضبوط.'
                );
            }
            $query[$paramName] = $location;
        }

        try {
            $response = $this->http->get($baseUrl, $query, ['Accept' => 'text/html']);
        } catch (TransportException $e) {
            throw new ScraperConfigurationException('تعذّر الوصول لصفحة الموقع: ' . $e->getMessage(), previous: $e);
        }

        if ($response['status'] < 200 || $response['status'] >= 300 || $response['body'] === '') {
            throw new ScraperConfigurationException('استجابة غير صالحة من الموقع (حالة ' . $response['status'] . ').');
        }

        return $this->parse($response['body'], $baseUrl);
    }

    /** @return list<Listing> */
    private function parse(string $html, string $baseUrl): array
    {
        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        // ترميز HTML الفعلي قد لا يكون UTF-8 دومًا؛ هذا التمرير آمن ولا يغيّر المحتوى، فقط يمنع تحذيرات محلّل XML من HTML غير صارم.
        $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($doc);
        $nodes = @$xpath->query($this->selectors['listing_item']);

        if ($nodes === false || $nodes->length === 0) {
            throw new ScraperConfigurationException(
                'لم يُطابق مُحدِّد listing_item أي عنصر في صفحة النتائج — إما أن الفلتر لم يُرجع نتائج فعليًا، '
                . 'أو أن تصميم الموقع تغيّر ويلزم تحديث المُحدِّدات في .env.'
            );
        }

        $listings = [];

        foreach ($nodes as $node) {
            $title = trim($this->extract($xpath, $this->selectors['title'], $node));
            if ($title === '') {
                continue;
            }

            $url = trim($this->extract($xpath, $this->selectors['url'], $node));
            $url = $url !== '' ? $this->resolveUrl($url, $baseUrl) : null;
            $location = trim($this->extract($xpath, $this->selectors['location'], $node)) ?: null;
            $price = trim($this->extract($xpath, $this->selectors['price'], $node)) ?: null;

            $listings[] = new Listing(
                id: Listing::makeId($url, $title, $location, $price),
                title: $title,
                url: $url,
                location: $location,
                price: $price,
            );
        }

        return $listings;
    }

    private function extract(DOMXPath $xpath, string $expression, \DOMNode $context): string
    {
        $result = @$xpath->evaluate($expression, $context);

        if (is_string($result)) {
            return $result;
        }

        if ($result instanceof \DOMNodeList && $result->length > 0) {
            return (string) $result->item(0)?->textContent;
        }

        return '';
    }

    private function resolveUrl(string $url, string $baseUrl): string
    {
        if (preg_match('#^https?://#i', $url) === 1) {
            return $url;
        }

        $base = parse_url($baseUrl);
        if ($base === false || !isset($base['scheme'], $base['host'])) {
            return $url;
        }

        $origin = $base['scheme'] . '://' . $base['host'] . (isset($base['port']) ? ':' . $base['port'] : '');

        return $origin . '/' . ltrim($url, '/');
    }
}
