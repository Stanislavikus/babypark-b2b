<?php

namespace Tests\Feature;

use App\Enums\ConnectorDefinitionStatus;
use App\Enums\ConnectorDirection;
use App\Enums\ConnectorSchemaAcquisitionMode;
use App\Enums\ConnectorSchemaScope;
use App\Enums\ConnectorSchemaSourceKind;
use App\Enums\ConnectorSchemaVerificationStatus;
use App\Enums\UserRole;
use App\Filament\Resources\ConnectorDefinitionResource\Pages\CreateConnectorDefinition;
use App\Filament\Resources\ConnectorDefinitionResource\Pages\EditConnectorDefinition;
use App\Filament\Resources\ConnectorDefinitionResource\RelationManagers\SchemaSourcesRelationManager;
use App\Models\ConnectorDefinition;
use App\Models\ConnectorSchemaSource;
use App\Models\User;
use App\Services\Connectors\ConnectorDefinitionGovernanceService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ConnectorFoundationFilamentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private ConnectorDefinitionGovernanceService $governance;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::query()->create([
            'name' => 'Connector Admin',
            'email' => 'connector-admin-'.uniqid().'@babypark.ua',
            'password' => 'password',
            'role' => UserRole::Admin,
            'is_active' => true,
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->governance = app(ConnectorDefinitionGovernanceService::class);
    }

    #[Test]
    public function filament_definition_edit_cannot_bypass_active_invariant(): void
    {
        $definition = $this->governance->createDefinition([
            'code' => 'invalid_active_ui',
            'name' => 'Invalid active UI',
            'direction' => ConnectorDirection::Both,
        ]);

        $this->governance->createSource($definition, [
            'code' => 'qualifying',
            'label' => 'Qualifying',
            'source_kind' => ConnectorSchemaSourceKind::OfficialWebDoc,
            'acquisition_mode' => ConnectorSchemaAcquisitionMode::RemoteStatic,
            'schema_scope' => ConnectorSchemaScope::Global,
            'reference_url' => 'https://example.com/qualifying',
            'is_primary' => true,
            'verification_status' => ConnectorSchemaVerificationStatus::Verified,
            'last_verified_at' => now(),
        ]);

        DB::table('connector_definitions')
            ->where('id', $definition->id)
            ->update(['status' => ConnectorDefinitionStatus::Active->value]);

        ConnectorSchemaSource::query()
            ->where('connector_definition_id', $definition->id)
            ->update(['is_primary' => false]);

        $definition->refresh();
        $originalNotes = $definition->notes;

        Livewire::actingAs($this->admin)
            ->test(EditConnectorDefinition::class, ['record' => $definition->getKey()])
            ->fillForm([
                'notes' => 'changed through filament',
            ])
            ->call('save')
            ->assertHasErrors();

        $definition->refresh();
        $this->assertSame($originalNotes, $definition->notes);
        $this->assertSame(ConnectorDefinitionStatus::Active, $definition->status);
    }

    #[Test]
    public function filament_source_edit_uses_governance_service_and_auto_drafts_parent(): void
    {
        $definition = $this->activateWithQualifyingSource('filament_edit_source');
        $source = ConnectorSchemaSource::query()
            ->where('connector_definition_id', $definition->id)
            ->firstOrFail();

        ConnectorDefinition::saving(function (): void {
            throw new \RuntimeException('abort parent draft');
        });

        try {
            Livewire::actingAs($this->admin)
                ->test(SchemaSourcesRelationManager::class, [
                    'ownerRecord' => $definition,
                    'pageClass' => EditConnectorDefinition::class,
                ])
                ->callTableAction('edit', $source, data: [
                    'code' => $source->code,
                    'label' => $source->label,
                    'source_kind' => $source->source_kind->value,
                    'acquisition_mode' => $source->acquisition_mode->value,
                    'schema_scope' => $source->schema_scope->value,
                    'reference_url' => $source->reference_url,
                    'is_primary' => true,
                    'verification_status' => ConnectorSchemaVerificationStatus::Stale->value,
                    'sort_order' => $source->sort_order,
                ]);
        } catch (\RuntimeException) {
            // expected abort inside governance transaction
        } finally {
            ConnectorDefinition::flushEventListeners();
            ConnectorDefinition::booted();
        }

        $source->refresh();
        $definition->refresh();

        $this->assertSame(ConnectorSchemaVerificationStatus::Verified, $source->verification_status);
        $this->assertSame(ConnectorDefinitionStatus::Active, $definition->status);
    }

    #[Test]
    public function filament_source_create_with_unverified_global_primary_uses_service_and_auto_drafts_parent(): void
    {
        $definition = $this->activateWithQualifyingSource('filament_create_source');

        ConnectorDefinition::saving(function (ConnectorDefinition $model): void {
            if ($model->isDirty('status')) {
                throw new \RuntimeException('abort create source transaction');
            }
        });

        try {
            Livewire::actingAs($this->admin)
                ->test(SchemaSourcesRelationManager::class, [
                    'ownerRecord' => $definition,
                    'pageClass' => EditConnectorDefinition::class,
                ])
                ->callTableAction('create', data: [
                    'code' => 'filament_new_primary',
                    'label' => 'Filament new primary',
                    'source_kind' => ConnectorSchemaSourceKind::OfficialWebDoc->value,
                    'acquisition_mode' => ConnectorSchemaAcquisitionMode::RemoteStatic->value,
                    'schema_scope' => ConnectorSchemaScope::Global->value,
                    'reference_url' => 'https://example.com/filament-new',
                    'is_primary' => true,
                    'verification_status' => ConnectorSchemaVerificationStatus::Stale->value,
                    'sort_order' => 5,
                ]);
        } catch (\RuntimeException) {
            // expected
        } finally {
            ConnectorDefinition::flushEventListeners();
            ConnectorDefinition::booted();
        }

        $definition->refresh();

        $this->assertSame(
            1,
            ConnectorSchemaSource::query()->where('connector_definition_id', $definition->id)->count(),
        );
        $this->assertTrue(
            ConnectorSchemaSource::query()
                ->where('connector_definition_id', $definition->id)
                ->where('code', 'qualifying_filament_create_source')
                ->where('is_primary', true)
                ->exists(),
        );
        $this->assertSame(ConnectorDefinitionStatus::Active, $definition->status);
    }

    #[Test]
    public function filament_source_delete_uses_governance_service_and_auto_drafts_parent(): void
    {
        $definition = $this->activateWithQualifyingSource('filament_delete_source');
        $source = ConnectorSchemaSource::query()
            ->where('connector_definition_id', $definition->id)
            ->firstOrFail();

        ConnectorDefinition::saving(function (): void {
            throw new \RuntimeException('abort delete draft');
        });

        try {
            Livewire::actingAs($this->admin)
                ->test(SchemaSourcesRelationManager::class, [
                    'ownerRecord' => $definition,
                    'pageClass' => EditConnectorDefinition::class,
                ])
                ->callTableAction('delete', $source);
        } catch (\RuntimeException) {
            // expected
        } finally {
            ConnectorDefinition::flushEventListeners();
            ConnectorDefinition::booted();
        }

        $this->assertTrue(ConnectorSchemaSource::query()->whereKey($source->id)->exists());
        $definition->refresh();
        $this->assertSame(ConnectorDefinitionStatus::Active, $definition->status);
    }

    #[Test]
    public function filament_definition_create_form_does_not_expose_status(): void
    {
        Livewire::actingAs($this->admin)
            ->test(CreateConnectorDefinition::class)
            ->assertFormFieldExists('code')
            ->assertFormFieldDoesNotExist('status');
    }

    #[Test]
    public function filament_definition_create_without_status_creates_draft(): void
    {
        Livewire::actingAs($this->admin)
            ->test(CreateConnectorDefinition::class)
            ->fillForm([
                'code' => 'filament_created',
                'name' => 'Filament created',
                'direction' => ConnectorDirection::Both->value,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $definition = ConnectorDefinition::query()->where('code', 'filament_created')->firstOrFail();
        $this->assertSame(ConnectorDefinitionStatus::Draft, $definition->status);
    }

    private function activateWithQualifyingSource(string $code): ConnectorDefinition
    {
        $definition = $this->governance->createDefinition([
            'code' => $code,
            'name' => 'Test '.$code,
            'direction' => ConnectorDirection::Both,
        ]);

        $this->governance->createSource($definition, [
            'code' => 'qualifying_'.$code,
            'label' => 'Qualifying '.$code,
            'source_kind' => ConnectorSchemaSourceKind::OfficialWebDoc,
            'acquisition_mode' => ConnectorSchemaAcquisitionMode::RemoteStatic,
            'schema_scope' => ConnectorSchemaScope::Global,
            'reference_url' => 'https://example.com/'.$code,
            'is_primary' => true,
            'verification_status' => ConnectorSchemaVerificationStatus::Verified,
            'last_verified_at' => now(),
        ]);

        return $this->governance->updateDefinition($definition, [
            'status' => ConnectorDefinitionStatus::Active,
        ]);
    }
}
