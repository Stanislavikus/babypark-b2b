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
}
