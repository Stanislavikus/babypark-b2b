<?php

namespace App\Filament\Resources\ProductResource\Pages;

use App\Filament\Resources\ProductResource;
use App\Filament\Resources\ProductResource\Support\ProductWorkspaceFieldEditor;
use App\Filament\Resources\ProductResource\Support\ProductWorkspaceFieldEditStaleException;
use App\Models\ProductType;
use App\Models\ProductTypeGroupPlacement;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Fields\Exceptions\DynamicFieldCurrentValueMismatchException;
use App\Services\Fields\Exceptions\FieldValueWriterException;
use App\Services\ProductStructure\ProductOptionalGroupMutationService;
use App\Services\ProductStructure\ProductTypeChangeImpactService;
use App\Services\ProductStructure\ProductTypeMutationService;
use App\Services\Workspace\WorkspaceAuthorization;
use App\Support\ProductStructure\Exceptions\ProductTypeChangeStaleException;
use App\Support\ProductStructure\ProductTypeChangeImpact;
use App\Support\Workspace\WorkspaceContext;
use App\Support\Workspace\WorkspacePermissions;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\Width;
use Illuminate\Support\Facades\Crypt;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    public function getTitle(): string
    {
        return (string) ($this->record->name ?: 'Товар');
    }

    public function getSubheading(): ?string
    {
        $source = filled($this->record->onec_guid) ? '1С' : 'Master Workspace';
        $sku = filled($this->record->sku) ? ' · SKU '.$this->record->sku : '';

        return 'Master Product · '.$source.$sku;
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->editProductFieldsAction(),
            $this->changeProductTypeAction(),
            $this->optionalGroupsAction(),
            ViewAction::make()->label('Перегляд'),
        ];
    }

    private function editProductFieldsAction(): Action
    {
        return Action::make('edit_product_fields')
            ->label('Поля товару')
            ->icon('heroicon-o-pencil-square')
            ->visible(fn (): bool => $this->canManageProducts()
                && app(ProductWorkspaceFieldEditor::class)->hasEditableFields($this->record))
            ->modalHeading('Поля товару')
            ->modalDescription('Master-поля поточного типу товару. Незавершений товар можна зберігати: обов’язковість тут впливає на повноту даних, а не блокує чернетку.')
            ->modalWidth(Width::SevenExtraLarge)
            ->modalSubmitActionLabel('Зберегти поля')
            ->fillForm(fn (): array => app(ProductWorkspaceFieldEditor::class)->formState($this->record))
            ->schema(fn (): array => app(ProductWorkspaceFieldEditor::class)->schema($this->record))
            ->action(function (array $data): void {
                if (! $this->canManageProducts()) {
                    abort(403);
                }

                try {
                    $result = app(ProductWorkspaceFieldEditor::class)->apply($this->record, $data);
                    $this->record->refresh();

                    $mutations = $result['changed'] + $result['cleared'];
                    Notification::make()
                        ->success()
                        ->title($mutations > 0 ? 'Поля товару збережено' : 'Змін немає')
                        ->body($mutations > 0
                            ? 'Оновлено: '.$result['changed'].', очищено: '.$result['cleared'].'.'
                            : null)
                        ->send();
                } catch (ProductWorkspaceFieldEditStaleException|DynamicFieldCurrentValueMismatchException $exception) {
                    Notification::make()
                        ->danger()
                        ->title('Дані вже змінилися')
                        ->body($exception->getMessage())
                        ->send();
                } catch (FieldValueWriterException) {
                    Notification::make()
                        ->danger()
                        ->title('Не вдалося зберегти поле')
                        ->body('Перевірте значення поля та спробуйте ще раз.')
                        ->send();
                }
            });
    }

    private function changeProductTypeAction(): Action
    {
        return Action::make('change_product_type')
            ->label('Тип товару')
            ->icon('heroicon-o-squares-2x2')
            ->visible(fn (): bool => $this->canManageProductStructure())
            ->schema([
                Select::make('product_type_id')
                    ->label('Новий тип товару')
                    ->options(fn (): array => ProductType::withoutWorkspaceScope()
                        ->where('workspace_id', $this->record->workspace_id)
                        ->where('status', 'active')
                        ->orderBy('is_default', 'desc')
                        ->orderBy('code')
                        ->get()
                        ->mapWithKeys(fn (ProductType $type): array => [
                            $type->id => (string) (
                                $type->localized_labels['uk']
                                ?? $type->localized_labels['en']
                                ?? $type->code
                            ),
                        ])
                        ->all())
                    ->default(fn (): ?string => $this->record->product_type_id)
                    ->required()
                    ->searchable()
                    ->live()
                    ->afterStateUpdated(function (?string $state, Set $set): void {
                        $set('reviewed_impact_token', null);

                        if (! filled($state) || (string) $state === (string) $this->record->product_type_id) {
                            return;
                        }

                        $target = ProductType::withoutWorkspaceScope()
                            ->where('workspace_id', $this->record->workspace_id)
                            ->where('status', 'active')
                            ->find($state);

                        if (! $target instanceof ProductType) {
                            return;
                        }

                        try {
                            $impact = app(ProductTypeChangeImpactService::class)->preview($this->record, $target);
                            $set('reviewed_impact_token', $this->encodeReviewedImpact($impact));
                        } catch (\Throwable) {
                            $set('reviewed_impact_token', null);
                        }
                    }),
                Hidden::make('reviewed_impact_token'),
                Placeholder::make('impact_notice')
                    ->label('Що зміниться')
                    ->content(function (Get $get): string {
                        $targetId = $get('product_type_id');

                        if (! filled($targetId) || (string) $targetId === (string) $this->record->product_type_id) {
                            return 'Оберіть інший тип товару, щоб побачити вплив до підтвердження.';
                        }

                        $impact = $this->decodeReviewedImpact($get('reviewed_impact_token'));
                        if (! $impact instanceof ProductTypeChangeImpact || $impact->toProductTypeId !== (string) $targetId) {
                            return 'Не вдалося зафіксувати попередній перегляд. Оберіть тип товару ще раз.';
                        }

                        return sprintf(
                            'Нових полів: %d; прибрано зі структури: %d; нових обов’язкових: %d. '.
                            'Збережені значення не видаляються; поза новим типом залишиться %d товарних і %d варіантних значень.',
                            count($impact->addedBindingIds),
                            count($impact->removedBindingIds),
                            count($impact->newlyRequiredBindingIds),
                            count($impact->outOfTypeProductBindingIds),
                            count($impact->outOfTypeVariantCells),
                        );
                    }),
            ])
            ->action(function (array $data): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);

                $workspace = Workspace::withoutGlobalScopes()->findOrFail($this->record->workspace_id);
                $target = ProductType::withoutWorkspaceScope()
                    ->where('workspace_id', $workspace->id)
                    ->findOrFail((string) $data['product_type_id']);

                if ((string) $target->id === (string) $this->record->product_type_id) {
                    return;
                }

                $reviewedImpact = $this->decodeReviewedImpact($data['reviewed_impact_token'] ?? null);
                if (! $reviewedImpact instanceof ProductTypeChangeImpact
                    || $reviewedImpact->toProductTypeId !== (string) $target->id) {
                    Notification::make()
                        ->danger()
                        ->title('Попередній перегляд недоступний')
                        ->body('Оберіть тип товару ще раз і перегляньте актуальний вплив перед підтвердженням.')
                        ->send();

                    return;
                }

                try {
                    app(ProductTypeMutationService::class)->change(
                        $actor,
                        $workspace,
                        $this->record,
                        $target,
                        $reviewedImpact,
                    );

                    $this->record->refresh();

                    Notification::make()
                        ->success()
                        ->title('Тип товару змінено')
                        ->send();
                } catch (ProductTypeChangeStaleException $exception) {
                    Notification::make()
                        ->danger()
                        ->title('Структура вже змінилася')
                        ->body($exception->getMessage())
                        ->send();
                }
            });
    }

    private function encodeReviewedImpact(ProductTypeChangeImpact $impact): string
    {
        return Crypt::encryptString(serialize($impact));
    }

    private function decodeReviewedImpact(mixed $token): ?ProductTypeChangeImpact
    {
        if (! is_string($token) || $token === '') {
            return null;
        }

        try {
            $impact = unserialize(
                Crypt::decryptString($token),
                ['allowed_classes' => [ProductTypeChangeImpact::class]],
            );
        } catch (\Throwable) {
            return null;
        }

        return $impact instanceof ProductTypeChangeImpact ? $impact : null;
    }

    private function optionalGroupsAction(): Action
    {
        return Action::make('optional_groups')
            ->label('Групи характеристик')
            ->icon('heroicon-o-adjustments-horizontal')
            ->visible(fn (): bool => $this->canManageProductStructure() && $this->hasOptionalGroups())
            ->schema([
                Select::make('group_placement_id')
                    ->label('Опційна група')
                    ->options(fn (): array => ProductTypeGroupPlacement::withoutWorkspaceScope()
                        ->with(['attributeGroup' => fn ($query) => $query->withoutGlobalScopes()])
                        ->where('workspace_id', $this->record->workspace_id)
                        ->where('product_type_id', $this->record->product_type_id)
                        ->where('is_optional', true)
                        ->orderBy('sort_order')
                        ->get()
                        ->mapWithKeys(fn (ProductTypeGroupPlacement $placement): array => [
                            $placement->id => (string) (
                                $placement->attributeGroup?->localized_labels['uk']
                                ?? $placement->attributeGroup?->localized_labels['en']
                                ?? $placement->attributeGroup?->code
                                ?? $placement->id
                            ),
                        ])
                        ->all())
                    ->required(),
                Select::make('active')
                    ->label('Стан')
                    ->options([
                        1 => 'Активна',
                        0 => 'Неактивна',
                    ])
                    ->required(),
            ])
            ->action(function (array $data): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);

                $workspace = Workspace::withoutGlobalScopes()->findOrFail($this->record->workspace_id);
                $placement = ProductTypeGroupPlacement::withoutWorkspaceScope()
                    ->where('workspace_id', $workspace->id)
                    ->where('product_type_id', $this->record->product_type_id)
                    ->findOrFail((string) $data['group_placement_id']);

                app(ProductOptionalGroupMutationService::class)->setActive(
                    $actor,
                    $workspace,
                    $this->record,
                    $placement,
                    (bool) $data['active'],
                );

                Notification::make()
                    ->success()
                    ->title('Стан групи характеристик змінено')
                    ->send();
            });
    }

    private function canManageProducts(): bool
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            return false;
        }

        $workspace = app(WorkspaceContext::class)->current();

        return (string) $workspace->id === (string) $this->record->workspace_id
            && app(WorkspaceAuthorization::class)->allows(
                $actor,
                $workspace,
                WorkspacePermissions::MANAGE_PRODUCTS,
            );
    }

    private function canManageProductStructure(): bool
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            return false;
        }

        $workspace = app(WorkspaceContext::class)->current();

        return (string) $workspace->id === (string) $this->record->workspace_id
            && app(WorkspaceAuthorization::class)->allows(
                $actor,
                $workspace,
                WorkspacePermissions::MANAGE_PRODUCT_STRUCTURE,
            );
    }

    private function hasOptionalGroups(): bool
    {
        return ProductTypeGroupPlacement::withoutWorkspaceScope()
            ->where('workspace_id', $this->record->workspace_id)
            ->where('product_type_id', $this->record->product_type_id)
            ->where('is_optional', true)
            ->exists();
    }
}
