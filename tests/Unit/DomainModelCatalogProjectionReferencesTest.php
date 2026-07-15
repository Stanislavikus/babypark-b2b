<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Guards docs/03-DOMAIN_MODEL.md "B2B Catalogue Projection" and "Audience Resolution"
 * against class/path drift — every symbol cited there must still exist in the codebase.
 */
class DomainModelCatalogProjectionReferencesTest extends TestCase
{
    /**
     * @return array<string, array{0: class-string}>
     */
    public static function documentedCatalogProjectionClasses(): array
    {
        return [
            'PriceResolver' => [\App\Services\Pricing\PriceResolver::class],
            'PriceResolutionResult' => [\App\Services\Pricing\Resolution\PriceResolutionResult::class],
            'PriceResolutionStatus' => [\App\Services\Pricing\Resolution\PriceResolutionStatus::class],
            'CustomerPricingScope' => [\App\Support\Pricing\CustomerPricingScope::class],
            'WorkspaceTaxDefaults' => [\App\Services\Pricing\WorkspaceTaxDefaults::class],
            'PriceDisplayMode' => [\App\Enums\PriceDisplayMode::class],
            'PriceDisplayPresenter' => [\App\Services\Pricing\PriceDisplayPresenter::class],
            'PriceDisplayModeResolver' => [\App\Services\Pricing\PriceDisplayModeResolver::class],
            'CatalogProductDisplayState' => [\App\Enums\CatalogProductDisplayState::class],
            'CatalogRowData' => [\App\Support\CatalogRowData::class],
            'CatalogRowProjection' => [\App\Support\Pricing\CatalogRowProjection::class],
            'CustomerCatalogQuery' => [\App\Services\Pricing\CustomerCatalogQuery::class],
            'ProductPricingSummary' => [\App\Services\Pricing\ProductPricingSummary::class],
            'AvailabilityResolver' => [\App\Services\Availability\AvailabilityResolver::class],
            'CustomerCatalogCriteria' => [\App\Support\Pricing\CustomerCatalogCriteria::class],
            'PricingSqlExpressions' => [\App\Services\Pricing\PricingSqlExpressions::class],
            'Cabinet Catalog Livewire' => [\App\Livewire\Cabinet\Catalog::class],
            'PreviewAsCustomer page' => [\App\Filament\Resources\CustomerResource\Pages\PreviewAsCustomer::class],
        ];
    }

    #[DataProvider('documentedCatalogProjectionClasses')]
    public function test_documented_catalog_projection_class_exists(string $class): void
    {
        $this->assertTrue(class_exists($class), "Documented class missing: {$class}");
    }
}
