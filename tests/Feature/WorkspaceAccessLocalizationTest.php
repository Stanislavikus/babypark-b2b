<?php

namespace Tests\Feature;

use App\Filament\Pages\WorkspaceAccess\WorkspaceAccessMembersTable;
use App\Filament\Pages\WorkspaceAccess\WorkspaceAccessRolesTable;
use App\Models\User;
use App\Support\Workspace\WorkspacePermissions;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Database\Seeders\WorkspaceSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithWorkspaceRbac;
use Tests\TestCase;

class WorkspaceAccessLocalizationTest extends TestCase
{
    use InteractsWithWorkspaceRbac;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceSeeder::class);
        $this->seed(WorkspaceRbacPermissionSeeder::class);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    #[Test]
    public function page_metadata_translation_keys_resolve_for_supported_locales(): void
    {
        $expected = [
            'en' => [
                'workspace_access.page.navigation_group' => 'Settings',
                'workspace_access.page.navigation_label' => 'Access',
                'workspace_access.page.title' => 'Access',
            ],
            'uk' => [
                'workspace_access.page.navigation_group' => 'Налаштування',
                'workspace_access.page.navigation_label' => 'Доступ',
                'workspace_access.page.title' => 'Доступ',
            ],
            'ru' => [
                'workspace_access.page.navigation_group' => 'Настройки',
                'workspace_access.page.navigation_label' => 'Доступ',
                'workspace_access.page.title' => 'Доступ',
            ],
        ];

        foreach ($expected as $locale => $keys) {
            app()->setLocale($locale);

            foreach ($keys as $key => $value) {
                $this->assertSame($value, __($key), "Failed asserting {$key} for locale {$locale}");
            }
        }
    }

    #[Test]
    public function workspace_access_translation_keys_have_parity_across_locales(): void
    {
        $en = json_decode(File::get(lang_path('en.json')), true, 512, JSON_THROW_ON_ERROR);
        $uk = json_decode(File::get(lang_path('uk.json')), true, 512, JSON_THROW_ON_ERROR);
        $ru = json_decode(File::get(lang_path('ru.json')), true, 512, JSON_THROW_ON_ERROR);

        $keys = array_values(array_filter(
            array_keys($en),
            fn (string $key): bool => str_starts_with($key, 'workspace_access.'),
        ));

        $this->assertNotEmpty($keys);

        foreach ($keys as $key) {
            $this->assertArrayHasKey($key, $uk, "Missing Ukrainian translation for {$key}");
            $this->assertArrayHasKey($key, $ru, "Missing Russian translation for {$key}");
        }
    }

    #[Test]
    public function rendered_ui_does_not_expose_forbidden_technical_vocabulary(): void
    {
        $actor = User::factory()->create();
        $this->grantManageWorkspaceAccess($this->defaultWorkspace(), $actor);
        $this->createRoleWithPermissions(
            $this->defaultWorkspace()->id,
            'Merchant Visible Role',
            WorkspacePermissions::catalogue(),
        );

        $html = Livewire::actingAs($actor)
            ->test(WorkspaceAccessMembersTable::class)
            ->assertSee(__('workspace_access.members.existing_users_only'))
            ->html();

        $rolesHtml = Livewire::actingAs($actor)
            ->test(WorkspaceAccessRolesTable::class)
            ->assertSee('Merchant Visible Role')
            ->html();

        foreach ([$html, $rolesHtml] as $rendered) {
            foreach ([
                'RBAC',
                'Spatie',
                'workspace_user_roles',
                'workspace_role_permissions',
                'template_key',
                ...WorkspacePermissions::catalogue(),
            ] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $rendered, "Forbidden vocabulary found: {$forbidden}");
            }
        }
    }
}
