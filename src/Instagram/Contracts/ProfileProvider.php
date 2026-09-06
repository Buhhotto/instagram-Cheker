<?php

declare(strict_types=1);

namespace App\Instagram\Contracts;

use App\Instagram\DTO\Comment;
use App\Instagram\DTO\Media;
use App\Instagram\DTO\Profile;

/**
 * عقد موحّد لمصادر بيانات إنستاجرام (Graph API الرسمي، الواجهة العامة، أو بيانات تجريبية).
 */
interface ProfileProvider
{
    /** معرّف قصير للمزوّد يظهر في نتائج الـ API. */
    public function name(): string;

    /** هل المزوّد مهيّأ بالمفاتيح اللازمة ويمكن استخدامه؟ */
    public function isConfigured(): bool;

    /** يُرجع الملف الشخصي أو null إذا لم يكن الحساب موجودًا. */
    public function fetchProfile(string $username): ?Profile;

    /** @return list<Media> أحدث المنشورات، وقد تكون فارغة للحسابات الخاصة. */
    public function fetchMedia(string $username, int $limit = 25): array;

    /**
     * @return list<Comment> تعليقات منشور محدّد، وقد تكون فارغة إذا لم يسمح المزوّد بقراءتها.
     */
    public function fetchComments(string $username, string $mediaId, int $limit = 50): array;

    /** هل يستطيع هذا المزوّد قراءة التعليقات لهذا الحساب؟ */
    public function supportsComments(string $username): bool;

    /** true موجود، false متاح، null غير معروف. */
    public function usernameExists(string $username): ?bool;
}
