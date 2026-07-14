<?php

namespace App\Support\Pricing;

use App\Enums\CatalogPriceDisplayStatus;
use App\Enums\PriceDisplayContext;
use App\Models\Workspace;
use App\Services\Pricing\PriceDisplayModeResolver;
use App\Services\Pricing\PriceDisplayPresenter;
use App\Support\Workspace\WorkspaceContext;

class CustomerFacingPriceLabel
{
    private const INTERNAL_PATTERNS = [
        'DefaultPriceListMisconfigured',
        'active default price list',
        'multiple active default price lists',
        'workspace_id',
    ];

    public static function forDisplay(VariantPriceDisplay $display, ?Workspace $workspace = null): string
    {
        return match ($display->status) {
            CatalogPriceDisplayStatus::Resolved => self::formatResolved($display, $workspace),
            CatalogPriceDisplayStatus::Unavailable => 'Ціна недоступна',
            CatalogPriceDisplayStatus::ConfigurationError => 'Помилка конфігурації цін',
        };
    }

    public static function sanitize(string $output): string
    {
        foreach (self::INTERNAL_PATTERNS as $pattern) {
            if (str_contains($output, $pattern)) {
                return 'Помилка конфігурації цін';
            }
        }

        return $output;
    }

    private static function formatResolved(VariantPriceDisplay $display, ?Workspace $workspace): string
    {
        if ($display->resolvedPrice === null) {
            return $display->formattedGross();
        }

        $workspace ??= app(WorkspaceContext::class)->current();
        $mode = app(PriceDisplayModeResolver::class)->resolve($workspace, PriceDisplayContext::CustomerFacing);
        $presentation = app(PriceDisplayPresenter::class)->present($display->resolvedPrice, $mode);

        return $presentation->compactLabel();
    }
}
