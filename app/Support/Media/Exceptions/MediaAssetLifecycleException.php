<?php

namespace App\Support\Media\Exceptions;

use RuntimeException;

final class MediaAssetLifecycleException extends RuntimeException
{
    /**
     * @param  array{products:int,variants:int,brands:int,derivatives:int}  $usageCounts
     */
    private function __construct(
        string $message,
        public readonly array $usageCounts = [
            'products' => 0,
            'variants' => 0,
            'brands' => 0,
            'derivatives' => 0,
        ],
    ) {
        parent::__construct($message);
    }

    public static function originalRequired(): self
    {
        return new self('Замінювати або видаляти можна лише Original asset.');
    }

    public static function imageRequired(): self
    {
        return new self('Lifecycle v1 підтримує заміну лише зображень.');
    }

    public static function duplicateContent(): self
    {
        return new self('Це зображення вже є в Assets. Щоб не створювати дубль, поточний Asset не змінено. Оберіть інший файл.');
    }

    public static function derivativesBlockReplace(int $count): self
    {
        return new self("Заміну заблоковано: від цього Original існує похідних версій — {$count}. Спочатку потрібен окремий lifecycle для похідних версій.");
    }

    /**
     * @param  array{products:int,variants:int,brands:int,derivatives:int}  $counts
     */
    public static function inUse(array $counts): self
    {
        $parts = [];

        if ($counts['products'] > 0) {
            $parts[] = 'товарів: '.$counts['products'];
        }

        if ($counts['variants'] > 0) {
            $parts[] = 'варіантів: '.$counts['variants'];
        }

        if ($counts['brands'] > 0) {
            $parts[] = 'брендів: '.$counts['brands'];
        }

        if ($counts['derivatives'] > 0) {
            $parts[] = 'похідних версій: '.$counts['derivatives'];
        }

        $detail = $parts === [] ? 'є активні посилання' : implode(' · ', $parts);

        return new self(
            'Asset не можна видалити, поки він використовується: '.$detail.'. Спочатку приберіть або перепризначте ці використання.',
            $counts,
        );
    }

    public static function storageFailed(): self
    {
        return new self('Не вдалося зберегти новий Original для заміни.');
    }
}
