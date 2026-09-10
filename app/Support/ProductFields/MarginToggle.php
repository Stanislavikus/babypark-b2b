<?php

namespace App\Support\ProductFields;

use Illuminate\Support\HtmlString;

class MarginToggle
{
    public static function labelHtml(string $format = 'percent'): HtmlString
    {
        $badge = $format === 'percent' ? '%' : '₴';

        return new HtmlString(
            '<button type="button" wire:click="toggleMarginFormat"'
            .' class="inline-flex items-center gap-1 hover:text-primary-600 transition-colors"'
            .' title="Перемкнути формат маржі">'
            .'Маржа'
            .'<span class="bp-muted-badge text-[10px] font-bold px-1 py-0.5 rounded">'.$badge.'</span>'
            .'</button>'
        );
    }
}
