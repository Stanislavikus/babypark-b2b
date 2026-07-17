<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Pages\Governance;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GovernancePageTest extends TestCase
{
    use RefreshDatabase;

    private User $platformAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformAdmin = User::query()->create([
            'name' => 'Platform Admin',
            'email' => 'governance-admin-'.uniqid().'@babypark.ua',
            'password' => 'password',
            'role' => UserRole::Admin,
            'is_active' => true,
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    #[Test]
    public function governance_page_renders_without_error_for_platform_admin(): void
    {
        Livewire::actingAs($this->platformAdmin)
            ->test(Governance::class)
            ->assertSuccessful()
            ->assertSet('decisions', fn (array $decisions): bool => $decisions !== [])
            ->assertSet('selectedDecision', fn (?array $decision): bool => $decision !== null);
    }
}
