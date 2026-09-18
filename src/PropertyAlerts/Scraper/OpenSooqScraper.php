<?php

declare(strict_types=1);

namespace App\PropertyAlerts\Scraper;

use App\Http\HttpClient;

/**
 * مصدر السوق المفتوح (OpenSooq) — راجع توثيق AbstractXPathScraper لآلية
 * الاستخراج العامة وسبب عدم وجود قيم افتراضية مُخمَّنة.
 */
final class OpenSooqScraper extends AbstractXPathScraper
{
    /**
     * @param array{base_url:string,location_param:string,type_param:string} $source
     * @param array{listing_item:string,title:string,url:string,location:string,price:string} $selectors
     * @param array<string,string> $typeMap
     */
    public function __construct(HttpClient $http, array $source, array $selectors, array $typeMap)
    {
        parent::__construct($http, 'OpenSooq', $source, $selectors, $typeMap);
    }
}
