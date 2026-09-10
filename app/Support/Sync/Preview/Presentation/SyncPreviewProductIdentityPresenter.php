<?php

namespace App\Support\Sync\Preview\Presentation;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Collection;

final class SyncPreviewProductIdentityPresenter
{
    /**
     * @param  Collection<int, ProductVariant>  $sellableVariants
     */
    public function present(Product $product, Collection $sellableVariants): string
    {
        $lines = [$product->name];
        $brand = is_string($product->brand) ? trim($product->brand) : '';
        $count = $sellableVariants->count();

        if ($count === 1) {
            $sku = is_string($sellableVariants->first()->sku) ? trim((string) $sellableVariants->first()->sku) : '';
            $skuLine = $sku !== ''
                ? __('sync_preview.product_identity.single_sku', ['sku' => $sku])
                : __('sync_preview.product_identity.sku_missing');
            $lines[] = $this->appendBrand($skuLine, $brand);
        } elseif ($count > 1) {
            $lines[] = $this->appendBrand(
                __('sync_preview.product_identity.multi_sku', ['count' => $count]),
                $brand,
            );
        } elseif ($brand !== '') {
            $lines[] = $brand;
        }

        return implode("\n", $lines);
    }

    public function presentHtml(Product $product, Collection $sellableVariants): string
    {
        $lines = explode("\n", $this->present($product, $sellableVariants));
        $name = array_shift($lines);
        $secondary = implode('<br>', array_map('e', $lines));

        if ($secondary === '') {
            return '<span class="font-medium">'.e($name).'</span>';
        }

        return '<span class="font-medium">'.e($name).'</span>'
            .'<br><span class="text-sm text-gray-500 dark:text-gray-400">'.$secondary.'</span>';
    }

    private function appendBrand(string $line, string $brand): string
    {
        if ($brand === '') {
            return $line;
        }

        return $line.' · '.$brand;
    }
}
