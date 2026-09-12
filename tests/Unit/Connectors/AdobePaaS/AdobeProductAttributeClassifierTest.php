<?php

namespace Tests\Unit\Connectors\AdobePaaS;

use App\Enums\ConnectorSchemaFieldDisposition;
use App\Models\ConnectorSchemaSnapshotField;
use App\Support\Connectors\AdobePaaS\AdobeProductAttributeClassifier;
use Tests\TestCase;

class AdobeProductAttributeClassifierTest extends TestCase
{
    public function test_verified_canonical_mapping_is_ready_without_name_guessing(): void
    {
        $decision = app(AdobeProductAttributeClassifier::class)->classify($this->field('description'));

        $this->assertSame(ConnectorSchemaFieldDisposition::CanonicalPlatform, $decision->disposition);
        $this->assertSame('description', $decision->canonicalCode);
    }

    public function test_dedicated_provider_attribute_keeps_canonical_target_when_applicable(): void
    {
        $decision = app(AdobeProductAttributeClassifier::class)->classify($this->field('category_ids'));

        $this->assertSame(ConnectorSchemaFieldDisposition::SystemOrDedicatedOwner, $decision->disposition);
        $this->assertSame('relations', $decision->runtimeOwnerHint);
        $this->assertSame('category', $decision->canonicalCode);
    }

    public function test_provider_registry_wins_over_misleading_is_user_defined_flag(): void
    {
        $decision = app(AdobeProductAttributeClassifier::class)->classify($this->field(
            'cost', frontendInput: 'price', backendType: 'decimal', isUserDefined: true,
            backendModel: 'Magento\\Catalog\\Model\\Product\\Attribute\\Backend\\Price',
        ));

        $this->assertSame(ConnectorSchemaFieldDisposition::SystemOrDedicatedOwner, $decision->disposition);
        $this->assertSame('pricing', $decision->runtimeOwnerHint);
    }

    public function test_known_provider_standard_is_visible_but_not_claimed_as_verified_mapping(): void
    {
        $decision = app(AdobeProductAttributeClassifier::class)->classify($this->field(
            'visibility', frontendInput: 'select', backendType: 'int', isUserDefined: false,
            sourceModel: 'Magento\\Catalog\\Model\\Product\\Visibility',
        ));

        $this->assertSame(ConnectorSchemaFieldDisposition::ProviderStandard, $decision->disposition);
        $this->assertNull($decision->canonicalCode);
    }

    public function test_different_custom_keys_with_same_semantics_reuse_behavior_class(): void
    {
        $classifier = app(AdobeProductAttributeClassifier::class);
        $first = $classifier->classify($this->field('merchant_color_a', sourceModel: 'Magento\\Eav\\Model\\Entity\\Attribute\\Source\\Table'));
        $second = $classifier->classify($this->field('merchant_color_b', sourceModel: 'Magento\\Eav\\Model\\Entity\\Attribute\\Source\\Table'));

        $this->assertSame(ConnectorSchemaFieldDisposition::WorkspaceCustom, $first->disposition);
        $this->assertSame($first->behaviorClass, $second->behaviorClass);
        $this->assertSame($first->behaviorSignature, $second->behaviorSignature);
    }

    public function test_behavior_class_changes_when_behavior_changing_metadata_changes(): void
    {
        $classifier = app(AdobeProductAttributeClassifier::class);
        $single = $classifier->classify($this->field('merchant_choice', sourceModel: 'Magento\\Eav\\Model\\Entity\\Attribute\\Source\\Table'));
        $multi = $classifier->classify($this->field('merchant_choice', frontendInput: 'multiselect', normalizedType: 'multi_select', multi: true, sourceModel: 'Magento\\Eav\\Model\\Entity\\Attribute\\Source\\Table'));

        $this->assertNotSame($single->behaviorClass, $multi->behaviorClass);
    }

    public function test_verified_account_specific_channel_decision_keeps_color_and_manufacturer_out_of_canonical_mapping(): void
    {
        $classifier = app(AdobeProductAttributeClassifier::class);

        foreach (['color', 'manufacturer'] as $key) {
            $decision = $classifier->classify($this->field(
                $key,
                frontendInput: 'select',
                normalizedType: 'select',
                isUserDefined: true,
                sourceModel: 'Magento\\Eav\\Model\\Entity\\Attribute\\Source\\Table',
            ));

            $this->assertSame(ConnectorSchemaFieldDisposition::WorkspaceCustom, $decision->disposition, $key);
            $this->assertNull($decision->canonicalCode, $key);
            $this->assertSame('verified_channel_account_specific', $decision->reasonCode, $key);
        }
    }

    public function test_verified_deferred_channel_decision_recognizes_meta_fields_without_claiming_verified_mapping(): void
    {
        $classifier = app(AdobeProductAttributeClassifier::class);

        foreach ([
            'meta_title' => ['text', 'text'],
            'meta_description' => ['textarea', 'long_text'],
        ] as $key => [$frontendInput, $normalizedType]) {
            $decision = $classifier->classify($this->field(
                $key,
                frontendInput: $frontendInput,
                normalizedType: $normalizedType,
                isUserDefined: false,
                backendType: 'varchar',
            ));

            $this->assertSame(ConnectorSchemaFieldDisposition::CanonicalPlatform, $decision->disposition, $key);
            $this->assertSame($key, $decision->canonicalCode, $key);
            $this->assertSame('channel_deferred', $decision->mappingStrategy, $key);
            $this->assertSame('verified_channel_decision_deferred', $decision->reasonCode, $key);
        }
    }

    public function test_is_user_defined_is_disposition_evidence_not_behavior_identity(): void
    {
        $classifier = app(AdobeProductAttributeClassifier::class);
        $merchant = $classifier->classify($this->field('merchant_choice', isUserDefined: true));
        $provider = $classifier->classify($this->field('unproven_provider_choice', isUserDefined: false));

        $this->assertSame($merchant->behaviorClass, $provider->behaviorClass);
        $this->assertArrayNotHasKey('is_user_defined', $merchant->behaviorSignature ?? []);
    }

    public function test_dedicated_runtime_owner_is_part_of_behavior_identity(): void
    {
        $classifier = app(AdobeProductAttributeClassifier::class);
        $dedicated = $classifier->classify($this->field(
            'cost', frontendInput: 'price', normalizedType: 'money', isUserDefined: true,
            backendModel: 'Magento\\Catalog\\Model\\Product\\Attribute\\Backend\\Price', backendType: 'decimal',
        ));
        $generic = $classifier->classify($this->field(
            'merchant_cost_like', frontendInput: 'price', normalizedType: 'money', isUserDefined: true,
            backendModel: 'Magento\\Catalog\\Model\\Product\\Attribute\\Backend\\Price', backendType: 'decimal',
        ));

        $this->assertNotSame($dedicated->behaviorClass, $generic->behaviorClass);
        $this->assertSame('pricing', $dedicated->behaviorSignature['runtime_owner'] ?? null);
        $this->assertSame('generic_field_candidate', $generic->behaviorSignature['runtime_owner'] ?? null);
    }

    public function test_unknown_third_party_special_model_fails_closed_to_review(): void
    {
        $decision = app(AdobeProductAttributeClassifier::class)->classify($this->field(
            'merchant_special', sourceModel: 'Vendor\\Module\\Model\\Source\\Special',
        ));

        $this->assertSame(ConnectorSchemaFieldDisposition::ReviewNeeded, $decision->disposition);
        $this->assertSame('third_party_special_model', $decision->reasonCode);
    }

    public function test_service_only_provider_field_is_inventoried_as_dedicated_not_silently_dropped(): void
    {
        $decision = app(AdobeProductAttributeClassifier::class)->classify($this->field(
            'has_options', frontendInput: null, normalizedType: null, isUserDefined: false,
            normalizationStatus: 'unclassified',
        ));

        $this->assertSame(ConnectorSchemaFieldDisposition::SystemOrDedicatedOwner, $decision->disposition);
        $this->assertSame('system', $decision->runtimeOwnerHint);
    }

    public function test_unknown_unclassified_field_is_local_review_not_snapshot_failure(): void
    {
        $decision = app(AdobeProductAttributeClassifier::class)->classify($this->field(
            'new_unknown_attribute', frontendInput: 'future_widget', normalizedType: null,
            normalizationStatus: 'unclassified',
        ));

        $this->assertSame(ConnectorSchemaFieldDisposition::ReviewNeeded, $decision->disposition);
        $this->assertSame('semantic_normalization_failed', $decision->reasonCode);
    }

    public function test_target_only_keys_without_provider_evidence_fail_closed_to_review(): void
    {
        $classifier = app(AdobeProductAttributeClassifier::class);

        foreach (['quantity_and_stock_status', 'old_id', 'custom_layout_update_file', 'tier_price', 'custom_layout'] as $key) {
            $decision = $classifier->classify($this->field($key, isUserDefined: false));

            $this->assertSame(ConnectorSchemaFieldDisposition::ReviewNeeded, $decision->disposition, $key);
            $this->assertSame('provider_ownership_not_proven', $decision->reasonCode, $key);
        }
    }

    private function field(
        string $key,
        ?string $frontendInput = 'select',
        ?string $normalizedType = 'select',
        bool $isUserDefined = true,
        ?string $sourceModel = null,
        ?string $backendModel = null,
        string $backendType = 'int',
        bool $multi = false,
        string $normalizationStatus = 'normalized',
    ): ConnectorSchemaSnapshotField {
        $metadata = [
            'frontend_input' => $frontendInput,
            'scope' => 'global',
            'backend_type' => $backendType,
            'source_model' => $sourceModel,
            'backend_model' => $backendModel,
            'is_user_defined' => $isUserDefined,
            'apply_to' => [],
        ];

        $field = new ConnectorSchemaSnapshotField;
        $field->forceFill([
            'external_field_key' => $key,
            'normalization_status' => $normalizationStatus,
            'normalization_failure_reason' => $normalizationStatus === 'normalized' ? null : 'unmapped_value',
            'normalized_data_type' => $normalizedType,
            'is_required' => false,
            'is_multi_value' => $multi,
            'is_localizable' => false,
            'external_scope' => $normalizedType === null ? null : 'global',
            'normalized_payload' => [
                'options' => [['value' => '1'], ['value' => '2']],
                'provider_metadata' => $metadata,
                'provider_metadata_version' => 'adobe.product_attribute.v1',
            ],
        ]);

        return $field;
    }
}
