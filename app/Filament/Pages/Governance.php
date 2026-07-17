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

    public string $activeTab = 'DEC';

    public string $search = '';

    public ?string $expandedCardId = null;

    /** @var array{id: string, type: string, title: string, body: string}|null */
    public ?array $expandedDecision = null;

    /** @var list<array<string, string>> */
    public array $expandedSources = [];

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User && PlatformAdminAuthorization::canManage($user);
    }

    public function mount(): void
    {
        $reader = app(CanonicalGovernanceReader::class);
        $this->decisions = $reader->listDecisions();
    }

    public function setActiveTab(string $tab): void
    {
        if (! in_array($tab, ['DEC', 'GAP'], true)) {
            return;
        }

        $this->activeTab = $tab;
        $this->expandedCardId = null;
        $this->expandedDecision = null;
        $this->expandedSources = [];
    }

    public function toggleCard(string $id): void
    {
        if ($this->expandedCardId === $id) {
            $this->expandedCardId = null;
            $this->expandedDecision = null;
            $this->expandedSources = [];

            return;
        }

        $this->expandedCardId = $id;
        $reader = app(CanonicalGovernanceReader::class);
        $this->expandedDecision = $reader->getDecision($id);
        $this->expandedSources = $reader->sourcesForSubject('decision:'.$id);
    }

    /**
     * @return list<array{id: string, type: string, title: string}>
     */
    public function filteredDecisions(): array
    {
        $search = mb_strtolower(trim($this->search));

        return collect($this->decisions)
            ->filter(fn (array $decision): bool => $decision['type'] === $this->activeTab)
            ->filter(function (array $decision) use ($search): bool {
                if ($search === '') {
                    return true;
                }

                $number = str_replace(['DEC-', 'GAP-'], '', $decision['id']);

                return str_contains(mb_strtolower($decision['id']), $search)
                    || str_contains(mb_strtolower($number), $search)
                    || str_contains(mb_strtolower($decision['title']), $search);
            })
            ->values()
            ->all();
    }

    public function decCount(): int
    {
        return collect($this->decisions)
            ->where('type', 'DEC')
            ->count();
    }

    public function gapCount(): int
    {
        return collect($this->decisions)
            ->where('type', 'GAP')
            ->count();
    }
}
