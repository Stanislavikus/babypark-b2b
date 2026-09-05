<?php

namespace Tests\Feature;

use App\Enums\AttributeDataType;
use App\Enums\AttributeScope;
use App\Enums\AttributeStatus;
use App\Enums\UserRole;
use App\Filament\Cabinet\Resources\ProductResource as CabinetProductResource;
use App\Filament\Resources\CategoryResource;
use App\Filament\Resources\ConnectorAccountResource;
use App\Filament\Resources\ConnectorDefinitionResource;
use App\Filament\Resources\CustomerResource;
use App\Filament\Resources\FieldDefinitionResource;
use App\Filament\Resources\OrderResource;
use App\Filament\Resources\ProductResource;
use App\Filament\Resources\ReservationResource;
use App\Filament\Resources\StockResource;
use App\Filament\Resources\SyncLogResource;
use App\Models\FieldDefinition;
use App\Models\User;
use App\Models\Workspace;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * GAP-024 PR3 gate: Filament 4 action paths consult get*AuthorizationResponse().
 * Deny-only Resource contracts must not broaden to default-allow.
 */
class FilamentResourceAuthorizationBridgeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::query()->create([
            'name' => 'Auth Bridge Admin',
            'email' => 'auth-bridge-admin@babypark.ua',
            'password' => 'password',
            'role' => UserRole::Admin,
            'is_active' => true,
        ]);

        $this->manager = User::query()->create([
            'name' => 'Auth Bridge Manager',
            'email' => 'auth-bridge-manager@babypark.ua',
            'password' => 'password',
            'role' => UserRole::Manager,
            'is_active' => true,
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->admin);
    }

    /**
     * @return array<string, array{0: class-string, 1: list<string>}>
     */
    public static function denyOnlyResourcesProvider(): array
    {
        return [
            'sync log' => [SyncLogResource::class, ['create', 'edit', 'delete']],
            'stock' => [StockResource::class, ['create', 'edit', 'delete']],
            'reservation' => [ReservationResource::class, ['create', 'edit', 'delete']],
            'admin product' => [ProductResource::class, ['create', 'delete']],
            'category' => [CategoryResource::class, ['create', 'delete']],
            'order' => [OrderResource::class, ['create']],
            'customer' => [CustomerResource::class, ['create']],
            'cabinet product' => [CabinetProductResource::class, ['create', 'edit', 'delete']],
            'connector account' => [ConnectorAccountResource::class, ['create', 'edit', 'delete']],
            'field definition create' => [FieldDefinitionResource::class, ['create']],
        ];
    }

    #[Test]
    #[DataProvider('denyOnlyResourcesProvider')]
    public function deny_only_resources_return_denied_authorization_responses(string $resource, array $operations): void
    {
        $record = new class extends Model {};

        foreach ($operations as $operation) {
            $response = match ($operation) {
                'create' => $resource::getCreateAuthorizationResponse(),
                'edit' => $resource::getEditAuthorizationResponse($record),
                'delete' => $resource::getDeleteAuthorizationResponse($record),
                default => throw new \InvalidArgumentException($operation),
            };

            $this->assertInstanceOf(Response::class, $response);
            $this->assertTrue($response->denied(), "{$resource}::{$operation} must remain denied");
            $this->assertFalse($response->allowed());
        }
    }

    #[Test]
    public function field_definition_delete_allows_non_system_and_denies_system_scope(): void
    {
        $workspace = Workspace::query()->where('is_default', true)->sole();

        $custom = FieldDefinition::query()->create([
            'workspace_id' => $workspace->id,
            'code' => 'auth_bridge_custom_field',
            'data_type' => AttributeDataType::Text,
            'scope' => AttributeScope::WorkspaceCustom,
            'localized_labels' => ['uk' => 'Auth Bridge Custom'],
            'status' => AttributeStatus::Active,
            'is_localizable' => false,
            'is_multi_value' => false,
        ]);

        $system = FieldDefinition::query()
            ->where('scope', AttributeScope::System)
            ->first();

        $this->assertNotNull($system);

        $this->assertTrue(FieldDefinitionResource::getDeleteAuthorizationResponse($custom)->allowed());
        $this->assertTrue(FieldDefinitionResource::getDeleteAuthorizationResponse($system)->denied());
        $this->assertTrue(FieldDefinitionResource::canDelete($custom));
        $this->assertFalse(FieldDefinitionResource::canDelete($system));
    }

    #[Test]
    public function connector_definition_can_access_allows_admin_and_denies_manager(): void
    {
        $this->actingAs($this->admin);
        $this->assertTrue(ConnectorDefinitionResource::canAccess());

        $this->actingAs($this->manager);
        $this->assertFalse(ConnectorDefinitionResource::canAccess());
    }

    #[Test]
    public function can_helpers_delegate_to_authorization_responses_for_sync_log(): void
    {
        $record = new class extends Model {};

        $this->assertFalse(SyncLogResource::canCreate());
        $this->assertFalse(SyncLogResource::canEdit($record));
        $this->assertFalse(SyncLogResource::canDelete($record));
        $this->assertTrue(SyncLogResource::getCreateAuthorizationResponse()->denied());
    }
}
