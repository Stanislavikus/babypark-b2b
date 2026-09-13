<?php

namespace Tests\Feature\Connectors;

use App\Models\AdobeProductAttributeGroup;
use App\Models\AdobeProductAttributeLineage;
use App\Models\AdobeProductAttributeOptionLineage;
use App\Models\AdobeProductAttributeSet;
use App\Models\AdobeProductAttributeSetMembership;
use App\Services\Connectors\AdobeProductAttributeStructureReconciler;
use App\Support\Connectors\Transport\ConnectorHttpResult;
use App\Support\Connectors\Transport\ConnectorHttpTransport;
use App\Support\Connectors\Transport\ConnectorOutboundRequest;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\Support\Connectors\RecordingConnectorHttpTransport;
use Tests\TestCase;

class AdobeProductAttributeStructureReconcilerTest extends TestCase
{
    use CreatesConnectorAccountFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceSeeder::class);
        $this->seed(ConnectorFoundationSeeder::class);
    }

    #[Test]
    public function reconciliation_is_idempotent_and_tracks_rename_delete_readd_by_provider_id(): void
    {
        $account = $this->createConnectorAccount();
        $phase = 1;
        $transport = new RecordingConnectorHttpTransport(
            function (ConnectorOutboundRequest $request) use (&$phase): ConnectorHttpResult {
                return $this->responseFor((string) $request->request->getUri(), $phase);
            },
        );
        $this->app->instance(ConnectorHttpTransport::class, $transport);
        $reconciler = app(AdobeProductAttributeStructureReconciler::class);

        $first = $reconciler->reconcile($account->workspace_id, $account->id);
        $lineage100 = $this->lineage($account->id, 100);
        $lineage101 = $this->lineage($account->id, 101);
        $option10 = $this->option($account->id, $lineage100->id, '10');
        $group7 = $this->group($account->id, 7);

        $this->assertSame(2, $first->attributes);
        $this->assertSame(1, $first->attributeSets);
        $this->assertSame(1, $first->attributeGroups);
        $this->assertSame(2, $first->setMemberships);
        $this->assertSame(1, $first->options);
        $this->assertSame('merchant_old', $lineage100->current_external_field_key);
        $this->assertSame('Red', $option10->default_label);
        $this->assertSame(['default' => 'Red'], $option10->labels_by_store);

        $second = $reconciler->reconcile($account->workspace_id, $account->id);

        $this->assertSame($lineage100->id, $this->lineage($account->id, 100)->id);
        $this->assertSame($option10->id, $this->option($account->id, $lineage100->id, '10')->id);
        $this->assertSame($group7->id, $this->group($account->id, 7)->id);
        $this->assertSame(2, AdobeProductAttributeLineage::withoutWorkspaceScope()->where('connector_account_id', $account->id)->count());
        $this->assertSame(1, AdobeProductAttributeOptionLineage::withoutWorkspaceScope()->where('connector_account_id', $account->id)->count());
        $this->assertSame(2, $second->setMemberships);

        $phase = 2;
        $reconciler->reconcile($account->workspace_id, $account->id);
        $renamed = $this->lineage($account->id, 100);

        $this->assertSame($lineage100->id, $renamed->id);
        $this->assertSame('merchant_new', $renamed->current_external_field_key);
        $this->assertNull($renamed->missing_since);
        $this->assertNotNull($this->lineage($account->id, 101)->missing_since);
        $this->assertSame('Crimson', $this->option($account->id, $renamed->id, '10')->default_label);
        $this->assertSame('Renamed Group', $this->group($account->id, 7)->name);

        $phase = 3;
        $reconciler->reconcile($account->workspace_id, $account->id);
        $old = $this->lineage($account->id, 100);
        $replacement = $this->lineage($account->id, 200);

        $this->assertNotNull($old->missing_since);
        $this->assertNull($old->current_external_field_key);
        $this->assertSame('merchant_new', $old->last_external_field_key);
        $this->assertNotSame($old->id, $replacement->id);
        $this->assertSame('merchant_new', $replacement->current_external_field_key);
        $this->assertSame(3, AdobeProductAttributeLineage::withoutWorkspaceScope()->where('connector_account_id', $account->id)->count());
        $this->assertSame(1, AdobeProductAttributeLineage::withoutWorkspaceScope()->where('connector_account_id', $account->id)->whereNull('missing_since')->count());
        $this->assertSame(1, AdobeProductAttributeSet::withoutWorkspaceScope()->where('connector_account_id', $account->id)->whereNull('missing_since')->count());
        $this->assertSame(1, AdobeProductAttributeGroup::withoutWorkspaceScope()->where('connector_account_id', $account->id)->whereNull('missing_since')->count());
        $this->assertSame(1, AdobeProductAttributeSetMembership::withoutWorkspaceScope()->where('connector_account_id', $account->id)->whereNull('missing_since')->count());
        $this->assertSame('Ruby', $this->option($account->id, $replacement->id, '10')->default_label);
        $this->assertFalse(collect($transport->recordedRequests)->contains(
            static fn (ConnectorOutboundRequest $request): bool => str_contains((string) $request->request->getUri(), '/options'),
        ));
    }

    #[Test]
    public function one_thousand_attributes_reconcile_as_data_across_five_pages_without_option_n_plus_one(): void
    {
        $account = $this->createConnectorAccount();
        $all = [];

        for ($i = 1; $i <= 1000; $i++) {
            $selectable = $i % 20 === 0;
            $all[] = $this->attribute(
                1000 + $i,
                'merchant_'.$i,
                $selectable ? 'select' : 'text',
                $selectable ? [['value' => (string) $i, 'label' => 'Option '.$i]] : [],
            );
        }

        $transport = new RecordingConnectorHttpTransport(function (ConnectorOutboundRequest $request) use ($all): ConnectorHttpResult {
            $uri = (string) $request->request->getUri();

            if (str_contains($uri, '/V1/products/attributes?')) {
                parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
                $page = (int) ($query['searchCriteria']['currentPage'] ?? 1);
                $items = array_slice($all, ($page - 1) * 200, 200);

                return $this->responseJson(['items' => $items, 'total_count' => 1000]);
            }

            if (str_contains($uri, '/attribute-sets/sets/list')) {
                return $this->responseJson([
                    'items' => [['attribute_set_id' => 4, 'attribute_set_name' => 'Default']],
                    'total_count' => 1,
                ]);
            }

            if (str_contains($uri, '/attribute-sets/groups/list')) {
                return $this->responseJson(['items' => [], 'total_count' => 0]);
            }

            if (str_contains($uri, '/attribute-sets/4/attributes')) {
                return $this->responseJson(array_map(
                    static fn (array $attribute): array => [
                        'attribute_id' => $attribute['attribute_id'],
                        'attribute_code' => $attribute['attribute_code'],
                    ],
                    $all,
                ));
            }

            return new ConnectorHttpResult(404, [], '{}');
        });
        $this->app->instance(ConnectorHttpTransport::class, $transport);

        $result = app(AdobeProductAttributeStructureReconciler::class)->reconcile($account->workspace_id, $account->id);

        $this->assertSame(1000, $result->attributes);
        $this->assertSame(1000, $result->setMemberships);
        $this->assertSame(50, $result->options);
        $this->assertSame(1000, AdobeProductAttributeLineage::withoutWorkspaceScope()->where('connector_account_id', $account->id)->whereNull('missing_since')->count());
        $this->assertSame(50, AdobeProductAttributeOptionLineage::withoutWorkspaceScope()->where('connector_account_id', $account->id)->whereNull('missing_since')->count());
        $this->assertSame(8, $transport->sendCount);
        $this->assertFalse(collect($transport->recordedRequests)->contains(
            static fn (ConnectorOutboundRequest $request): bool => str_contains((string) $request->request->getUri(), '/options'),
        ));
    }

    #[Test]
    public function missing_inline_options_uses_fallback_endpoint_but_valid_empty_list_does_not(): void
    {
        $account = $this->createConnectorAccount();
        $transport = new RecordingConnectorHttpTransport(function (ConnectorOutboundRequest $request): ConnectorHttpResult {
            $uri = (string) $request->request->getUri();

            if (str_contains($uri, '/V1/products/attributes?')) {
                return $this->responseJson([
                    'items' => [
                        [
                            'attribute_id' => 100,
                            'attribute_code' => 'needs_fallback',
                            'frontend_input' => 'select',
                        ],
                        $this->attribute(101, 'valid_empty', 'select', []),
                    ],
                    'total_count' => 2,
                ]);
            }

            if (str_contains($uri, '/products/attributes/needs_fallback/options')) {
                return $this->responseJson([
                    ['value' => '', 'label' => ' '],
                    ['value' => '55', 'label' => 'Fallback'],
                ]);
            }

            if (str_contains($uri, '/attribute-sets/sets/list')) {
                return $this->responseJson([
                    'items' => [['attribute_set_id' => 4, 'attribute_set_name' => 'Default']],
                    'total_count' => 1,
                ]);
            }

            if (str_contains($uri, '/attribute-sets/groups/list')) {
                return $this->responseJson(['items' => [], 'total_count' => 0]);
            }

            if (str_contains($uri, '/attribute-sets/4/attributes')) {
                return $this->responseJson([
                    ['attribute_id' => 100, 'attribute_code' => 'needs_fallback'],
                    ['attribute_id' => 101, 'attribute_code' => 'valid_empty'],
                ]);
            }

            return new ConnectorHttpResult(404, [], '{}');
        });
        $this->app->instance(ConnectorHttpTransport::class, $transport);

        $result = app(AdobeProductAttributeStructureReconciler::class)->reconcile($account->workspace_id, $account->id);
        $uris = collect($transport->recordedRequests)->map(
            static fn (ConnectorOutboundRequest $request): string => (string) $request->request->getUri(),
        );

        $this->assertSame(1, $result->options);
        $this->assertTrue($uris->contains(static fn (string $uri): bool => str_contains($uri, '/needs_fallback/options')));
        $this->assertFalse($uris->contains(static fn (string $uri): bool => str_contains($uri, '/valid_empty/options')));
    }

    #[Test]
    public function remote_failure_publishes_no_partial_structure(): void
    {
        $account = $this->createConnectorAccount();
        $transport = new RecordingConnectorHttpTransport(function (ConnectorOutboundRequest $request): ConnectorHttpResult {
            $uri = (string) $request->request->getUri();

            if (str_contains($uri, '/V1/products/attributes?')) {
                return $this->responseJson([
                    'items' => [$this->attribute(100, 'merchant_text', 'text', [])],
                    'total_count' => 1,
                ]);
            }

            if (str_contains($uri, '/attribute-sets/sets/list')) {
                return $this->responseJson([
                    'items' => [['attribute_set_id' => 4, 'attribute_set_name' => 'Default']],
                    'total_count' => 1,
                ]);
            }

            return new ConnectorHttpResult(500, [], '{}');
        });
        $this->app->instance(ConnectorHttpTransport::class, $transport);

        try {
            app(AdobeProductAttributeStructureReconciler::class)->reconcile($account->workspace_id, $account->id);
            $this->fail('Remote failure must abort before structure publication.');
        } catch (\RuntimeException) {
            $this->assertTrue(true);
        }

        $this->assertStructureTablesEmpty();
    }

    #[Test]
    public function target_change_during_remote_read_aborts_publication(): void
    {
        $account = $this->createConnectorAccount();
        $changed = false;
        $transport = new RecordingConnectorHttpTransport(function (ConnectorOutboundRequest $request) use ($account, &$changed): ConnectorHttpResult {
            $uri = (string) $request->request->getUri();

            if (str_contains($uri, '/V1/products/attributes?')) {
                if (! $changed) {
                    DB::table('connector_accounts')->where('id', $account->id)->update(['store_code' => 'changed']);
                    $changed = true;
                }

                return $this->responseJson([
                    'items' => [$this->attribute(100, 'merchant_text', 'text', [])],
                    'total_count' => 1,
                ]);
            }

            if (str_contains($uri, '/attribute-sets/sets/list')) {
                return $this->responseJson([
                    'items' => [['attribute_set_id' => 4, 'attribute_set_name' => 'Default']],
                    'total_count' => 1,
                ]);
            }

            if (str_contains($uri, '/attribute-sets/groups/list')) {
                return $this->responseJson(['items' => [], 'total_count' => 0]);
            }

            if (str_contains($uri, '/attribute-sets/4/attributes')) {
                return $this->responseJson([['attribute_id' => 100, 'attribute_code' => 'merchant_text']]);
            }

            return new ConnectorHttpResult(404, [], '{}');
        });
        $this->app->instance(ConnectorHttpTransport::class, $transport);

        try {
            app(AdobeProductAttributeStructureReconciler::class)->reconcile($account->workspace_id, $account->id);
            $this->fail('Changed connector target must abort structure publication.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('target changed', $exception->getMessage());
        }

        $this->assertStructureTablesEmpty();
    }

    private function responseFor(string $uri, int $phase): ConnectorHttpResult
    {
        if (str_contains($uri, '/V1/products/attributes?')) {
            $items = match ($phase) {
                1 => [
                    $this->attribute(100, 'merchant_old', 'select', [
                        ['value' => '', 'label' => ' '],
                        ['value' => '10', 'label' => 'Red'],
                    ]),
                    $this->attribute(101, 'merchant_empty_select', 'select', []),
                ],
                2 => [
                    $this->attribute(100, 'merchant_new', 'select', [
                        ['value' => '', 'label' => ' '],
                        ['value' => '10', 'label' => 'Crimson'],
                    ]),
                ],
                default => [
                    $this->attribute(200, 'merchant_new', 'select', [
                        ['value' => '', 'label' => ' '],
                        ['value' => '10', 'label' => 'Ruby'],
                    ]),
                ],
            };

            return $this->responseJson(['items' => $items, 'total_count' => count($items)]);
        }

        if (str_contains($uri, '/attribute-sets/sets/list')) {
            return $this->responseJson([
                'items' => [[
                    'attribute_set_id' => 4,
                    'attribute_set_name' => 'Default',
                    'entity_type_id' => 4,
                ]],
                'total_count' => 1,
            ]);
        }

        if (str_contains($uri, '/attribute-sets/groups/list')) {
            return $this->responseJson([
                'items' => [
                    [
                        'attribute_group_id' => '7',
                        'attribute_group_name' => $phase >= 2 ? 'Renamed Group' : 'General',
                        'attribute_set_id' => 4,
                    ],
                    [
                        'attribute_group_id' => '99',
                        'attribute_group_name' => 'Foreign Entity Group',
                        'attribute_set_id' => 99,
                    ],
                ],
                'total_count' => 2,
            ]);
        }

        if (str_contains($uri, '/attribute-sets/4/attributes')) {
            $items = match ($phase) {
                1 => [
                    ['attribute_id' => 100, 'attribute_code' => 'merchant_old'],
                    ['attribute_id' => 101, 'attribute_code' => 'merchant_empty_select'],
                ],
                2 => [['attribute_id' => 100, 'attribute_code' => 'merchant_new']],
                default => [['attribute_id' => 200, 'attribute_code' => 'merchant_new']],
            };

            return $this->responseJson($items);
        }

        return new ConnectorHttpResult(404, [], '{}');
    }

    /** @param list<array{value:string, label:string}> $options */
    private function attribute(int $id, string $code, string $frontendInput, array $options): array
    {
        return [
            'attribute_id' => $id,
            'attribute_code' => $code,
            'frontend_input' => $frontendInput,
            'scope' => 'global',
            'is_required' => false,
            'options' => $options,
        ];
    }

    private function responseJson(array $payload): ConnectorHttpResult
    {
        return new ConnectorHttpResult(
            200,
            [],
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
    }

    private function assertStructureTablesEmpty(): void
    {
        $this->assertSame(0, AdobeProductAttributeLineage::withoutWorkspaceScope()->count());
        $this->assertSame(0, AdobeProductAttributeSet::withoutWorkspaceScope()->count());
        $this->assertSame(0, AdobeProductAttributeGroup::withoutWorkspaceScope()->count());
        $this->assertSame(0, AdobeProductAttributeSetMembership::withoutWorkspaceScope()->count());
        $this->assertSame(0, AdobeProductAttributeOptionLineage::withoutWorkspaceScope()->count());
    }

    private function lineage(string $accountId, int $providerId): AdobeProductAttributeLineage
    {
        return AdobeProductAttributeLineage::withoutWorkspaceScope()
            ->where('connector_account_id', $accountId)
            ->where('provider_attribute_id', $providerId)
            ->firstOrFail();
    }

    private function group(string $accountId, int $providerId): AdobeProductAttributeGroup
    {
        return AdobeProductAttributeGroup::withoutWorkspaceScope()
            ->where('connector_account_id', $accountId)
            ->where('provider_attribute_group_id', $providerId)
            ->firstOrFail();
    }

    private function option(string $accountId, string $lineageId, string $providerOptionId): AdobeProductAttributeOptionLineage
    {
        return AdobeProductAttributeOptionLineage::withoutWorkspaceScope()
            ->where('connector_account_id', $accountId)
            ->where('adobe_product_attribute_lineage_id', $lineageId)
            ->where('provider_option_id', $providerOptionId)
            ->firstOrFail();
    }
}
