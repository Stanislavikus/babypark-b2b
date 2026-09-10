<?php

namespace Tests\Unit;

use App\Enums\CatalogProductDisplayState;
use App\Enums\PriceDisplayMode;
use App\Filament\Resources\CustomerResource\Pages\PreviewAsCustomer;
use App\Livewire\Cabinet\Catalog;
use App\Services\Availability\AvailabilityResolver;
use App\Services\Pricing\CustomerCatalogQuery;
use App\Services\Pricing\PriceDisplayModeResolver;
use App\Services\Pricing\PriceDisplayPresenter;
use App\Services\Pricing\PriceResolver;
use App\Services\Pricing\PricingSqlExpressions;
use App\Services\Pricing\ProductPricingSummary;
use App\Services\Pricing\Resolution\PriceResolutionResult;
use App\Services\Pricing\Resolution\PriceResolutionStatus;
use App\Services\Pricing\WorkspaceTaxDefaults;
use App\Support\CatalogRowData;
use App\Support\Pricing\CatalogRowProjection;
use App\Support\Pricing\CustomerCatalogCriteria;
use App\Support\Pricing\CustomerPricingScope;
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
            'PriceResolver' => [PriceResolver::class],
            'PriceResolutionResult' => [PriceResolutionResult::class],
            'PriceResolutionStatus' => [PriceResolutionStatus::class],
            'CustomerPricingScope' => [CustomerPricingScope::class],
            'WorkspaceTaxDefaults' => [WorkspaceTaxDefaults::class],
            'PriceDisplayMode' => [PriceDisplayMode::class],
            'PriceDisplayPresenter' => [PriceDisplayPresenter::class],
            'PriceDisplayModeResolver' => [PriceDisplayModeResolver::class],
            'CatalogProductDisplayState' => [CatalogProductDisplayState::class],
            'CatalogRowData' => [CatalogRowData::class],
            'CatalogRowProjection' => [CatalogRowProjection::class],
            'CustomerCatalogQuery' => [CustomerCatalogQuery::class],
            'ProductPricingSummary' => [ProductPricingSummary::class],
            'AvailabilityResolver' => [AvailabilityResolver::class],
            'CustomerCatalogCriteria' => [CustomerCatalogCriteria::class],
            'PricingSqlExpressions' => [PricingSqlExpressions::class],
            'Cabinet Catalog Livewire' => [Catalog::class],
            'PreviewAsCustomer page' => [PreviewAsCustomer::class],
        ];
    }

    #[DataProvider('documentedCatalogProjectionClasses')]
    public function test_documented_catalog_projection_class_exists(string $class): void
    {
        $this->assertTrue(class_exists($class), "Documented class missing: {$class}");
    }
}
