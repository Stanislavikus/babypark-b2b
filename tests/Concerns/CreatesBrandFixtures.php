<?php

namespace Tests\Concerns;

use App\Models\Brand;
use App\Models\Workspace;

trait CreatesBrandFixtures
{
    protected function brandFixture(Workspace|string $workspace, string $name, bool $active = true): Brand
    {
        $workspaceId = $workspace instanceof Workspace ? (string) $workspace->id : $workspace;

        return Brand::withoutWorkspaceScope()->firstOrCreate(
            [
                'workspace_id' => $workspaceId,
                'name' => $name,
            ],
            [
                'is_active' => $active,
            ],
        );
    }

    /** @return array{brand_id:string} */
    protected function brandAttributes(Workspace|string $workspace, string $name): array
    {
        return ['brand_id' => (string) $this->brandFixture($workspace, $name)->id];
    }
}
