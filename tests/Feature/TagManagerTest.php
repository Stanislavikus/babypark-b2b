<?php

namespace Tests\Feature;

use App\Models\Tag;
use App\Models\Workspace;
use App\Services\Catalog\TagManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TagManagerTest extends TestCase
{
    use RefreshDatabase;

    private TagManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = app(TagManager::class);
    }

    public function test_create_persists_trimmed_name_in_workspace(): void
    {
        $workspace = Workspace::query()->where('is_default', true)->firstOrFail();

        $tag = $this->manager->create($workspace->id, '  Опт  ');

        $this->assertSame('Опт', $tag->name);
        $this->assertSame($workspace->id, $tag->workspace_id);
    }

    public function test_application_level_normalized_comparison_treats_case_variants_as_duplicates_in_same_workspace(): void
    {
        $workspace = Workspace::query()->where('is_default', true)->firstOrFail();

        $this->manager->create($workspace->id, 'Опт');

        try {
            $this->manager->create($workspace->id, 'опт');
            $this->fail('Expected duplicate validation exception.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('name', $e->errors());
            $this->assertSame('Тег із такою назвою вже існує.', $e->errors()['name'][0]);
        }
    }

    public function test_same_normalized_name_is_allowed_in_different_workspace(): void
    {
        $workspaceA = Workspace::query()->where('is_default', true)->firstOrFail();
        $workspaceB = Workspace::query()->create([
            'name' => 'Workspace B',
            'is_default' => false,
        ]);

        $tagA = $this->manager->create($workspaceA->id, 'Опт');
        $tagB = $this->manager->create($workspaceB->id, 'опт');

        $this->assertNotSame($tagA->id, $tagB->id);
        $this->assertSame('Опт', $tagA->name);
        $this->assertSame('опт', $tagB->name);
    }

    public function test_rename_rejects_tag_from_different_workspace(): void
    {
        $workspaceA = Workspace::query()->where('is_default', true)->firstOrFail();
        $workspaceB = Workspace::query()->create([
            'name' => 'Workspace B',
            'is_default' => false,
        ]);

        $tag = Tag::withoutWorkspaceScope()->create([
            'workspace_id' => $workspaceB->id,
            'name' => 'foreign',
        ]);

        $this->expectException(ValidationException::class);

        $this->manager->rename($workspaceA->id, $tag, 'renamed');
    }

    public function test_unique_constraint_violation_is_translated_to_friendly_validation_error(): void
    {
        $workspace = Workspace::query()->where('is_default', true)->firstOrFail();

        Tag::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'name' => 'forced-dup',
        ]);

        $manager = new class extends TagManager
        {
            protected function assertUniqueInWorkspace(string $workspaceId, string $displayName, ?Tag $except = null): void {}
        };

        try {
            $manager->create($workspace->id, 'forced-dup');
            $this->fail('Expected duplicate validation exception from database constraint.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('name', $e->errors());
            $this->assertSame('Тег із такою назвою вже існує.', $e->errors()['name'][0]);
        }
    }
}
