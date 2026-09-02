<?php

declare(strict_types=1);

namespace App\Instagram;

use App\Instagram\Exception\ValidationException;

/**
 * التحقق من صلاحية اسم مستخدم إنستاجرام ومن توفّره، مع اقتراح بدائل عند كونه محجوزًا.
 */
final class UsernameChecker
{
    public const STATUS_AVAILABLE = 'available';
    public const STATUS_TAKEN = 'taken';
    public const STATUS_INVALID = 'invalid';
    public const STATUS_RESERVED = 'reserved';
    public const STATUS_UNKNOWN = 'unknown';

    private const MIN_LENGTH = 1;
    private const MAX_LENGTH = 30;

    /** كلمات تحجزها إنستاجرام أو تمنع التسجيل بها عادةً. */
    private const RESERVED = [
        'about', 'account', 'accounts', 'admin', 'administrator', 'api', 'app', 'apps', 'blog',
        'developer', 'developers', 'explore', 'facebook', 'favorites', 'follow', 'followers',
        'following', 'help', 'home', 'instagram', 'legal', 'login', 'logout', 'meta', 'null',
        'privacy', 'register', 'root', 'security', 'session', 'settings', 'signup', 'support',
        'terms', 'undefined', 'user', 'username', 'users', 'www',
    ];

    /** @var list<string> لواحق تُستخدم في توليد الاقتراحات. */
    private const SUFFIXES = ['_', '.official', 'hq', '_ig', '.co', '1', '_real'];

    /** @var list<string> بادئات تُستخدم في توليد الاقتراحات. */
    private const PREFIXES = ['the', 'real', 'its', 'im'];

    public function __construct(
        private ProviderChain $providers,
        private int $suggestionLimit = 6,
        private int $suggestionChecks = 4,
    ) {
    }

    /**
     * تحقق كامل: القواعد ثم الحجز ثم الوجود الفعلي.
     *
     * @return array<string,mixed>
     */
    public function check(string $username, bool $withSuggestions = true): array
    {
        $username = $this->normalize($username);
        $validation = $this->validate($username);

        $result = [
            'username' => $username,
            'profile_url' => 'https://www.instagram.com/' . rawurlencode($username) . '/',
            'checked_at' => date(DATE_ATOM),
            'validation' => $validation,
            'exists' => null,
            'available' => false,
            'status' => self::STATUS_INVALID,
            'confidence' => 'high',
            'source' => null,
            'suggestions' => [],
            'warnings' => [],
        ];

        if (!$validation['valid']) {
            $result['message'] = 'الاسم لا يطابق قواعد إنستاجرام، لذلك لا يمكن التسجيل به.';
            $result['suggestions'] = $withSuggestions ? $this->suggest($username, false) : [];

            return $result;
        }

        if ($validation['reserved']) {
            $result['status'] = self::STATUS_RESERVED;
            $result['exists'] = true;
            $result['message'] = 'اسم محجوز من قبل إنستاجرام ولا يمكن استخدامه.';
            $result['suggestions'] = $withSuggestions ? $this->suggest($username, false) : [];

            return $result;
        }

        $exists = $this->providers->usernameExists($username);
        $result['exists'] = $exists;
        $result['source'] = $this->providers->lastSource();
        $result['warnings'] = array_merge($this->providers->warnings(), $this->providers->sourceWarnings());

        if ($exists === null) {
            $result['status'] = self::STATUS_UNKNOWN;
            $result['confidence'] = 'low';
            $result['message'] = 'تعذّر التأكد من حالة الاسم حاليًا؛ قد يكون المزوّد محدودًا بالطلبات. أعد المحاولة لاحقًا.';
        } elseif ($exists) {
            $result['status'] = self::STATUS_TAKEN;
            $result['message'] = 'الاسم مستخدم بالفعل.';
        } else {
            $result['status'] = self::STATUS_AVAILABLE;
            $result['available'] = true;
            $result['message'] = 'الاسم يبدو متاحًا. لا يضمن ذلك قبوله عند التسجيل إذا كان محجوزًا داخليًا.';
        }

        // نتيجة مصدرها وضع التجربة ليست دليلًا على الحالة الحقيقية للاسم.
        if ($result['source'] === 'demo') {
            $result['confidence'] = 'low';
        }

        if ($withSuggestions && $result['status'] !== self::STATUS_AVAILABLE) {
            $result['suggestions'] = $this->suggest($username, true);
        }

        return $result;
    }

    /**
     * فحص القواعد المحلية فقط (بدون شبكة).
     *
     * @return array{valid:bool,reserved:bool,errors:list<string>,notes:list<string>,length:int}
     */
    public function validate(string $username): array
    {
        $errors = [];
        $notes = [];
        $length = mb_strlen($username, 'UTF-8');

        if ($length < self::MIN_LENGTH) {
            $errors[] = 'اسم المستخدم مطلوب.';
        }

        if ($length > self::MAX_LENGTH) {
            $errors[] = sprintf('الحد الأقصى %d حرفًا (الطول الحالي %d).', self::MAX_LENGTH, $length);
        }

        if ($username !== '' && preg_match('/^[a-z0-9._]+$/', $username) !== 1) {
            $errors[] = 'يُسمح فقط بالحروف الإنجليزية والأرقام والنقطة والشرطة السفلية.';
        }

        if (str_starts_with($username, '.') || str_ends_with($username, '.')) {
            $errors[] = 'لا يمكن أن يبدأ الاسم أو ينتهي بنقطة.';
        }

        if (str_contains($username, '..')) {
            $notes[] = 'النقاط المتتالية قد تُرفض عند التسجيل.';
        }

        if ($username !== '' && preg_match('/^[._]+$/', $username) === 1) {
            $errors[] = 'يجب أن يحتوي الاسم على حرف أو رقم واحد على الأقل.';
        }

        if ($length > 0 && $length < 3) {
            $notes[] = 'الأسماء القصيرة جدًا مأخوذة في الغالب.';
        }

        $reserved = in_array($username, self::RESERVED, true);
        if ($reserved) {
            $notes[] = 'هذا الاسم ضمن قائمة الأسماء المحجوزة.';
        }

        return [
            'valid' => $errors === [],
            'reserved' => $reserved,
            'errors' => $errors,
            'notes' => $notes,
            'length' => $length,
        ];
    }

    /**
     * توليد بدائل صالحة، مع فحص توفّر أول عدد منها إن أمكن.
     *
     * @return list<array{username:string,available:bool|null}>
     */
    public function suggest(string $username, bool $checkAvailability = true): array
    {
        $base = strtolower((string) preg_replace('/[^a-z0-9._]/', '', strtolower($username)));
        $base = (string) preg_replace('/\.{2,}/', '.', $base);
        $base = trim($base, '.');

        if ($base === '') {
            return [];
        }

        $candidates = [];

        foreach (self::SUFFIXES as $suffix) {
            $candidates[] = $base . $suffix;
        }

        foreach (self::PREFIXES as $prefix) {
            $candidates[] = $prefix . $base;
        }

        $candidates[] = $base . '.' . substr((string) date('Y'), 2);
        $candidates[] = str_replace('_', '.', $base);

        $candidates = array_values(array_filter(
            array_unique($candidates),
            fn (string $candidate): bool => $candidate !== $username
                && $this->validate($candidate)['valid']
                && !in_array($candidate, self::RESERVED, true)
        ));

        $candidates = array_slice($candidates, 0, $this->suggestionLimit);

        $suggestions = [];
        $checked = 0;

        foreach ($candidates as $candidate) {
            $available = null;

            if ($checkAvailability && $checked < $this->suggestionChecks) {
                $checked++;
                $exists = $this->providers->usernameExists($candidate);
                $available = $exists === null ? null : !$exists;
            }

            $suggestions[] = ['username' => $candidate, 'available' => $available];
        }

        return $suggestions;
    }

    /** تنظيف المدخل: إزالة @ والروابط والمسافات. */
    public function normalize(string $username): string
    {
        $username = trim($username);
        $username = (string) preg_replace('#^https?://(www\.)?instagram\.com/#i', '', $username);
        $username = ltrim($username, '@');
        $username = trim($username, "/ \t\n\r\0\x0B");
        $username = strtolower($username);

        if ($username === '') {
            throw new ValidationException('يرجى إدخال اسم مستخدم.');
        }

        return $username;
    }
}
