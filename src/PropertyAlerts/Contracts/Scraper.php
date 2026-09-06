<?php

declare(strict_types=1);

namespace App\PropertyAlerts\Contracts;

use App\PropertyAlerts\DTO\Listing;
use App\PropertyAlerts\Exception\ScraperConfigurationException;

/**
 * عقد جلب إعلانات مطابقة لفلتر (نوع أرض + موقع) من مصدر خارجي.
 */
interface Scraper
{
    /**
     * @throws ScraperConfigurationException عند عدم ضبط مُحدِّدات الاستخراج
     * @return list<Listing>
     */
    public function search(?string $propertyType, ?string $location): array;
}
