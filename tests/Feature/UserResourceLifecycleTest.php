<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\UserResource\Pages\CreateUser;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Models\User;
use App\Models\WorkspaceUser;
use App\Support\Workspace\Rbac\Exceptions\UserLifecycleIntegrityException;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Database\Seeders\WorkspaceSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithWorkspaceRbac;
use Tests\TestCase;

class UserResourceLifecycleTest extends TestCase
{
    use InteractsWithWorkspaceRbac;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceSeeder::class);
        $this->seed(WorkspaceRbacPermissionSeeder::class);

        $this->admin = User::factory()->create([
            'role' => UserRole::Admin,
            'is_active' => true,
            'customer_id' => null,
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->admin);
    }

    #[Test]
    public function new_staff_user_creation_creates_no_workspace_user(): void
    {
        $membershipCount = WorkspaceUser::query()->count();

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'New Staff',
                'email' => 'new-staff@babypark.ua',
                'password' => 'password',
                'role' => UserRole::Manager->value,
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame($membershipCount, WorkspaceUser::query()->count());
    }

    #[Test]
    public function edit_user_submission_with_prohibited_deactivation_and_other_field_change_persists_neither(): void
    {
        $workspace = $this->defaultWorkspace();
        $target = User::factory()->create([
            'name' => 'Original Name',
            'email' => 'target-holder@babypark.ua',
            'role' => UserRole::Manager,
            'is_active' => true,
            'customer_id' => null,
        ]);
        $this->makeEffectiveHolder($workspace, $target, 'Only Holder');

        try {
            Livewire::test(EditUser::class, ['record' => $target->getRouteKey()])
                ->fillForm([
                    'name' => 'Changed Name',
                    'is_active' => false,
                ])
                ->call('save');
        } catch (UserLifecycleIntegrityException) {
            // Filament may surface the exception directly during save.
        }

        $target->refresh();

        $this->assertSame('Original Name', $target->name);
        $this->assertTrue($target->is_active);
    }
}
