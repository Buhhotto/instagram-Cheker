<?php

declare(strict_types=1);

namespace App\Instagram;

use App\Instagram\Contracts\ProfileProvider;
use App\Instagram\DTO\Comment;
use App\Instagram\DTO\Media;
use App\Instagram\DTO\Profile;
use App\Instagram\Exception\InstagramException;
use App\Instagram\Exception\ProviderUnavailableException;

/**
 * يجرّب المزوّدين بالترتيب ويعيد أول نتيجة صالحة، مع تسجيل مصدر البيانات
 * وأي تحذيرات حدثت أثناء المحاولة حتى تبقى النتيجة شفافة للمستخدم.
 */
final class ProviderChain implements ProfileProvider
{
    /** @var list<string> */
    private array $warnings = [];

    private ?string $lastSource = null;

    /** @param list<ProfileProvider> $providers */
    public function __construct(private array $providers)
    {
    }

    public function name(): string
    {
        return 'chain';
    }

    public function isConfigured(): bool
    {
        return $this->active() !== [];
    }

    /** @return list<ProfileProvider> المزوّدون المهيّأون فقط. */
    public function active(): array
    {
        return array_values(array_filter(
            $this->providers,
            static fn (ProfileProvider $provider): bool => $provider->isConfigured()
        ));
    }

    /** @return list<string> */
    public function activeNames(): array
    {
        return array_map(static fn (ProfileProvider $p): string => $p->name(), $this->active());
    }

    /** @return list<string> */
    public function warnings(): array
    {
        return array_values(array_unique($this->warnings));
    }

    public function lastSource(): ?string
    {
        return $this->lastSource;
    }

    /**
     * تحذير يُضاف لنتائج التحليل عندما تأتي البيانات من المزوّد التجريبي،
     * حتى لا تُقرأ الأرقام المُصطنعة على أنها بيانات إنستاجرام حقيقية.
     *
     * @return list<string>
     */
    public function sourceWarnings(): array
    {
        if ($this->lastSource !== 'demo') {
            return [];
        }

        return ['البيانات المعروضة مُصطنعة من وضع التجربة وليست من إنستاجرام. اضبط توكن Graph API للحصول على أرقام حقيقية.'];
    }

    public function fetchProfile(string $username): ?Profile
    {
        $this->guard();

        foreach ($this->active() as $provider) {
            try {
                $profile = $provider->fetchProfile($username);
            } catch (InstagramException $e) {
                $this->note($provider, $e);
                continue;
            }

            if ($profile !== null) {
                $this->lastSource = $provider->name();

                return $profile;
            }
        }

        return null;
    }

    /** @return list<Media> */
    public function fetchMedia(string $username, int $limit = 25): array
    {
        $this->guard();

        foreach ($this->active() as $provider) {
            try {
                $media = $provider->fetchMedia($username, $limit);
            } catch (InstagramException $e) {
                $this->note($provider, $e);
                continue;
            }

            if ($media !== []) {
                $this->lastSource = $provider->name();

                return $media;
            }
        }

        return [];
    }

    /** @return list<Comment> */
    public function fetchComments(string $username, string $mediaId, int $limit = 50): array
    {
        foreach ($this->active() as $provider) {
            if (!$provider->supportsComments($username)) {
                continue;
            }

            try {
                $comments = $provider->fetchComments($username, $mediaId, $limit);
            } catch (InstagramException $e) {
                $this->note($provider, $e);
                continue;
            }

            if ($comments !== []) {
                return $comments;
            }
        }

        return [];
    }

    public function supportsComments(string $username): bool
    {
        foreach ($this->active() as $provider) {
            if ($provider->supportsComments($username)) {
                return true;
            }
        }

        return false;
    }

    public function usernameExists(string $username): ?bool
    {
        $this->guard();

        foreach ($this->active() as $provider) {
            try {
                $exists = $provider->usernameExists($username);
            } catch (InstagramException $e) {
                $this->note($provider, $e);
                continue;
            }

            if ($exists !== null) {
                $this->lastSource = $provider->name();

                return $exists;
            }
        }

        return null;
    }

    private function guard(): void
    {
        if ($this->active() === []) {
            throw new ProviderUnavailableException(
                'لا يوجد مزوّد بيانات مفعّل. فعّل Graph API أو الواجهة العامة أو وضع التجربة في ملف .env.'
            );
        }
    }

    private function note(ProfileProvider $provider, InstagramException $e): void
    {
        $this->warnings[] = sprintf('تعذّر استخدام المزوّد "%s": %s', $provider->name(), $e->getMessage());
    }
}
