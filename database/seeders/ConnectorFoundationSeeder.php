<?php

namespace Database\Seeders;

use App\Enums\ConnectorDefinitionStatus;
use App\Enums\ConnectorDirection;
use App\Enums\ConnectorSchemaAcquisitionMode;
use App\Enums\ConnectorSchemaScope;
use App\Enums\ConnectorSchemaSourceKind;
use App\Enums\ConnectorSchemaVerificationStatus;
use App\Models\ConnectorDefinition;
use App\Models\ConnectorSchemaSource;
use Illuminate\Database\Seeder;

class ConnectorFoundationSeeder extends Seeder
{
    public function run(): void
    {
        $definitions = [
            [
                'code' => 'adobe_commerce',
                'name' => 'Adobe Commerce',
                'direction' => ConnectorDirection::Both,
                'status' => ConnectorDefinitionStatus::Active,
            ],
            [
                'code' => 'shopify',
                'name' => 'Shopify',
                'direction' => ConnectorDirection::Both,
                'status' => ConnectorDefinitionStatus::Active,
            ],
            [
                'code' => 'google_merchant',
                'name' => 'Google Merchant Center',
                'direction' => ConnectorDirection::Export,
                'status' => ConnectorDefinitionStatus::Active,
            ],
            [
                'code' => 'bigcommerce',
                'name' => 'BigCommerce',
                'direction' => ConnectorDirection::Both,
                'status' => ConnectorDefinitionStatus::Draft,
            ],
            [
                'code' => 'csv',
                'name' => 'CSV',
                'direction' => ConnectorDirection::Import,
                'status' => ConnectorDefinitionStatus::Draft,
            ],
            [
                'code' => 'google_sheets',
                'name' => 'Google Sheets',
                'direction' => ConnectorDirection::Import,
                'status' => ConnectorDefinitionStatus::Draft,
            ],
            [
                'code' => '1c',
                'name' => '1C',
                'direction' => ConnectorDirection::Both,
                'status' => ConnectorDefinitionStatus::Draft,
            ],
        ];

        foreach ($definitions as $definitionData) {
            ConnectorDefinition::query()->firstOrCreate(
                ['code' => $definitionData['code']],
                [
                    'name' => $definitionData['name'],
                    'direction' => $definitionData['direction'],
                    'status' => $definitionData['status'],
                ],
            );
        }

        $this->seedAdobeCommerceSources();
        $this->seedShopifySources();
        $this->seedGoogleMerchantSources();
    }

    private function seedAdobeCommerceSources(): void
    {
        $definition = ConnectorDefinition::query()->where('code', 'adobe_commerce')->firstOrFail();

        $sources = [
            [
                'code' => 'admin_rest_api',
                'label' => 'Admin REST API reference',
                'source_kind' => ConnectorSchemaSourceKind::ApiSchema,
                'acquisition_mode' => ConnectorSchemaAcquisitionMode::RemoteStatic,
                'schema_scope' => ConnectorSchemaScope::Global,
                'reference_url' => 'https://adobe-commerce.redoc.ly/2.4.9-admin/tag/products/',
                'endpoint_path' => null,
                'schema_version' => '2.4.9-admin',
                'is_primary' => true,
                'verification_status' => ConnectorSchemaVerificationStatus::Verified,
                'last_verified_at' => '2026-07-16 00:00:00',
                'sort_order' => 10,
            ],
            [
                'code' => 'live_account_attributes',
                'label' => 'Live account attributes',
                'source_kind' => ConnectorSchemaSourceKind::AccountApi,
                'acquisition_mode' => ConnectorSchemaAcquisitionMode::LiveFetch,
                'schema_scope' => ConnectorSchemaScope::Account,
                'reference_url' => 'https://experienceleague.adobe.com/en/docs/commerce-admin/systems/data-transfer/data-attributes-product',
                'endpoint_path' => '/V1/products/attributes',
                'schema_version' => '2.4.9-admin',
                'is_primary' => true,
                'verification_status' => ConnectorSchemaVerificationStatus::Verified,
                'last_verified_at' => '2026-07-16 00:00:00',
                'sort_order' => 20,
            ],
        ];

        $this->seedSourcesIfMissing($definition, $sources);
    }

    private function seedShopifySources(): void
    {
        $definition = ConnectorDefinition::query()->where('code', 'shopify')->firstOrFail();

        $sources = [
            [
                'code' => 'product_taxonomy_attributes',
                'label' => 'Shopify Standard Product Taxonomy — attributes.yml',
                'source_kind' => ConnectorSchemaSourceKind::RepositoryDocument,
                'acquisition_mode' => ConnectorSchemaAcquisitionMode::RemoteStatic,
                'schema_scope' => ConnectorSchemaScope::Global,
                'reference_url' => 'https://github.com/Shopify/product-taxonomy/blob/main/data/attributes.yml',
                'endpoint_path' => null,
                'schema_version' => 'unversioned',
                'is_primary' => true,
                'verification_status' => ConnectorSchemaVerificationStatus::Verified,
                'last_verified_at' => '2026-07-16 00:00:00',
                'sort_order' => 10,
            ],
            [
                'code' => 'admin_graphql_taxonomy_value',
                'label' => 'Shopify Admin GraphQL API — TaxonomyValue',
                'source_kind' => ConnectorSchemaSourceKind::OfficialWebDoc,
                'acquisition_mode' => ConnectorSchemaAcquisitionMode::RemoteStatic,
                'schema_scope' => ConnectorSchemaScope::Global,
                'reference_url' => 'https://shopify.dev/docs/api/admin-graphql/latest/objects/TaxonomyValue',
                'endpoint_path' => null,
                'schema_version' => 'unversioned',
                'is_primary' => false,
                'verification_status' => ConnectorSchemaVerificationStatus::Verified,
                'last_verified_at' => '2026-07-16 00:00:00',
                'sort_order' => 20,
            ],
            [
                'code' => 'admin_rest_product',
                'label' => 'Shopify Admin REST API — Product',
                'source_kind' => ConnectorSchemaSourceKind::ApiSchema,
                'acquisition_mode' => ConnectorSchemaAcquisitionMode::RemoteStatic,
                'schema_scope' => ConnectorSchemaScope::Global,
                'reference_url' => 'https://shopify.dev/docs/api/admin-rest/latest/resources/product',
                'endpoint_path' => null,
                'schema_version' => '2024-10',
                'is_primary' => false,
                'verification_status' => ConnectorSchemaVerificationStatus::Verified,
                'last_verified_at' => '2026-07-16 00:00:00',
                'sort_order' => 30,
            ],
        ];

        $this->seedSourcesIfMissing($definition, $sources);
    }

    private function seedGoogleMerchantSources(): void
    {
        $definition = ConnectorDefinition::query()->where('code', 'google_merchant')->firstOrFail();

        $sources = [
            [
                'code' => 'product_data_spec',
                'label' => 'Google Merchant product data specification',
                'source_kind' => ConnectorSchemaSourceKind::OfficialWebDoc,
                'acquisition_mode' => ConnectorSchemaAcquisitionMode::RemoteStatic,
                'schema_scope' => ConnectorSchemaScope::Global,
                'reference_url' => 'https://support.google.com/merchants/answer/7052112',
                'endpoint_path' => null,
                'schema_version' => 'unversioned',
                'is_primary' => true,
                'verification_status' => ConnectorSchemaVerificationStatus::Verified,
                'last_verified_at' => '2026-07-15 00:00:00',
                'sort_order' => 10,
            ],
        ];

        $this->seedSourcesIfMissing($definition, $sources);
    }

    /**
     * @param  list<array<string, mixed>>  $sources
     */
    private function seedSourcesIfMissing(ConnectorDefinition $definition, array $sources): void
    {
        foreach ($sources as $sourceData) {
            ConnectorSchemaSource::query()->firstOrCreate(
                [
                    'connector_definition_id' => $definition->id,
                    'code' => $sourceData['code'],
                ],
                array_merge($sourceData, [
                    'connector_definition_id' => $definition->id,
                ]),
            );
        }
    }
}
