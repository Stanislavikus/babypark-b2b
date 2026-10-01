<?php

namespace App\Filament\Resources\ProductResource\Pages;

use App\Filament\Resources\ProductResource;
use App\Filament\Resources\ProductResource\Support\ProductWorkspaceFieldEditor;
use App\Filament\Resources\ProductResource\Support\ProductWorkspaceFieldEditStaleException;
use App\Models\ProductType;
use App\Models\ProductTypeGroupPlacement;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Catalog\ProductVariantStructureService;
use App\Services\Fields\Exceptions\DynamicFieldCurrentValueMismatchException;
use App\Services\Fields\Exceptions\FieldValueWriterException;
use App\Services\ProductStructure\ProductOptionalGroupMutationService;
use App\Services\ProductStructure\ProductTypeChangeImpactService;
use App\Services\ProductStructure\ProductTypeMutationService;
use App\Services\Workspace\WorkspaceAuthorization;
use App\Support\Catalog\Exceptions\ProductVariantStructureException;
use App\Support\ProductStructure\Exceptions\ProductTypeChangeStaleException;
use App\Support\ProductStructure\ProductTypeChangeImpact;
use App\Support\Workspace\WorkspaceContext;
use App\Support\Workspace\WorkspacePermissions;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\Width;
use Illuminate\Support\Collection;
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
            $this->promoteVariantsAction(),
            $this->addVariantAxisAction(),
            $this->addVariantAction(),
            $this->editProductFieldsAction(),
            $this->changeProductTypeAction(),
            $this->optionalGroupsAction(),
            ViewAction::make()->label('Перегляд'),
        ];
    }

    private function promoteVariantsAction(): Action
    {
        return Action::make('promote_variants')
            ->label('Додати варіанти')
            ->icon('heroicon-o-squares-plus')
            ->visible(fn (): bool => $this->canManageProducts()
                && $this->variantShapeIsManuallyEditable()
                && $this->record->variants()->withoutGlobalScopes()->count() === 1
                && app(ProductVariantStructureService::class)->declaredAxes($this->record)->isEmpty()
                && app(ProductVariantStructureService::class)->axisCandidates($this->record)->isNotEmpty())
            ->modalHeading('Додати варіанти')
            ->modalDescription('Оберіть першу опцію. Поточний варіант збережеться — його внутрішній ID не зміниться.')
            ->schema([
                Select::make('axis_binding_id')
                    ->label('Опція')
                    ->options(fn (): array => app(ProductVariantStructureService::class)
                        ->axisCandidates($this->record)
                        ->mapWithKeys(fn (array $candidate, string $id): array => [$id => $candidate['label']])
                        ->all())
                    ->required()
                    ->live(),
                Select::make('existing_value')
                    ->label('Значення поточного варіанта')
                    ->options(fn (Get $get): array => $this->axisOptions((string) $get('axis_binding_id')))
                    ->required()
                    ->searchable(),
                Select::make('additional_values')
                    ->label('Ще значення')
                    ->options(fn (Get $get): array => $this->axisOptions((string) $get('axis_binding_id')))
                    ->multiple()
                    ->required()
                    ->searchable()
                    ->helperText('Для кожного додаткового значення буде створено окремий варіант без автоматичного копіювання SKU, GTIN, ціни, залишку чи медіа.'),
            ])
            ->action(function (array $data): void {
                $this->runVariantMutation(function (User $actor, Workspace $workspace) use ($data): void {
                    app(ProductVariantStructureService::class)->promoteSimple(
                        $actor,
                        $workspace,
                        $this->record,
                        (string) $data['axis_binding_id'],
                        (string) $data['existing_value'],
                        array_values($data['additional_values'] ?? []),
                    );
                }, 'Варіанти додано');
            });
    }

    private function addVariantAxisAction(): Action
    {
        return Action::make('add_variant_axis')
            ->label('Додати опцію')
            ->icon('heroicon-o-plus-circle')
            ->visible(fn (): bool => $this->canManageProducts()
                && $this->variantShapeIsManuallyEditable()
                && app(ProductVariantStructureService::class)->declaredAxes($this->record)->isNotEmpty()
                && $this->availableAdditionalAxes() !== [])
            ->modalHeading('Додати опцію варіантів')
            ->modalDescription('Вкажіть значення нової опції для кожного існуючого варіанта. Нові комбінації автоматично не створюються.')
            ->schema(function (): array {
                $components = [
                    Select::make('axis_binding_id')
                        ->label('Нова опція')
                        ->options($this->availableAdditionalAxes())
                        ->required()
                        ->live(),
                ];

                foreach ($this->activeVariants() as $variant) {
                    $components[] = Select::make('assignments.'.(string) $variant->id)
                        ->label(filled($variant->sku) ? 'SKU '.$variant->sku : 'Варіант #'.$variant->id)
                        ->options(fn (Get $get): array => $this->axisOptions((string) $get('axis_binding_id')))
                        ->required()
                        ->searchable();
                }

                return $components;
            })
            ->action(function (array $data): void {
                $this->runVariantMutation(function (User $actor, Workspace $workspace) use ($data): void {
                    app(ProductVariantStructureService::class)->addAxis(
                        $actor,
                        $workspace,
                        $this->record,
                        (string) $data['axis_binding_id'],
                        $data['assignments'] ?? [],
                    );
                }, 'Опцію додано');
            });
    }

    private function addVariantAction(): Action
    {
        return Action::make('add_variant')
            ->label('Додати варіант')
            ->icon('heroicon-o-plus')
            ->visible(fn (): bool => $this->canManageProducts()
                && $this->variantShapeIsManuallyEditable()
                && app(ProductVariantStructureService::class)->declaredAxes($this->record)->isNotEmpty())
            ->modalHeading('Новий варіант')
            ->modalDescription('Вкажіть точну комбінацію опцій. Платформа не створює декартовий набір варіантів автоматично.')
            ->schema(function (): array {
                $components = [];
                $service = app(ProductVariantStructureService::class);
                $candidates = $service->axisCandidates($this->record);

                foreach ($service->declaredAxes($this->record) as $axis) {
                    $bindingId = (string) $axis->field_binding_id;
                    $candidate = $candidates->get($bindingId);
                    $components[] = Select::make('axis_values.'.$bindingId)
                        ->label(is_array($candidate) ? (string) $candidate['label'] : $bindingId)
                        ->options(is_array($candidate) ? $candidate['options'] : [])
                        ->required()
                        ->searchable();
                }

                $components[] = TextInput::make('sku')->label('SKU')->maxLength(255);
                $components[] = TextInput::make('gtin')->label('GTIN / EAN')->maxLength(255);

                return $components;
            })
            ->action(function (array $data): void {
                $this->runVariantMutation(function (User $actor, Workspace $workspace) use ($data): void {
                    app(ProductVariantStructureService::class)->addVariant(
                        $actor,
                        $workspace,
                        $this->record,
                        $data['axis_values'] ?? [],
                        $data['sku'] ?? null,
                        $data['gtin'] ?? null,
                    );
                }, 'Варіант додано');
            });
    }

    /** @return array<string,string> */
    private function axisOptions(string $bindingId): array
    {
        $candidate = app(ProductVariantStructureService::class)->axisCandidates($this->record)->get($bindingId);

        return is_array($candidate) ? $candidate['options'] : [];
    }

    /** @return array<string,string> */
    private function availableAdditionalAxes(): array
    {
        $service = app(ProductVariantStructureService::class);
        $declared = $service->declaredAxes($this->record)
            ->pluck('field_binding_id')
            ->map(fn ($id): string => (string) $id)
            ->all();

        return $service->axisCandidates($this->record)
            ->reject(fn (array $candidate, string $id): bool => in_array($id, $declared, true))
            ->mapWithKeys(fn (array $candidate, string $id): array => [$id => $candidate['label']])
            ->all();
    }

    /** @return Collection<int,ProductVariant> */
    private function activeVariants(): Collection
    {
        return ProductVariant::withoutWorkspaceScope()
            ->where('workspace_id', $this->record->workspace_id)
            ->where('product_id', $this->record->id)
            ->where('is_active', true)
            ->orderBy('id')
            ->get();
    }

    private function variantShapeIsManuallyEditable(): bool
    {
        return ! filled($this->record->onec_guid)
            && ! ProductVariant::withoutWorkspaceScope()
                ->where('workspace_id', $this->record->workspace_id)
                ->where('product_id', $this->record->id)
                ->whereNotNull('onec_guid')
                ->exists();
    }

    private function runVariantMutation(\Closure $mutation, string $successTitle): void
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User && $this->canManageProducts(), 403);
        $workspace = Workspace::withoutGlobalScopes()->findOrFail($this->record->workspace_id);

        try {
            $mutation($actor, $workspace);
            $this->record->refresh();

            Notification::make()
                ->success()
                ->title($successTitle)
                ->send();
        } catch (ProductVariantStructureException $exception) {
            Notification::make()
                ->danger()
                ->title('Не вдалося змінити варіанти')
                ->body($exception->getMessage())
                ->send();
        } catch (FieldValueWriterException) {
            Notification::make()
                ->danger()
                ->title('Не вдалося зберегти опції варіантів')
                ->body('Перевірте значення та спробуйте ще раз.')
                ->send();
        }
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
                } catch (ProductVariantStructureException $exception) {
                    Notification::make()
                        ->danger()
                        ->title('Спочатку змініть варіанти')
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

                try {
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
                } catch (ProductVariantStructureException $exception) {
                    Notification::make()
                        ->danger()
                        ->title('Група використовується варіантами')
                        ->body($exception->getMessage())
                        ->send();
                }
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
