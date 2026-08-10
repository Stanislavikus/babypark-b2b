<?php

namespace App\Filament\Pages\Integrations;

use App\Models\ConnectorAccount;
use App\Models\User;
use App\Support\Connectors\Integrations\PlatformIntegrationCard;
use App\Support\Connectors\Integrations\PlatformIntegrationCardBuilder;
use App\Support\Workspace\WorkspaceContext;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class Integrations extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-puzzle-piece';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'integrations';

    protected string $view = 'filament.pages.integrations.index';

    /** @var list<array<string, mixed>> */
    public array $cards = [];

    public static function getNavigationLabel(): string
    {
        return __('connectors.ui.integrations.navigation_label');
    }

    public function getTitle(): string|Htmlable
    {
        return __('connectors.ui.integrations.title');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('connectors.ui.integrations.navigation_group');
    }

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User
            && Gate::forUser($user)->allows('viewAny', ConnectorAccount::class);
    }

    public function mount(PlatformIntegrationCardBuilder $cardBuilder, WorkspaceContext $workspaceContext): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        $this->cards = $cardBuilder
            ->cardsFor($user, $workspaceContext->current())
            ->map(fn (PlatformIntegrationCard $card): array => $this->serializeCard($card))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeCard(PlatformIntegrationCard $card): array
    {
        return [
            'platform_name' => $card->platform->name,
            'platform_code' => $card->platform->code,
            'status_label' => $card->statusLabel,
            'status_color' => $card->statusColor,
            'secondary_line' => $card->secondaryLine,
            'runtime_overlay_label' => $card->runtimeOverlayLabel,
            'primary_action' => $card->primaryAction,
            'primary_action_url' => $card->primaryActionUrl,
            'primary_action_label' => $card->primaryActionLabel,
            'secondary_action_hint' => $card->secondaryActionHint,
            'account_count' => $card->accountCount(),
            'connection_status' => $card->connectionStatus()?->value,
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function getCardsProperty(): Collection
    {
        return Collection::make($this->cards);
    }
}
