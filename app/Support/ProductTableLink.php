<?php

namespace App\Support;

use Illuminate\Support\HtmlString;

class ProductTableLink
{
    public static function externalUrlHtml(?string $url, int $limit = 35): HtmlString|string
    {
        if (! filled($url)) {
            return '—';
        }

        $display = parse_url($url, PHP_URL_HOST).rtrim(parse_url($url, PHP_URL_PATH) ?? '', '/');
        $safe = e($url);
        $label = e(mb_strlen($display) > $limit ? mb_substr($display, 0, $limit).'…' : $display);

        return new HtmlString(
            '<a href="'.$safe.'" target="_blank" rel="noopener noreferrer"'
            .' class="inline-flex items-center gap-1 text-primary-600 hover:text-primary-500 hover:underline"'
            .' onclick="event.stopPropagation();event.preventDefault();window.open(\''.$safe.'\',\'_blank\',\'noopener,noreferrer\');">'
            .$label
            .'<svg class="h-4 w-4 shrink-0" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">'
            .'<path fill-rule="evenodd" d="M4.25 5.5a.75.75 0 0 1 .75-.75h8.5a.75.75 0 0 1 .75.75v8.5a.75.75 0 0 1-1.5 0V6.56l-7.22 7.22a.75.75 0 0 1-1.06-1.06l7.22-7.22H5a.75.75 0 0 1-.75-.75Z" clip-rule="evenodd" />'
            .'</svg></a>'
        );
    }
}
