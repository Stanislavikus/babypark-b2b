<?php

namespace Tests\Feature;

use App\Enums\ProductLifecycleStatus;
use App\Models\Product;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ProductLifecycleCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function legacy_boolean_create_and_update_stay_synchronized_with_lifecycle(): void
    {
        $workspace = Workspace::query()->where('is_default', true)->sole();

        $active = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => null,
            'name' => 'Legacy active product',
            'is_active' => true,
        ]);

        $archived = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => null,
            'name' => 'Legacy archived product',
            'is_active' => false,
        ]);

        $this->assertSame(ProductLifecycleStatus::Active, $active->lifecycle_status);
        $this->assertSame(ProductLifecycleStatus::Archived, $archived->lifecycle_status);

        $active->update(['is_active' => false]);
        $active->refresh();

        $this->assertFalse($active->is_active);
        $this->assertSame(ProductLifecycleStatus::Archived, $active->lifecycle_status);

        $active->update(['lifecycle_status' => ProductLifecycleStatus::Draft]);
        $active->refresh();

        $this->assertFalse($active->is_active);
        $this->assertSame(ProductLifecycleStatus::Draft, $active->lifecycle_status);

        $active->update(['lifecycle_status' => ProductLifecycleStatus::Active]);
        $active->refresh();

        $this->assertTrue($active->is_active);
        $this->assertSame(ProductLifecycleStatus::Active, $active->lifecycle_status);
    }
}
