# 08 — Connector & Sync Runtime Atlas

**Current-state implementation index — not normative architecture.**

```text
[Resolved] normative docs
        ↓
actual current code / migrations / tests
        ↓
Atlas as locator/index
```

This Atlas must never override either normative decisions or verified runtime truth.

It is a:

- current-state capability map;
- physical ownership locator;
- test locator;
- reuse-intent map.

It is **not** a backlog, changelog, historical narrative, replacement for `IMPLEMENTATION_GAPS.md`, new Domain Model, or Magento specification.

Completed capabilities remain here. Only their previous status is replaced. Git owns history.

**Initial Atlas extraction baseline:** `develop` @ `87104f5f503a6e5c0af8046f668cc2bb74998729` (2026-08-16).

This records initial provenance only. It is not a claim that every Atlas row was globally reverified at that SHA after every future PR. Subsequent freshness is maintained through same-PR touched-seam replacement and Git history. Do not add per-row SHA bureaucracy.

**Same-PR rule:** any Connector/Sync PR that materially changes an Atlas-listed seam must update the affected Atlas entry in the same PR. Only touched seams require re-verification.

---

## Status vocabulary

| Status | Meaning |
|---|---|
| `IMPLEMENTED` | Verified in current code, migrations, and/or tests |
| `RESOLVED — NOT IMPLEMENTED` | Normative contract exists; runtime is absent |
| `CONFIRMED ABSENT` | Verified not present in current code |
| `DORMANT / SCAFFOLDING` | Schema/model exists without a working write path or consumer |

Implementation status is never mixed with reuse intent.

Reuse-intent markers:

| Marker | Meaning |
|---|---|
| `platform-reusable` | Seam belongs to generic Product/Sync core |
| `connector-owned` | Vendor/profile concept; must not enter Product core |
| `family-hypothesis` | First-connector reusable seam; refine when a second real connector family proves a new general requirement |

A reuse-intent marker does **not** mean implementation exists.

---

## Capability map

| Capability | Status | Layer | Owner(s) | Persistence | Key test(s) | Normative contract | Reuse intent |
|---|---|---|---|---|---|---|---|
| Workspace RBAC catalogue (9 atomic permissions) | IMPLEMENTED | Core | `app/Support/Workspace/WorkspacePermissions.php`; `app/Services/Workspace/WorkspaceAuthorization.php`; `database/seeders/WorkspaceRbacPermissionSeeder.php` | `workspace_permissions` | `tests/Feature/WorkspaceRbacCatalogueSeederTest.php`; `tests/Feature/WorkspaceAuthorizationTest.php`; `tests/Feature/Sync/RunSyncPreviewPermissionTest.php`; `tests/Feature/Sync/Stage2A1SyncConfigurationSetupTest.php` | `docs/03-DOMAIN_MODEL.md` → Workspace RBAC; Preview execution permission | platform-reusable |
| `run_sync_preview` permission | IMPLEMENTED | Core | `app/Support/Workspace/WorkspacePermissions.php`; `database/seeders/WorkspaceRbacPermissionSeeder.php` | `workspace_permissions` | `tests/Feature/Sync/RunSyncPreviewPermissionTest.php`; `tests/Feature/Sync/SyncPreviewAdmissionTest.php` | `docs/03-DOMAIN_MODEL.md` → Preview execution permission | platform-reusable |
| `manage_sync_configurations` permission | IMPLEMENTED (Stage 2A-1) | Core | `app/Support/Workspace/WorkspacePermissions.php`; `database/seeders/WorkspaceRbacPermissionSeeder.php` | `workspace_permissions` | `tests/Feature/Sync/Stage2A1SyncConfigurationSetupTest.php` | `docs/03-DOMAIN_MODEL.md` → Merchant Preview Authorization & Remediation Contract | platform-reusable |
| Merchant Preview work surface (Stage 2A-2) | IMPLEMENTED | Connector UX | `app/Filament/Pages/Sync/ManageAdobeProductsExportPreview.php`; `app/Filament/Pages/Sync/ListSyncDataSetup.php`; `app/Services/Sync/SyncPreviewMerchantReadService.php`; `app/Support/Sync/Preview/Presentation/SyncPreviewFindingPresenter.php` | reads `sync_runs`; `sync_run_items` | `tests/Feature/Sync/Stage2A2MerchantPreviewWorkSurfaceTest.php` | `docs/03-DOMAIN_MODEL.md` → Merchant Preview Authorization & Remediation Contract | Adobe Products Export first slice |
| `run_sync_live` permission | RESOLVED — NOT IMPLEMENTED | Core | *(absent)* | none | `tests/Feature/Sync/PlatformProductScopeAndConnectorAtlasDocumentationContractTest.php` | `docs/03-DOMAIN_MODEL.md` → Magento Product Export V1 / Live authority | platform-reusable |
| Product | IMPLEMENTED | Product Domain | `app/Models/Product.php`; `database/migrations/2024_06_01_100001_create_products_table.php` | `products` | `tests/Feature/CoreFieldNamingMigrationTest.php` | `docs/03-DOMAIN_MODEL.md` → Product | platform-reusable |
| ProductVariant (0..N) | IMPLEMENTED | Product Domain | `app/Models/ProductVariant.php`; `database/migrations/2024_06_01_100002_create_product_variants_table.php` | `product_variants` | `tests/Feature/Sync/SyncRunPersistenceFoundationTest.php` | `docs/03-DOMAIN_MODEL.md` → ProductVariant; Platform Product Capability Baseline | platform-reusable |
| FieldDefinition | IMPLEMENTED | Product Domain | `app/Models/FieldDefinition.php` | `field_definitions` | `tests/Feature/FieldMatrixPageTest.php` | `docs/03-DOMAIN_MODEL.md` → Field Dictionary | platform-reusable |
| FieldBinding | IMPLEMENTED | Product Domain | `app/Models/FieldBinding.php` | `field_bindings` | `tests/Feature/Sync/FieldMappingPersistenceTest.php` | `docs/03-DOMAIN_MODEL.md` → FieldBinding | platform-reusable |
| Dynamic product/variant values | IMPLEMENTED | Product Domain | `app/Models/ProductFieldValue.php`; `app/Models/VariantFieldValue.php` | `product_field_values`; `variant_field_values` | `tests/Feature/FieldMatrixPageTest.php` | `docs/03-DOMAIN_MODEL.md` → Hybrid Field Storage | platform-reusable |
| Pricing / PriceResolver | IMPLEMENTED | Product Domain | `app/Services/Pricing/PriceResolver.php` | `price_lists`; `price_list_items`; variant price caches | `tests/Feature/PriceInspectorTest.php` | `docs/03-DOMAIN_MODEL.md` → PriceResolver | platform-reusable |
| Availability / AvailabilityResolver | IMPLEMENTED | Product Domain | `app/Services/Availability/AvailabilityResolver.php` | `inventory_records`; variant availability caches | `tests/Unit/AvailabilityServicesTest.php` | `docs/03-DOMAIN_MODEL.md` → AvailabilityResolver | platform-reusable |
| Current media persistence | IMPLEMENTED | Product Domain | `app/Models/Product.php` (`images` JSON); `database/migrations/2024_06_01_100001_create_products_table.php` | `products.images` | `tests/Feature/ListProductsViewActionTest.php` | `docs/03-DOMAIN_MODEL.md` → Media; Platform Product Capability Baseline | platform-reusable |
| Legacy `products.onec_guid` / `product_variants.onec_guid` | IMPLEMENTED (legacy physical columns); canonical classification `external_identity` | Current physical owner: Product / ProductVariant legacy columns; target semantic owner: ExternalRecordLink | `app/Models/Product.php`; `app/Models/ProductVariant.php`; `database/migrations/2024_06_01_100001_create_products_table.php` | `products.onec_guid`; `product_variants.onec_guid` | `tests/Feature/Sync/PlatformProductScopeAndConnectorAtlasDocumentationContractTest.php` | DEC-011; GAP-007 | connector-owned identity debt |
| First-class MediaAsset / ProductMedia / VariantMedia | CONFIRMED ABSENT | Product Domain | *(no models)* | none | `tests/Feature/Sync/PlatformProductScopeAndConnectorAtlasDocumentationContractTest.php` | `docs/03-DOMAIN_MODEL.md` → Media | platform-reusable |
| ConnectorDefinition | IMPLEMENTED | Connector | `app/Models/ConnectorDefinition.php` | `connector_definitions` | `tests/Feature/ConnectorDefinitionResourceTest.php` | `docs/03-DOMAIN_MODEL.md` → ConnectorDefinition | platform-reusable |
| ConnectorAccount | IMPLEMENTED | Connector | `app/Models/ConnectorAccount.php`; `app/Services/Connectors/ConnectorAccountSettingsService.php` | `connector_accounts` | `tests/Feature/Connectors/ConnectorAccountSettingsServiceTest.php` | `docs/03-DOMAIN_MODEL.md` → ConnectorAccount | platform-reusable |
| ConnectorAccount create UI | IMPLEMENTED | Connector | `app/Filament/Pages/Integrations/ConnectPlatformIntegration.php` | `connector_accounts` | `tests/Feature/Connectors/Integrations/IntegrationsPageTest.php` | `docs/CONNECTOR_INTEGRATION_UX_CONTRACT.md` | platform-reusable |
| ConnectorAccount credential/settings edit UI | CONFIRMED ABSENT | Connector | update path exists in `app/Services/Connectors/ConnectorAccountSettingsService.php`; no Filament edit page | `connector_accounts` | `tests/Feature/Connectors/ConnectorAccountSettingsServiceTest.php` | `docs/CONNECTOR_INTEGRATION_UX_CONTRACT.md` | platform-reusable |
| Profile / auth (Adobe PaaS OAuth1) | IMPLEMENTED | Connector | `app/Support/Connectors/AdobePaaS/AdobePaaSConnectorAdapter.php`; `app/Support/Connectors/ConnectorProfileRegistry.php` | encrypted credentials on `connector_accounts` | `tests/Feature/Connectors/ConnectorAccountSettingsServiceTest.php` | `docs/03-DOMAIN_MODEL.md` → Connector profile | connector-owned |
| Transport / SSRF | IMPLEMENTED | Connector | `app/Support/Connectors/AdobePaaS/AdobePaaSBaseUrl.php` | none | `tests/Feature/Connectors/AdobePaaSConnectionCheckServiceTest.php` | `docs/04-ARCHITECTURE_PRINCIPLES.md` → Connector operational security | platform-reusable |
| Connection Check | IMPLEMENTED | Connector | `app/Jobs/Connectors/ConnectorConnectionCheckJob.php`; `app/Services/Connectors/ConnectorConnectionCheckDispatchService.php` | `connector_connection_checks` | `tests/Feature/Connectors/ConnectorConnectionCheckDispatchServiceTest.php` | `docs/03-DOMAIN_MODEL.md` → ConnectorConnectionCheck | platform-reusable |
| Discovery | IMPLEMENTED | Connector | `app/Jobs/Connectors/ConnectorDiscoveryRunJob.php`; `app/Services/Connectors/AdobePaaSDiscoveryService.php` | `connector_discovery_runs` | `tests/Feature/Connectors/DiscoverySmokeTestHarnessTest.php` | `docs/03-DOMAIN_MODEL.md` → ConnectorDiscoveryRun | platform-reusable |
| Schema snapshot | IMPLEMENTED | Connector | `app/Models/ConnectorSchemaSnapshot.php`; `app/Support/Connectors/AdobePaaS/AdobePaaSAttributeNormalizer.php` | `connector_schema_snapshots`; `connector_schema_snapshot_fields` | `tests/Feature/Connectors/AdobePaaSCanonicalHashDiscoveryIntegrationTest.php` | `docs/03-DOMAIN_MODEL.md` → ConnectorSchemaSnapshot | platform-reusable |
| Schema diff | DORMANT / SCAFFOLDING | Connector | `app/Models/ConnectorSchemaDiff.php`; `app/Models/ConnectorSchemaDiffItem.php` | `connector_schema_diffs` (no write path) | `tests/Feature/ConnectorAccountDocumentationTest.php` | `docs/03-DOMAIN_MODEL.md` → ConnectorSchemaDiff | platform-reusable |
| FieldMapping | IMPLEMENTED | Connector | `app/Models/FieldMapping.php`; `app/Filament/Pages/Sync/ManageSyncFieldMappings.php` | `field_mappings` | `tests/Feature/Sync/FieldMappingPersistenceTest.php`; `tests/Feature/Sync/ManageSyncFieldMappingsPageTest.php` | `docs/03-DOMAIN_MODEL.md` → FieldMapping | platform-reusable |
| FieldOptionMapping | IMPLEMENTED | Connector | `app/Models/FieldOptionMapping.php`; `app/Services/Sync/FieldOptionMappingMutationService.php` | `field_option_mappings` | `tests/Feature/Sync/FieldOptionMappingTest.php` | `docs/03-DOMAIN_MODEL.md` → FieldOptionMapping persistence contract | platform-reusable / family-hypothesis |
| SyncConfiguration | IMPLEMENTED | Connector | `app/Models/SyncConfiguration.php`; `app/Services/Sync/SyncConfigurationService.php`; `app/Services/Sync/SyncConfigurationReachabilityService.php` | `sync_configurations`; `sync_configurations.connector_execution_configuration` | `tests/Feature/Sync/SyncConfigurationFoundationTest.php`; `tests/Feature/Sync/SyncPreviewAdmissionTest.php` | `docs/03-DOMAIN_MODEL.md` → SyncConfiguration; E5 Adobe attribute-set ownership | platform-reusable |
| SyncConfiguration merchant reachability | IMPLEMENTED (mutating ensure path) | Connector | `app/Services/Sync/SyncConfigurationReachabilityService.php` | `sync_configurations` | `tests/Feature/Sync/SyncPreviewAdmissionTest.php` | `docs/03-DOMAIN_MODEL.md` → E7 SyncConfiguration merchant reachability | platform-reusable |
| SyncConfiguration non-mutating existence lookup | IMPLEMENTED (Stage 2A-1) | Connector | `app/Services/Sync/SyncConfigurationLookupService.php` | `sync_configurations` (read-only) | `tests/Feature/Sync/Stage2A1SyncConfigurationSetupTest.php` | `docs/03-DOMAIN_MODEL.md` → Merchant Preview Authorization & Remediation Contract | platform-reusable |
| Adobe Products Export merchant setup (Layer B) | IMPLEMENTED (Stage 2A-1) | Connector | `app/Filament/Pages/Sync/ListSyncDataSetup.php`; `app/Filament/Pages/Sync/ManageAdobeProductsExportSetup.php`; `app/Services/Sync/AdobeProductExportSetupAuthorizationService.php`; `app/Support/Connectors/ConnectorAccountLayerBSetupProjection.php` | `sync_configurations.connector_execution_configuration` | `tests/Feature/Sync/Stage2A1SyncConfigurationSetupTest.php` | `docs/03-DOMAIN_MODEL.md` → E5 Adobe attribute-set ownership | connector-owned setup; platform-reusable authority |
| Configuration revision v4 | IMPLEMENTED | Connector | `app/Support/Sync/SyncConfigurationRevisionHasher.php`; `database/migrations/2026_08_17_120000_sync_configuration_revision_v4.php` | `sync_configurations.configuration_revision` | `tests/Unit/Sync/SyncConfigurationRevisionHasherTest.php`; `tests/Feature/Sync/FieldOptionMappingTest.php` | `docs/03-DOMAIN_MODEL.md` → Revision v4 | platform-reusable |
| SyncConfiguration connector execution configuration | IMPLEMENTED | Connector | `app/Support/Sync/ConnectorExecutionConfiguration.php`; `app/Models/SyncConfiguration.php` | `sync_configurations.connector_execution_configuration` | `tests/Unit/Sync/SyncConfigurationRevisionHasherTest.php`; `tests/Unit/Sync/ConnectorExecutionConfigurationTest.php`; `tests/Feature/Sync/FieldOptionMappingTest.php` | `docs/03-DOMAIN_MODEL.md` → E5 connector-owned semantics via opaque JSON | platform-reusable persistence; connector-owned validation |
| Adobe Product Export execution configuration | IMPLEMENTED | Connector | `app/Support/Connectors/AdobePaaS/AdobeProductExportExecutionConfiguration.php`; `app/Services/Sync/AdobeProductExportSetupService.php`; `app/Services/Sync/AdobeProductExportSetupAuthorizationService.php` | `sync_configurations.connector_execution_configuration` | `tests/Unit/Connectors/AdobePaaS/AdobeProductExportMetadataReaderTest.php`; `tests/Unit/Connectors/AdobePaaS/AdobeProductExportSetupServiceTest.php`; `tests/Feature/Sync/Stage2A1SyncConfigurationSetupTest.php` | `docs/03-DOMAIN_MODEL.md` → E5 Adobe attribute-set ownership | connector-owned |
| SyncPreview connector capability | IMPLEMENTED | Connector | `app/Support/Sync/Preview/SyncPreviewConnectorCapability.php`; `app/Support/Sync/Preview/SyncPreviewConnectorCapabilityResolver.php`; `config/connectors.php` `preview_capability` | none | `tests/Feature/Sync/SyncPreviewExecutionTest.php`; `tests/Support/Sync/TestSyncPreviewCapability.php` | `docs/03-DOMAIN_MODEL.md` → Preview execution | platform-reusable |
| Preview configuration readiness | IMPLEMENTED | Connector | `app/Support/Sync/Preview/SyncPreviewConfigurationReadinessResolver.php`; `app/Support/Sync/Preview/SyncPreviewConfigurationReadinessPort.php` | none | `tests/Feature/Sync/SyncPreviewAdmissionTest.php` | `docs/03-DOMAIN_MODEL.md` → Run admission | platform-reusable |
| FieldOptionMapping option validation | IMPLEMENTED | Connector | `app/Services/Sync/FieldOptionMappingOptionValidatorResolver.php`; `app/Support/Sync/FieldOptionMappingOptionValidator.php`; `app/Services/Sync/FieldDefinitionInternalOptionValidator.php` (Product + ProductVariant single-select); profile registration via `config/connectors.php` `field_option_mapping_validator` | `field_option_mappings` | `tests/Feature/Sync/FieldOptionMappingTest.php`; `tests/Unit/Connectors/AdobePaaS/AdobeProductExportMetadataReaderTest.php` | `docs/03-DOMAIN_MODEL.md` → FieldOptionMapping persistence contract | platform-reusable port; profile-owned validator |
| SyncRun persistence | IMPLEMENTED | Connector | `app/Models/SyncRun.php`; `database/migrations/2026_08_16_110000_sync_run_persistence.php` | `sync_runs` | `tests/Feature/Sync/SyncRunPersistenceFoundationTest.php` | `docs/03-DOMAIN_MODEL.md` → SyncRun first physical contract | platform-reusable |
| SyncRunItem persistence (`product_id`) | IMPLEMENTED | Connector | `app/Models/SyncRunItem.php`; `database/migrations/2026_08_16_110000_sync_run_persistence.php` | `sync_run_items` | `tests/Feature/Sync/SyncRunPersistenceFoundationTest.php` | `docs/03-DOMAIN_MODEL.md` → SyncRunItem identity | platform-reusable |
| Execution support (mode-aware) | IMPLEMENTED | Connector | `app/Support/Connectors/ConnectorSyncOperationSupport.php`; `app/Support/Connectors/ConnectorSyncSupportResolver.php`; `app/Support/Connectors/AdobePaaS/AdobePaaSConnectorAdapter.php` | none | `tests/Feature/Sync/ModeAwareSyncSupportTest.php`; `tests/Feature/Sync/SyncConfigurationFoundationTest.php` | `docs/03-DOMAIN_MODEL.md` → E6 Execution support truth | platform-reusable / family-hypothesis |
| Mode-aware execution support (Preview vs Live) | IMPLEMENTED | Connector | `app/Support/Connectors/ConnectorSyncOperationSupport.php`; `app/Support/Connectors/AdobePaaS/AdobePaaSConnectorAdapter.php` | none | `tests/Feature/Sync/ModeAwareSyncSupportTest.php` | `docs/03-DOMAIN_MODEL.md` → Magento V1 / Execution support truth | platform-reusable |
| Preview admission | IMPLEMENTED | Connector | `app/Services/Sync/SyncPreviewAdmissionService.php`; `app/Services/Sync/SyncPreviewConfigurationSnapshotBuilder.php` | `sync_runs.configuration_snapshot` | `tests/Feature/Sync/SyncPreviewAdmissionTest.php`; `tests/Integration/MySql/SyncPreviewAdmissionConcurrencyMySqlTest.php` | `docs/03-DOMAIN_MODEL.md` → Run admission | platform-reusable |
| Product execution aggregate | IMPLEMENTED | Product Domain | `app/Support/Sync/Preview/ProductExecutionAggregateBuilder.php` — mapped descriptors retained when value is null | none — read-only execution input | `tests/Feature/Sync/ProductExecutionAggregateTest.php` | `docs/03-DOMAIN_MODEL.md` → E1 Generic Product execution input | platform-reusable / family-hypothesis |
| Adobe Products/Export planner | IMPLEMENTED | Connector | `app/Support/Connectors/AdobePaaS/AdobeProductExportPreviewPlanner.php` — selected-set truth for all mapped fields; Select option projection; visibility/status semantics; per-child `resolved_configurable_values`; Stage-1 logical parent reference (no persistent parent SKU) | none | `tests/Feature/Sync/AdobeProductExportPreviewPlannerTest.php`; `tests/Feature/Sync/SyncPreviewExecutionTest.php` | `docs/03-DOMAIN_MODEL.md` → Pure connector-owned Preview planner | connector-owned |
| Adobe Product Export metadata reader | IMPLEMENTED | Connector | `app/Support/Connectors/AdobePaaS/AdobeProductExportMetadataReader.php` — set membership + bounded `GET /V1/products/attributes/{code}` detail enrichment for mapped keys only | none (read-only HTTP seam) | `tests/Unit/Connectors/AdobePaaS/AdobeProductExportMetadataReaderTest.php`; `tests/Feature/Sync/AdobeProductExportPreviewPlannerTest.php` | `docs/03-DOMAIN_MODEL.md` → Magento V1 Preview metadata | connector-owned |
| Preview background execution job | IMPLEMENTED | Connector | `app/Jobs/Connectors/SyncPreviewRunJob.php` | `sync_runs`; `sync_run_items` | `tests/Feature/Sync/SyncPreviewExecutionTest.php` | `docs/03-DOMAIN_MODEL.md` → Preview execution | platform-reusable |
| ExternalRecordLink | CONFIRMED ABSENT | Connector | class does not exist (`tests/Feature/Sync/SyncRunPersistenceFoundationTest.php` asserts absence); configurable parent SKU / persistent Adobe identity deferred to Stage-3 Live revalidation | none | `tests/Feature/Sync/SyncRunPersistenceFoundationTest.php` | `docs/03-DOMAIN_MODEL.md` → E9 generic ExternalRecordLink | platform-reusable / family-hypothesis |
| Live execution | CONFIRMED ABSENT | Connector | *(absent)* | none | `tests/Feature/Sync/PreviewSyncExecutionFoundationDocumentationContractTest.php` | `docs/03-DOMAIN_MODEL.md` → Magento V1 Live safety | connector-owned transport; platform-reusable outcomes |
| Queues / connector lane | IMPLEMENTED | Operations | `config/queue.php`; `app/Jobs/Connectors/ConnectorDiscoveryRunJob.php`; `app/Jobs/Connectors/ConnectorConnectionCheckJob.php`; `app/Jobs/Connectors/SyncPreviewRunJob.php` | `jobs` / `database_connectors` | `tests/Feature/Connectors/ConnectorConnectionCheckDispatchServiceTest.php`; `tests/Feature/Sync/SyncPreviewExecutionTest.php` | `DEPLOY.md`; `docs/07-TECH_STACK.md` | platform-reusable |
| Deployment / RBAC cutover | IMPLEMENTED | Operations | `DEPLOY.md` | host Supervisor programs | `tests/Feature/Gap026b2DocumentationTruthSyncContractTest.php` | `DEPLOY.md` | operational |

---

## Reuse-stress-test matrix

Reference families — not an exhaustive roadmap.

| Seam | IMPLEMENTATION STATUS | 1C (ERP) | Adobe Commerce | Shopify / BigCommerce | Google Merchant / Rozetka | Google Sheets / CSV | Reuse intent |
|---|---|---|---|---|---|---|---|
| Product + 0..N ProductVariant aggregate | IMPLEMENTED | required | required (simple + configurable) | required (Product/ProductVariant) | offer/item projection | row may flatten; core stays Product | platform-reusable / family-hypothesis |
| FieldDefinition / FieldBinding / mapping | IMPLEMENTED | required | required | required | required | required | platform-reusable |
| PriceResolver as only price path | IMPLEMENTED | required | required | required | required | required | platform-reusable |
| AvailabilityResolver | IMPLEMENTED | required | optional for V1 export | required later | feed availability | optional | platform-reusable |
| Run admission / one active run | IMPLEMENTED | required | required | required | required | required | platform-reusable / family-hypothesis |
| Normalized Preview/Live outcomes | IMPLEMENTED (Preview only) | required | required | required | required | required | platform-reusable / family-hypothesis |
| Mode-aware execution support | IMPLEMENTED | required | required | required | required | required | platform-reusable / family-hypothesis |
| ExternalRecordLink (account-scoped) | CONFIRMED ABSENT | required | required (parent + children) | required (GraphQL GIDs) | channel offer ids | optional | platform-reusable / family-hypothesis |
| `attribute_set_id` / `type_id` / visibility | IMPLEMENTED as connector execution configuration (not Product fields) | n/a | connector-owned | n/a | n/a | n/a | connector-owned |
| Shopify GraphQL IDs | CONFIRMED ABSENT | n/a | n/a | connector-owned | n/a | n/a | connector-owned |
| Google Merchant feed vocabulary | CONFIRMED ABSENT in Product core | n/a | n/a | n/a | connector-owned | n/a | connector-owned |
| 1C GUID / `onec_guid` as Product/System field | CONFIRMED ABSENT as generic Product core (physical columns remain legacy) | connector-owned identity | n/a | n/a | n/a | connector-owned |

### Reusability principle

During the first full connector implementation, a seam that is demonstrated to belong to the platform and would be required by materially different connector families should be made reusable before a vendor-specific public/runtime contract hardens.

Vendor-specific concepts must remain connector-owned and must not be promoted into generic Product/Sync core merely because the first connector requires them.

### Rule-of-three / revision expectation

First-connector reusable seams are validated as platform hypotheses against materially different reference connector families. Their architecture may be refined when the second real connector reveals new general requirements.

Refinement must preserve existing domain invariants where possible. Do not preserve a bad abstraction merely because Magento implemented it first.

---

## Maintenance

Typical Atlas maintenance is a few-row edit in the same Connector/Sync PR:

1. Did this PR touch an Atlas capability?
2. Did current owner/status change?
3. Was a reusable seam introduced?
4. Is any stale `CONFIRMED ABSENT` / `RESOLVED — NOT IMPLEMENTED` state left?

Only affected rows are updated. Do not require a full Atlas reread per PR.

Mechanical coverage: documentation-contract tests verify that declared `app/`, `database/`, `config/`, and `tests/` owner paths exist. That proves referential integrity only — not semantic freshness.
