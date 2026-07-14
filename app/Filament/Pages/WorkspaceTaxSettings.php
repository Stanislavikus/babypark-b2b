<?php

namespace App\Filament\Pages;

use App\Enums\PriceDisplayMode;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Workspace\WorkspaceContext;
use App\Support\Workspace\WorkspaceTaxSettingsAuthorization;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

class WorkspaceTaxSettings extends Page implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationGroup = 'Налаштування';

    protected static ?string $navigationLabel = 'Ціни та податки';

    protected static ?string $title = 'Ціни та податки';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.workspace-tax-settings';

    public ?array $data = [];

    public ?string $originalVatRate = null;

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User
            && WorkspaceTaxSettingsAuthorization::canManage($user);
    }

    public function mount(): void
    {
        $workspace = app(WorkspaceContext::class)->current();

        $this->form->fill([
            'default_vat_rate' => $workspace->default_vat_rate,
            'default_price_display_mode' => $workspace->default_price_display_mode?->value
                ?? PriceDisplayMode::TaxInclusivePrimary->value,
        ]);

        $this->originalVatRate = (string) $workspace->default_vat_rate;
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Налаштування → Ціни та податки')->schema([
                    TextInput::make('default_vat_rate')
                        ->label('Ставка податку за замовчуванням')
                        ->required()
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(100)
                        ->step(0.01)
                        ->suffix('%')
                        ->helperText('Застосовується, якщо для товару або позиції прайс-листа не вказано окрему ставку.'),
                    Radio::make('default_price_display_mode')
                        ->label('Відображення цін за замовчуванням')
                        ->required()
                        ->options(PriceDisplayMode::options()),
                ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $workspace = app(WorkspaceContext::class)->current();

        if ($this->shouldWarnAboutVatRateChange($data, $workspace)) {
            $this->mountAction('confirmSaveWithVatChange');

            return;
        }

        $this->persist($data, $workspace);
    }

    public function confirmSaveWithVatChangeAction(): Action
    {
        return Action::make('confirmSaveWithVatChange')
            ->requiresConfirmation()
            ->modalHeading('Підтвердження зміни ставки')
            ->modalDescription(
                'Зміна ставки вплине на всі ціни, що використовують ставку податку за замовчуванням, зокрема позиції прайс-листів без власної ставки та fallback-ціни товарів.'
            )
            ->action(function (): void {
                $workspace = app(WorkspaceContext::class)->current();
                $this->persist($this->form->getState(), $workspace);
            });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function shouldWarnAboutVatRateChange(array $data, Workspace $workspace): bool
    {
        if (! $workspace->exists) {
            return false;
        }

        $newRate = number_format((float) $data['default_vat_rate'], 2, '.', '');
        $originalRate = number_format((float) $this->originalVatRate, 2, '.', '');

        return $newRate !== $originalRate;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function persist(array $data, Workspace $workspace): void
    {
        $workspace->update([
            'default_vat_rate' => $data['default_vat_rate'],
            'default_price_display_mode' => $data['default_price_display_mode'],
        ]);

        $this->originalVatRate = number_format((float) $data['default_vat_rate'], 2, '.', '');

        Notification::make()
            ->title('Налаштування збережено')
            ->success()
            ->send();
    }

    protected function getFormActions(): array
    {
        return [];
    }
}
