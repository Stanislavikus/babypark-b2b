<?php

namespace Tests\Unit\Sync;

use App\Enums\FieldObjectType;
use App\Services\Sync\CanonicalFieldOptionMappingSuggestionProvider;
use App\Support\CanonicalRegistry\CanonicalRegistryReader;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CanonicalFieldOptionMappingSuggestionProviderTest extends TestCase
{
    private CanonicalFieldOptionMappingSuggestionProvider $provider;

    /** @var list<string> */
    private array $temporaryRegistryPaths = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = new CanonicalFieldOptionMappingSuggestionProvider(
            new CanonicalRegistryReader(base_path('docs/data')),
        );
    }

    #[Test]
    public function verified_google_condition_options_are_suggested_when_field_and_values_are_authoritative(): void
    {
        $this->assertSame([
            'new' => 'new',
            'used' => 'used',
            'refurbished' => 'refurbished',
        ], $this->provider->suggest(
            connectorDefinitionCode: 'google_merchant',
            internalFieldCode: 'condition',
            objectType: FieldObjectType::ProductVariant,
            externalFieldKey: 'condition',
            internalOptionKeys: ['new', 'used', 'refurbished'],
            authoritativeExternalValues: ['new', 'used', 'refurbished'],
        ));
    }

    #[Test]
    public function suggestion_is_limited_to_current_internal_and_authoritative_external_values(): void
    {
        $this->assertSame([
            'new' => 'new',
        ], $this->provider->suggest(
            connectorDefinitionCode: 'google_merchant',
            internalFieldCode: 'condition',
            objectType: FieldObjectType::ProductVariant,
            externalFieldKey: 'condition',
            internalOptionKeys: ['new', 'used'],
            authoritativeExternalValues: ['new'],
        ));
    }

    #[Test]
    public function persisted_external_values_are_reserved_before_new_suggestions(): void
    {
        $this->assertSame([
            'used' => 'used',
            'refurbished' => 'refurbished',
        ], $this->provider->suggest(
            connectorDefinitionCode: 'google_merchant',
            internalFieldCode: 'condition',
            objectType: FieldObjectType::ProductVariant,
            externalFieldKey: 'condition',
            internalOptionKeys: ['new', 'used', 'refurbished'],
            authoritativeExternalValues: ['new', 'used', 'refurbished'],
            reservedExternalValues: ['new'],
        ));
    }

    #[Test]
    public function applicability_entity_level_must_match_confirmed_binding_object_type(): void
    {
        $this->assertSame([], $this->provider->suggest(
            connectorDefinitionCode: 'google_merchant',
            internalFieldCode: 'condition',
            objectType: FieldObjectType::Product,
            externalFieldKey: 'condition',
            internalOptionKeys: ['new', 'used', 'refurbished'],
            authoritativeExternalValues: ['new', 'used', 'refurbished'],
        ));
    }

    #[Test]
    public function external_value_collision_between_internal_options_fails_closed_for_both(): void
    {
        $provider = $this->providerFromCsv([
            'canonical_product_field_mappings.csv' => "internal_code,channel,external_field,applicability_id,verification_status\ncolor,adobe_commerce,color,a-color,verified\n",
            'canonical_product_field_options.csv' => "option_id,internal_code,option_code,applicability_id,verification_status,status\no-blue,color,blue,a-color,verified,active\no-pink,color,pink,a-color,verified,active\n",
            'canonical_product_field_option_mappings.csv' => "option_id,channel,external_option_value,applicability_id,verification_status\no-blue,adobe_commerce,93,a-color,verified\no-pink,adobe_commerce,93,a-color,verified\n",
            'canonical_product_field_applicability.csv' => "applicability_id,internal_code,context_type,channel_or_state,entity_level,verification_status\na-color,color,channel,adobe_commerce,product_variant,verified\n",
        ]);

        $this->assertSame([], $provider->suggest(
            connectorDefinitionCode: 'adobe_commerce',
            internalFieldCode: 'color',
            objectType: FieldObjectType::ProductVariant,
            externalFieldKey: 'color',
            internalOptionKeys: ['blue', 'pink'],
            authoritativeExternalValues: ['93'],
        ));
    }

    #[Test]
    public function custom_field_mapping_does_not_inherit_standard_channel_option_semantics(): void
    {
        $this->assertSame([], $this->provider->suggest(
            connectorDefinitionCode: 'google_merchant',
            internalFieldCode: 'condition',
            objectType: FieldObjectType::ProductVariant,
            externalFieldKey: 'custom_condition',
            internalOptionKeys: ['new'],
            authoritativeExternalValues: ['new'],
        ));
    }

    #[Test]
    public function channel_mismatch_fails_closed(): void
    {
        $this->assertSame([], $this->provider->suggest(
            connectorDefinitionCode: 'adobe_commerce',
            internalFieldCode: 'condition',
            objectType: FieldObjectType::ProductVariant,
            externalFieldKey: 'condition',
            internalOptionKeys: ['new'],
            authoritativeExternalValues: ['new'],
        ));
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryRegistryPaths as $path) {
            File::deleteDirectory($path);
        }

        parent::tearDown();
    }

    /**
     * @param  array<string, string>  $files
     */
    private function providerFromCsv(array $files): CanonicalFieldOptionMappingSuggestionProvider
    {
        $path = storage_path('framework/testing/canonical-option-provider-'.Str::random(8));
        File::ensureDirectoryExists($path);
        $this->temporaryRegistryPaths[] = $path;

        foreach ($files as $filename => $contents) {
            File::put($path.'/'.$filename, $contents);
        }

        return new CanonicalFieldOptionMappingSuggestionProvider(new CanonicalRegistryReader($path));
    }
}
