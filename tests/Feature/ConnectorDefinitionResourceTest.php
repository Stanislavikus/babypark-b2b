<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\ConnectorDefinitionResource;
use App\Filament\Resources\ConnectorDefinitionResource\Pages\ListConnectorDefinitions;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

class ConnectorDefinitionResourceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

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
    }

    #[Test]
    public function connector_definition_preserves_authored_label_capitalization(): void
    {
        $authoredLabel = 'Платформи та джерела';

        $this->assertSame($authoredLabel, ConnectorDefinitionResource::getPluralModelLabel());
        $this->assertSame($authoredLabel, ConnectorDefinitionResource::getTitleCasePluralModelLabel());

        $reflection = new ReflectionClass(ConnectorDefinitionResource::class);
        $property = $reflection->getProperty('hasTitleCaseModelLabel');
        $property->setAccessible(true);

        $this->assertFalse($property->getValue());

        Livewire::actingAs($this->admin)
            ->test(ListConnectorDefinitions::class)
            ->assertSee($authoredLabel)
            ->assertDontSee('Платформи Та Джерела');
    }
}
