<?php

namespace App\Support\Connectors\AdobePaaS\Media;

enum AdobeProductMediaRole: string
{
    case Primary = 'primary';
    case Gallery = 'gallery';

    /**
     * @return list<string>
     */
    public function magentoTypes(): array
    {
        return match ($this) {
            self::Primary => ['image', 'small_image', 'thumbnail'],
            self::Gallery => [],
        };
    }

    /**
     * Magento media roles owned by this connector runtime. Remote roles outside
     * this list must be preserved rather than implicitly cleared.
     *
     * @return list<string>
     */
    public static function controlledMagentoTypes(): array
    {
        return ['image', 'small_image', 'thumbnail'];
    }
}
