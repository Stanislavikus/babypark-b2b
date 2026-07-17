<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Support\CanonicalRegistry\CanonicalGovernanceReader;
use App\Support\Platform\PlatformAdminAuthorization;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

class Governance extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = 'Модель даних і коннектори';

    protected static ?string $navigationLabel = 'Governance';

    protected static ?string $title = 'Governance';

    protected static ?int $navigationSort = 3;

    protected static string $view = 'filament.pages.governance';

    /** @var list<array{id: string, type: string, title: string}> */
    public array $decisions = [];

    public ?string $selectedId = null;

    /** @var array{id: string, type: string, title: string, body: string}|null */
    public ?array $selectedDecision = null;

    /** @var list<array<string, string>> */
    public array $selectedSources = [];

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User && PlatformAdminAuthorization::canManage($user);
    }

    public function mount(): void
    {
        $reader = app(CanonicalGovernanceReader::class);
        $this->decisions = $reader->listDecisions();

        if ($this->decisions !== []) {
            $this->selectDecision($this->decisions[0]['id']);
        }
    }

    public function selectDecision(string $id): void
    {
        $this->selectedId = $id;
        $reader = app(CanonicalGovernanceReader::class);
        $this->selectedDecision = $reader->getDecision($id);
        $this->selectedSources = $reader->sourcesForSubject('decision:'.$id);
    }
}
