<?php

namespace Tests\Feature\Sync;

use App\Models\AdobeProductCategory;
use App\Services\Connectors\AdobeProductCategoryCatalogueReconciler;
use App\Support\Connectors\AdobePaaS\EntityTrust\AdobeConnectorAccountTargetSnapshotResolver;
use App\Support\Connectors\AdobePaaS\RemoteCatalog\AdobeRemoteCatalogCategoryCatalogue;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\TestCase;

final class AdobeProductCategoryCatalogueReconcilerTest extends TestCase
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
    public function it_reconciles_per_account_preserves_identity_and_marks_missing_categories(): void
    {
        $account = $this->createConnectorAccount();
        $otherAccount = $this->createConnectorAccount();
        $service = app(AdobeProductCategoryCatalogueReconciler::class);
        $targetResolver = app(AdobeConnectorAccountTargetSnapshotResolver::class);

        $first = now()->subMinute()->toImmutable();
        $service->reconcile(
            $account,
            $targetResolver->resolve($account),
            new AdobeRemoteCatalogCategoryCatalogue($first, [
                $this->category('15', '3', 'Prepared', '1/3/15', 'Prepared', true, 2, 7),
                $this->category('16', '3', 'Temporary', '1/3/16', 'Temporary', true, 2, 8),
            ]),
        );
        $service->reconcile(
            $otherAccount,
            $targetResolver->resolve($otherAccount),
            new AdobeRemoteCatalogCategoryCatalogue($first, [
                $this->category('15', '3', 'Other account category', '1/3/15', 'Other account category', true, 2, 1),
            ]),
        );

        $original = AdobeProductCategory::withoutWorkspaceScope()
            ->where('workspace_id', $account->workspace_id)
            ->where('connector_account_id', $account->id)
            ->where('external_category_id', '15')
            ->sole();

        $second = now()->toImmutable();
        $service->reconcile(
            $account,
            $targetResolver->resolve($account),
            new AdobeRemoteCatalogCategoryCatalogue($second, [
                $this->category('15', '9', 'Prepared renamed', '1/3/9/15', 'New parent > Prepared renamed', false, 3, 2),
            ]),
        );

        $moved = AdobeProductCategory::withoutWorkspaceScope()
            ->where('workspace_id', $account->workspace_id)
            ->where('connector_account_id', $account->id)
            ->where('external_category_id', '15')
            ->sole();

        $this->assertSame($original->id, $moved->id);
        $this->assertSame('9', $moved->parent_external_category_id);
        $this->assertSame('Prepared renamed', $moved->name);
        $this->assertSame('1/3/9/15', $moved->provider_path);
        $this->assertSame('New parent > Prepared renamed', $moved->breadcrumb);
        $this->assertFalse($moved->is_active);
        $this->assertNull($moved->missing_since);

        $missing = AdobeProductCategory::withoutWorkspaceScope()
            ->where('workspace_id', $account->workspace_id)
            ->where('connector_account_id', $account->id)
            ->where('external_category_id', '16')
            ->sole();

        $this->assertNotNull($missing->missing_since);

        $other = AdobeProductCategory::withoutWorkspaceScope()
            ->where('workspace_id', $otherAccount->workspace_id)
            ->where('connector_account_id', $otherAccount->id)
            ->where('external_category_id', '15')
            ->sole();

        $this->assertSame('Other account category', $other->name);
        $this->assertTrue($other->is_active);
        $this->assertNull($other->missing_since);

        $this->assertDatabaseHas('adobe_product_category_catalogue_states', [
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'category_count' => 1,
        ]);
        $this->assertDatabaseHas('adobe_product_category_catalogue_states', [
            'workspace_id' => $otherAccount->workspace_id,
            'connector_account_id' => $otherAccount->id,
            'category_count' => 1,
        ]);
    }

    /**
     * @return array{
     *     external_category_id:string,
     *     parent_external_category_id:?string,
     *     name:?string,
     *     provider_path:?string,
     *     breadcrumb:string,
     *     level:int,
     *     position:?int,
     *     is_active:bool
     * }
     */
    private function category(
        string $id,
        ?string $parentId,
        ?string $name,
        ?string $providerPath,
        string $breadcrumb,
        bool $isActive,
        int $level,
        ?int $position,
    ): array {
        return [
            'external_category_id' => $id,
            'parent_external_category_id' => $parentId,
            'name' => $name,
            'provider_path' => $providerPath,
            'breadcrumb' => $breadcrumb,
            'level' => $level,
            'position' => $position,
            'is_active' => $isActive,
        ];
    }
}
