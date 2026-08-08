<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Support\CanonicalRegistry\CanonicalGovernanceReader;
use App\Support\Platform\PlatformAdminAuthorization;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Auth;

class Governance extends Page
{
    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = null;

    protected static ?string $title = null;

    protected static string | \UnitEnum | null $navigationGroup = null;

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.pages.governance';

    /** @var list<array{id: string, type: string, title: string}> */
    public array $decisions = [];

    public string $documentType = 'DEC';

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

    public static function getNavigationLabel(): string
    {
        return __('governance.navigation_label');
    }

    public function getTitle(): string|Htmlable
    {
        return __('governance.title');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('governance.navigation_group');
    }

    public function mount(): void
    {
        $reader = app(CanonicalGovernanceReader::class);
        $this->decisions = $reader->listDecisions();
    }

    public function setDocumentType(string $type): void
    {
        if (! in_array($type, ['DEC', 'GAP'], true)) {
            return;
        }

        $this->documentType = $type;
        $this->expandedCardId = null;
        $this->expandedDecision = null;
        $this->expandedSources = [];
    }

    public function documentTypeIndicatorLabel(): string
    {
        return __('governance.current_document_type', [
            'type' => $this->documentType,
        ]);
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
            ->filter(fn (array $decision): bool => $decision['type'] === $this->documentType)
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
