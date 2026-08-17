<?php

namespace Tests\Feature\Sync;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlatformProductScopeAndConnectorAtlasDocumentationContractTest extends TestCase
{
    #[Test]
    public function project_documentation_map_includes_atlas(): void
    {
        $map = File::get(base_path('docs/Project_Documentation_Map.md'));

        $this->assertStringContainsString('## 08-CONNECTOR_SYNC_RUNTIME_ATLAS.md', $map);
        $this->assertStringContainsString('Current-state implementation index — not normative architecture.', $map);
        $this->assertStringContainsString('Atlas is same-PR maintained', $map);
        $this->assertStringContainsString('must still be verified in code before', $map);
    }

    #[Test]
    public function atlas_declares_itself_current_state_and_non_normative(): void
    {
        $atlas = File::get(base_path('docs/08-CONNECTOR_SYNC_RUNTIME_ATLAS.md'));

        $this->assertStringContainsString('Current-state implementation index — not normative architecture.', $atlas);
        $this->assertStringContainsString('must never override either normative decisions or verified runtime truth', $atlas);
        $this->assertStringContainsString('It is **not** a backlog, changelog, historical narrative', $atlas);
    }

    #[Test]
    public function working_agreement_contains_same_pr_touched_seam_rule(): void
    {
        $agreement = File::get(base_path('docs/05-AI_WORKING_AGREEMENT.md'));

        $this->assertStringContainsString('## Atlas impact check', $agreement);
        $this->assertStringContainsString('must update', $agreement);
        $this->assertStringContainsString('the affected Atlas entry in the same PR', $agreement);
        $this->assertStringContainsString('Did this PR touch an Atlas capability?', $agreement);
        $this->assertStringContainsString('Only affected rows are updated', $agreement);
    }

    #[Test]
    public function atlas_distinguishes_status_from_reuse_intent(): void
    {
        $atlas = File::get(base_path('docs/08-CONNECTOR_SYNC_RUNTIME_ATLAS.md'));

        $this->assertStringContainsString('IMPLEMENTED', $atlas);
        $this->assertStringContainsString('RESOLVED — NOT IMPLEMENTED', $atlas);
        $this->assertStringContainsString('CONFIRMED ABSENT', $atlas);
        $this->assertStringContainsString('DORMANT / SCAFFOLDING', $atlas);
        $this->assertStringContainsString('Implementation status is never mixed with reuse intent.', $atlas);
        $this->assertStringContainsString('A reuse-intent marker does **not** mean implementation exists.', $atlas);
        $this->assertStringContainsString('| Reuse intent |', $atlas);
    }

    #[Test]
    public function atlas_owner_paths_actually_exist(): void
    {
        $atlas = File::get(base_path('docs/08-CONNECTOR_SYNC_RUNTIME_ATLAS.md'));
        preg_match_all('/`((?:app|database|config|tests)\/[^`]+)`/', $atlas, $matches);

        $this->assertNotEmpty($matches[1], 'Atlas must declare at least one repository owner path');

        $missing = [];
        foreach (array_unique($matches[1]) as $path) {
            if (! File::exists(base_path($path))) {
                $missing[] = $path;
            }
        }

        $this->assertSame([], $missing, 'Atlas owner paths must exist on disk: '.implode(', ', $missing));
    }

    #[Test]
    public function customer_pilot_names_are_absent_from_normative_product_domain_positioning(): void
    {
        $files = [
            'docs/00-WHY.md',
            'docs/01-PRODUCT_VISION.md',
            'docs/02-ATTRIBUTE_DICTIONARY.md',
            'docs/04-ARCHITECTURE_PRINCIPLES.md',
            'docs/05-AI_WORKING_AGREEMENT.md',
            'docs/Project_Documentation_Map.md',
        ];

        foreach ($files as $path) {
            $content = File::get(base_path($path));
            $this->assertDoesNotMatchRegularExpression(
                '/BabyPark|Babypark|babypark/i',
                $content,
                "Named customer/pilot must be absent from normative positioning in {$path}",
            );
        }

        $this->assertStringContainsString(
            'Reference clients validate the platform; they do not define the platform.',
            File::get(base_path('docs/01-PRODUCT_VISION.md')),
        );
        $this->assertStringNotContainsString('Babypark Pilot Scope', File::get(base_path('docs/01-PRODUCT_VISION.md')));
    }

    #[Test]
    public function product_capability_contract_does_not_define_architecture_around_a_named_customer(): void
    {
        $section = $this->platformProductCapabilitySection();

        $this->assertDoesNotMatchRegularExpression('/BabyPark|Babypark|babypark/i', $section);
        $this->assertStringContainsString('Reference clients validate the platform; they do not define the platform.', $section);
        $this->assertStringContainsString('not defined by a tiny fixed field list', $section);
        $this->assertStringContainsString('heterogeneous e-commerce catalogues', $section);
    }

    #[Test]
    public function product_plus_zero_to_n_variant_invariant_exists(): void
    {
        $section = $this->platformProductCapabilitySection();

        $this->assertStringContainsString('Product + Variant is a first-class invariant', $section);
        $this->assertStringContainsString('0..N ProductVariants', $section);
        $this->assertStringContainsString('one Product = one SKU', $section);
        $this->assertStringContainsString('Do not invent a fake default variant merely to simplify Magento', $section);
        $this->assertStringContainsString('Zero variants does not mean Magento configurable', $section);
        $this->assertStringContainsString('ordinary non-variant / single-sellable-unit Product → Magento simple', $section);
        $this->assertStringContainsString('Product with meaningful option variants → Magento configurable family', $section);
        $this->assertStringNotContainsString('0..N meaningful variants exports as a Magento configurable', $section);
    }

    #[Test]
    public function configurable_variant_product_family_is_first_class(): void
    {
        $section = $this->platformProductCapabilitySection();

        $this->assertStringContainsString('Configurable / variant product families are mandatory', $section);
        $this->assertStringContainsString('configurable / variant product family', $section);
        $this->assertStringContainsString('first-class platform capability', $section);
    }

    #[Test]
    public function variant_family_is_distinguished_from_bundle_kit_composition(): void
    {
        $section = $this->platformProductCapabilitySection();

        $this->assertStringContainsString('bundle / kit / composite product', $section);
        $this->assertStringContainsString('Both concepts are distinct platform capabilities', $section);
        $this->assertStringContainsString('Do not declare Magento Product Export V1 DONE while ordinary platform multi-variant Products are unsupported', $section);
    }

    #[Test]
    public function rich_product_assets_include_images_video_and_documents(): void
    {
        $section = $this->platformProductCapabilitySection();

        $this->assertStringContainsString('images', $section);
        $this->assertStringContainsString('video', $section);
        $this->assertStringContainsString('product manuals / instructions', $section);
        $this->assertStringContainsString('documents / PDFs', $section);
        $this->assertStringContainsString('PLATFORM CAPABILITY — NOT IN THIS CONNECTOR V1', $section);
    }

    #[Test]
    public function sync_run_item_remains_product(): void
    {
        $section = $this->magentoV1ContractSection();

        $this->assertStringContainsString('one SyncRunItem = one platform Product outcome', $section);
        $this->assertStringContainsString('Transport/vendor operation cardinality must not redefine', $section);
        $this->assertStringContainsString('SyncRunItem remains Product', $section);
    }

    #[Test]
    public function preview_and_live_support_are_independent(): void
    {
        $section = $this->magentoV1ContractSection();

        $this->assertStringContainsString('Preview never implies Live', $section);
        $this->assertStringContainsString('Preview and Live support are independent', $section);
        $this->assertStringContainsString('execution_mode', $section);
    }

    #[Test]
    public function preview_permission_cannot_authorize_live(): void
    {
        $section = $this->magentoV1ContractSection();

        $this->assertStringContainsString('Preview permission != Live permission', $section);
        $this->assertStringContainsString('`run_sync_preview` cannot authorize Live', $section);
        $this->assertStringContainsString('`run_sync_live`', $section);
        $this->assertStringContainsString('no role/job title implies permission', $section);
        $this->assertStringContainsString('no automatic legacy grant', $section);
    }

    #[Test]
    public function generic_product_aggregate_is_vendor_neutral(): void
    {
        $section = $this->magentoV1ContractSection();

        $this->assertStringContainsString('generic Product execution aggregate is vendor-neutral', $section);
        $this->assertStringContainsString('not a serialized Magento payload', $section);
        $this->assertStringContainsString('0..N ProductVariants', $section);
    }

    #[Test]
    public function price_resolver_remains_the_only_price_calculation_path(): void
    {
        $section = $this->magentoV1ContractSection();

        $this->assertStringContainsString('price → PriceResolver', $section);
        $this->assertStringContainsString('No connector-specific alternate pricing path', $section);
        $this->assertStringContainsString('PriceResolver remains the only price calculation path', $section);
    }

    #[Test]
    public function adobe_attribute_set_id_is_not_a_generic_product_field(): void
    {
        $section = $this->magentoV1ContractSection();

        $this->assertStringContainsString('Adobe `attribute_set_id` is not a generic Product field', $section);
        $this->assertStringContainsString('platform ProductType == Adobe `attribute_set_id`', $section);
        $this->assertStringContainsString('SyncConfiguration-owned connector execution configuration', $section);
        $this->assertStringContainsString('Do not persist `attribute_set_id` in `external_context`', $section);
    }

    #[Test]
    public function simple_and_configurable_are_in_magento_v1_done(): void
    {
        $section = $this->magentoV1ContractSection();

        $this->assertStringContainsString('simple Product export works', $section);
        $this->assertStringContainsString('multi-variant/configurable Product export works', $section);
        $this->assertStringContainsString('must support', $section);
        $this->assertStringContainsString('simple products', $section);
        $this->assertStringContainsString('configurable / multi-variant products', $section);
    }

    #[Test]
    public function live_ambiguous_mutation_cannot_be_blindly_retried(): void
    {
        $section = $this->magentoV1ContractSection();

        $this->assertStringContainsString('ambiguous consequential mutation is never blindly retried', $section);
        $this->assertStringContainsString('transport retry != business idempotency', $section);
        $this->assertStringContainsString('unknown/ambiguous states are', $section);
    }

    #[Test]
    public function detailed_live_mechanics_remain_revalidation_sensitive(): void
    {
        $section = $this->magentoV1ContractSection();

        $this->assertStringContainsString('mechanics NOT over-frozen', $section);
        $this->assertStringContainsString('revalidation-sensitive rather than falsely [Resolved]', $section);
        $this->assertStringContainsString('POST vs PUT', $section);
        $this->assertStringContainsString('Before Stage 3 Live implementation, revalidate these mechanics', $section);
    }

    #[Test]
    public function multi_store_scope_is_explicit(): void
    {
        $section = $this->magentoV1ContractSection();

        $this->assertStringContainsString('#### E12. Multi-store / store-view scope', $section);
        $this->assertStringContainsString('single/default store context only', $section);
        $this->assertStringContainsString('multiple store views are out of Magento V1', $section);
    }

    #[Test]
    public function deactivation_semantics_are_explicit(): void
    {
        $section = $this->magentoV1ContractSection();

        $this->assertStringContainsString('#### E13. Deactivation / removal semantics', $section);
        $this->assertStringContainsString('Disable/unpublish', $section);
        $this->assertStringContainsString('Do not delete the external resource', $section);
        $this->assertStringContainsString('Hard-delete propagation is **outside V1**', $section);
    }

    #[Test]
    public function implementation_stages_are_represented_consistently_in_implementation_gaps(): void
    {
        $gaps = File::get(base_path('docs/IMPLEMENTATION_GAPS.md'));

        $this->assertStringContainsString('**Stage 1 — Preview Engine**', $gaps);
        $this->assertStringContainsString('**Stage 2 — Merchant Preview**', $gaps);
        $this->assertStringContainsString('**Stage 3 — Live Engine**', $gaps);
        $this->assertStringContainsString('Historical tracking label', $gaps);
        $this->assertStringContainsString('Not a mandatory future PR boundary', $gaps);
        $this->assertStringContainsString('Current coherent Magento execution stages', $gaps);
        $this->assertStringContainsString('connector execution configuration persistence plus revision/snapshot rebaseline', $gaps);
        $this->assertStringContainsString('current revision v3 has no connector execution-configuration input', $gaps);
    }

    #[Test]
    public function working_agreement_freezes_customer_neutral_architecture(): void
    {
        $agreement = File::get(base_path('docs/05-AI_WORKING_AGREEMENT.md'));

        $this->assertStringContainsString('## Customer-neutral architecture', $agreement);
        $this->assertStringContainsString('No named customer or pilot may define', $agreement);
        $this->assertStringContainsString('variant cardinality', $agreement);
        $this->assertStringContainsString('Do not introduce new customer-specific runtime identifiers', $agreement);
    }

    #[Test]
    public function onec_guid_is_not_a_generic_core_model_property_product_system_field(): void
    {
        $row = $this->canonicalFieldRow('onec_guid');

        $this->assertSame('connector_only', $row['implementation_kind']);
        $this->assertSame('ConnectorMapping', $row['storage_owner']);
        $this->assertSame('no', $row['field_definition_eligibility']);
        $this->assertSame('not_applicable', $row['scope']);
        $this->assertSame('connector_mapping_only', $row['recommended_action']);
        $this->assertNotSame('core_model_property', $row['implementation_kind']);
        $this->assertNotSame('system', $row['scope']);
        $this->assertNotSame('keep_as_is', $row['recommended_action']);

        $registry = File::get(base_path('docs/CANONICAL_PRODUCT_FIELD_REGISTRY.md'));
        $this->assertStringContainsString('### DEC-011 — onec_guid is connector-owned identity', $registry);
        $this->assertStringContainsString('Do **not** create a FieldDefinition for it', $registry);

        $phase2 = File::get(base_path('docs/03-DOMAIN_MODEL.md'));
        $this->assertStringContainsString('legacy 1C connector identity', $phase2);
        $this->assertStringContainsString('not deferred System Fields', $phase2);
    }

    #[Test]
    public function generic_external_record_link_contract_does_not_freeze_magento_role_vocabulary(): void
    {
        $section = $this->externalRecordLinkGenericSection();

        $this->assertStringContainsString('workspace-safe', $section);
        $this->assertStringContainsString('ConnectorAccount-scoped', $section);
        $this->assertStringContainsString('internal business-record identity explicit', $section);
        $this->assertStringContainsString('no assumption one Product = one external resource', $section);
        $this->assertStringContainsString('Do not freeze an irreversible generic database unique key', $section);
        $this->assertStringNotContainsString('configurable_parent', $section);
        $this->assertStringNotContainsString('configurable_child', $section);
        $this->assertStringNotContainsString('simple |', $section);

        $adobeNotes = $this->adobeMagentoIdentityNotesSection();
        $this->assertStringContainsString('simple product', $adobeNotes);
        $this->assertStringContainsString('configurable parent', $adobeNotes);
        $this->assertStringContainsString('simple child', $adobeNotes);
        $this->assertStringContainsString('They are **not** generic ExternalRecordLink vocabulary.', $adobeNotes);
    }

    #[Test]
    public function atlas_uses_initial_extraction_provenance_wording(): void
    {
        $atlas = File::get(base_path('docs/08-CONNECTOR_SYNC_RUNTIME_ATLAS.md'));

        $this->assertStringContainsString('**Initial Atlas extraction baseline:**', $atlas);
        $this->assertStringContainsString('This records initial provenance only', $atlas);
        $this->assertStringContainsString('not a claim that every Atlas row was globally reverified', $atlas);
        $this->assertStringNotContainsString('**Verification baseline:**', $atlas);
    }

    #[Test]
    public function gap_007_distinguishes_connector_leakage_from_generic_seo_fields(): void
    {
        $section = $this->gap007Section();

        $this->assertStringContainsString('`products.rozetka_category_id`', $section);
        $this->assertStringContainsString('`products.onec_guid`', $section);
        $this->assertStringContainsString('Valid platform Product core that happens to be physical columns', $section);
        $this->assertStringContainsString('`products.meta_title`', $section);
        $this->assertStringContainsString('`products.meta_description`', $section);
        $this->assertStringContainsString('core_model_property', $section);
        $this->assertStringContainsString('Do not treat generic reusable SEO fields as connector-specific leakage', $section);
        $this->assertStringNotContainsString(
            'contains `rozetka_category_id`, `meta_title`, `meta_description` as native columns — a direct instance',
            $section,
        );
    }

    #[Test]
    public function stage_1_includes_execution_config_revision_and_snapshot_consequences(): void
    {
        $section = $this->magentoV1ContractSection();

        $this->assertStringContainsString('SyncConfiguration-owned connector execution configuration', $section);
        $this->assertStringContainsString('current revision v3 has no connector execution-configuration input', $section);
        $this->assertStringContainsString('revision-version change/rebaseline', $section);
        $this->assertStringContainsString('configuration_snapshot` inclusion', $section);
        $this->assertStringContainsString('Do not rediscover a hidden revision-v4 prerequisite', $section);
    }

    #[Test]
    public function safe_customer_specific_textual_residue_is_not_classified_as_migration_bound(): void
    {
        $gaps = File::get(base_path('docs/IMPLEMENTATION_GAPS.md'));

        $this->assertStringContainsString('**SAFE TEXTUAL/UI NEUTRALIZATION**', $gaps);
        $this->assertStringContainsString('https://babypark.ua/product/...', $gaps);
        $this->assertStringContainsString('Основне (з 1С)', $gaps);
        $this->assertStringContainsString('**not** hash/worker migration debt', $gaps);
        $this->assertStringContainsString('**LEGITIMATE CONNECTOR FAMILY NAME:**', $gaps);

        $this->assertDoesNotMatchRegularExpression(
            '/MIGRATION\/REBASELINE REQUIRED:[\s\S]*?babypark\.ua\/product/',
            $gaps,
        );
    }

    /**
     * @return array<string, string>
     */
    private function canonicalFieldRow(string $internalCode): array
    {
        $handle = fopen(base_path('docs/data/canonical_product_fields.csv'), 'r');
        $this->assertNotFalse($handle);
        $header = fgetcsv($handle);
        $this->assertIsArray($header);

        while (($data = fgetcsv($handle)) !== false) {
            $row = array_combine($header, $data);
            if ($row !== false && ($row['internal_code'] ?? null) === $internalCode) {
                fclose($handle);

                return $row;
            }
        }

        fclose($handle);
        $this->fail("Could not locate canonical field row {$internalCode}");
    }

    /**
     * @return non-empty-string
     */
    private function externalRecordLinkGenericSection(): string
    {
        $content = File::get(base_path('docs/03-DOMAIN_MODEL.md'));

        if (! preg_match(
            '/#### E9. ExternalRecordLink structural contract\n\n(.*?)(?=\n#### )/s',
            $content,
            $matches,
        )) {
            $this->fail('Could not locate generic ExternalRecordLink contract section');
        }

        return $matches[1];
    }

    /**
     * @return non-empty-string
     */
    private function adobeMagentoIdentityNotesSection(): string
    {
        $content = File::get(base_path('docs/03-DOMAIN_MODEL.md'));

        if (! preg_match(
            '/#### Adobe Magento V1 identity notes\n\n(.*?)(?=\n#### E10)/s',
            $content,
            $matches,
        )) {
            $this->fail('Could not locate Adobe Magento V1 identity notes');
        }

        return $matches[1];
    }

    /**
     * @return non-empty-string
     */
    private function gap007Section(): string
    {
        $content = File::get(base_path('docs/IMPLEMENTATION_GAPS.md'));

        if (! preg_match(
            '/## GAP-007 — Connector-specific columns leaked into core `products` table\n\n(.*?)(?=\n---\n\n## GAP-008 —)/s',
            $content,
            $matches,
        )) {
            $this->fail('Could not locate GAP-007 section');
        }

        return $matches[1];
    }

    /**
     * @return non-empty-string
     */
    private function platformProductCapabilitySection(): string
    {
        $content = File::get(base_path('docs/03-DOMAIN_MODEL.md'));

        if (! preg_match(
            '/### Platform Product Capability Baseline\n\[Resolved\]\n\n(.*?)(?=\n### Product Type)/s',
            $content,
            $matches,
        )) {
            $this->fail('Could not locate Platform Product Capability Baseline in 03-DOMAIN_MODEL.md');
        }

        return $matches[1];
    }

    /**
     * @return non-empty-string
     */
    private function magentoV1ContractSection(): string
    {
        $content = File::get(base_path('docs/03-DOMAIN_MODEL.md'));

        if (! preg_match(
            '/### Magento Product Export V1 Execution Contract\n\[Resolved — Platform Product Scope Rebaseline\]\n\n(.*?)(?=\n### Canonical mapping registry role)/s',
            $content,
            $matches,
        )) {
            $this->fail('Could not locate Magento Product Export V1 Execution Contract in 03-DOMAIN_MODEL.md');
        }

        return $matches[1];
    }
}
