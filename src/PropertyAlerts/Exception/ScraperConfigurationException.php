<?php

declare(strict_types=1);

namespace App\PropertyAlerts\Exception;

use RuntimeException;

/**
 * تُرمى عندما تكون مُحدِّدات استخراج الإعلانات (selectors) غير مضبوطة، أو
 * لم تعد تطابق شيئًا في صفحة الموقع (تغيّر تصميمه). تُبقى منفصلة عن أخطاء
 * الشبكة عمدًا: فحص فاشل بسبب إعداد ناقص يجب أن يتوقّف بخطأ صريح، لا أن
 * يُرجع "لا توجد إعلانات جديدة" بصمت — وهذا قد يُفهم خطأً على أنه نتيجة فحص
 * سليمة بينما هو فشل استخراج فعلي.
 */
final class ScraperConfigurationException extends RuntimeException
{
}
