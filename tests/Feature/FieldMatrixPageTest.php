<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Pages\FieldMatrix;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FieldMatrixPageTest extends TestCase
{
    use RefreshDatabase;

    private User $platformAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformAdmin = User::query()->create([
            'name' => 'Platform Admin',
            'email' => 'platform-admin-'.uniqid().'@babypark.ua',
            'password' => 'password',
            'role' => UserRole::Admin,
            'is_active' => true,
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    #[Test]
    public function field_matrix_page_renders_without_error_for_platform_admin(): void
    {
        Livewire::actingAs($this->platformAdmin)
            ->test(FieldMatrix::class)
            ->assertSuccessful()
            ->assertSet('availableColumns', fn (array $columns): bool => $columns !== [])
            ->assertSet('matrix', fn (array $matrix): bool => $matrix !== []);
    }
}
