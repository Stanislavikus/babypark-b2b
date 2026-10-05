<?php

namespace App\Filament\Resources\ProductResource\Pages;

use App\Enums\ProductLifecycleStatus;
use App\Exceptions\Catalog\MasterProductLifecycleMutationException;
use App\Filament\Resources\ProductResource;
use App\Filament\Resources\ProductResource\Support\ProductWorkspaceFieldEditor;
use App\Filament\Resources\ProductResource\Support\ProductWorkspaceFieldEditStaleException;
use App\Models\MediaAsset;
use App\Models\ProductMedia;
use App\Models\ProductType;
use App\Models\ProductTypeGroupPlacement;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\VariantFieldValue;
use App\Models\VariantMedia;
use App\Models\Workspace;
use App\Services\Catalog\MasterProductLifecycleMutationService;
use App\Services\Catalog\ProductMediaMutationService;
use App\Services\Catalog\ProductMediaReadService;
use App\Services\Catalog\ProductVariantStructureService;
use App\Services\Catalog\ProductWorkspaceSummaryService;
use App\Services\Catalog\VariantMediaMutationService;
use App\Services\Fields\Exceptions\DynamicFieldCurrentValueMismatchException;
use App\Services\Fields\Exceptions\FieldValueWriterException;
use App\Services\ProductStructure\ProductOptionalGroupMutationService;
use App\Services\ProductStructure\ProductTypeChangeImpactService;
use App\Services\ProductStructure\ProductTypeMutationService;
use App\Services\Workspace\WorkspaceAuthorization;
use App\Support\Catalog\Exceptions\ProductMediaException;
use App\Support\Catalog\Exceptions\ProductVariantStructureException;
use App\Support\Catalog\Exceptions\VariantMediaException;
use App\Support\ProductStructure\Exceptions\ProductTypeChangeStaleException;
use App\Support\ProductStructure\ProductTypeChangeImpact;
use App\Support\Workspace\WorkspaceContext;
use App\Support\Workspace\WorkspacePermissions;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\Width;
use Filament\Support\Exceptions\Halt;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;

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
            $this->lifecycleAction(
                'activate_product',
                ProductLifecycleStatus::Active,
                'Активувати',
                'heroicon-o-check-circle',
                'success',
            ),
            $this->lifecycleAction(
                'archive_product',
                ProductLifecycleStatus::Archived,
                'Архівувати',
                'heroicon-o-archive-box',
                'gray',
            ),
            $this->promoteVariantsAction(),
            $this->addVariantAxisAction(),
            $this->addVariantAction(),
            $this->mediaActions(),
            $this->editProductFieldsAction(),
            $this->changeProductTypeAction(),
            $this->optionalGroupsAction(),
            ViewAction::make()->label('Перегляд'),
        ];
    }

    private function lifecycleAction(
        string $name,
        ProductLifecycleStatus $target,
        string $label,
        string $icon,
        string $color,
    ): Action {
        return Action::make($name)
            ->label($label)
            ->icon($icon)
            ->color($color)
            ->requiresConfirmation()
            ->visible(fn (): bool => $this->canManageProducts()
                && $this->variantShapeIsManuallyEditable()
                && $this->currentLifecycle() !== $target)
            ->action(function () use ($target): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User && $this->canManageProducts(), 403);

                $workspace = Workspace::withoutGlobalScopes()->findOrFail($this->record->workspace_id);
                $expected = $this->currentLifecycle();

                try {
                    app(MasterProductLifecycleMutationService::class)->transition(
                        $actor,
                        $workspace,
                        $this->record,
                        $expected,
                        $target,
                    );
                } catch (MasterProductLifecycleMutationException $exception) {
                    Notification::make()
                        ->warning()
                        ->title('Стан товару не змінено')
                        ->body($exception->getMessage())
                        ->send();

                    return;
                } catch (AuthorizationException) {
                    Notification::make()
                        ->warning()
                        ->title('Стан товару не змінено')
                        ->body('Ваші права на редагування товару змінилися. Оновіть сторінку.')
                        ->send();

                    return;
                }

                $this->record->refresh();

                Notification::make()
                    ->success()
                    ->title($target === ProductLifecycleStatus::Active
                        ? 'Товар активовано'
                        : 'Товар архівовано')
                    ->send();
            });
    }

    private function currentLifecycle(): ProductLifecycleStatus
    {
        return $this->record->lifecycle_status
            ?? ($this->record->is_active
                ? ProductLifecycleStatus::Active
                : ProductLifecycleStatus::Archived);
    }

    private function mediaActions(): ActionGroup
    {
        return ActionGroup::make([
            $this->addMediaAction(),
            $this->assignVariantMediaAction(),
            $this->reorderMediaAction(),
            $this->removeMediaAction(),
        ])
            ->label('Медіа')
            ->icon('heroicon-o-photo')
            ->button()
            ->visible(fn (): bool => $this->canManageProducts());
    }

    private function addMediaAction(): Action
    {
        return Action::make('add_media')
            ->label('Додати медіа')
            ->icon('heroicon-o-arrow-up-tray')
            ->modalHeading('Додати зображення')
            ->modalDescription('Original зберігається без resize або повторного кодування. Перший кадр порожньої галереї стає основним.')
            ->schema([
                FileUpload::make('files')
                    ->label('Зображення')
                    ->image()
                    ->multiple()
                    ->storeFiles(false)
                    ->appendFiles()
                    ->required()
                    ->helperText('Можна вибрати кілька файлів. Технічні версії для каналів тут не створюються.'),
            ])
            ->action(function (array $data): void {
                $files = array_values(array_filter(
                    $data['files'] ?? [],
                    fn ($file): bool => $file instanceof UploadedFile,
                ));

                $this->runMediaMutation(
                    fn (User $actor, Workspace $workspace) => app(ProductMediaMutationService::class)
                        ->addUploadedImages($actor, $workspace, $this->record, $files),
                    'Медіа додано',
                );
            });
    }

    private function assignVariantMediaAction(): Action
    {
        return Action::make('assign_variant_media')
            ->label('Призначити варіантам')
            ->icon('heroicon-o-squares-2x2')
            ->visible(fn (): bool => $this->canManageProducts() && $this->variantMediaAuthoringAvailable())
            ->slideOver()
            ->modalWidth(Width::SevenExtraLarge)
            ->modalHeading('Медіа варіантів')
            ->modalDescription('Власні фото варіанта показуються першими. Загальні фото товару залишаються окремо й не копіюються у VariantMedia.')
            ->modalSubmitActionLabel('Застосувати')
            ->fillForm(function (array $arguments): array {
                $variantId = isset($arguments['variant_id']) ? (int) $arguments['variant_id'] : null;
                $allowed = $this->activeVariants()->pluck('id')->map(fn ($id): int => (int) $id)->all();

                return [
                    'media_asset_ids' => [],
                    'variant_ids' => $variantId !== null && in_array($variantId, $allowed, true) ? [$variantId] : [],
                    'only_without_specific' => false,
                    'axis_groups' => [],
                    'operation' => 'add',
                    'make_primary' => false,
                    'confirm_replace' => false,
                ];
            })
            ->schema(fn (): array => array_merge([
                CheckboxList::make('media_asset_ids')
                    ->label('Фото')
                    ->options(fn (): array => $this->variantMediaAssetOptions())
                    ->helperText('Використовуються існуючі Original assets цього товару. Файл не дублюється.')
                    ->required()
                    ->columns(1)
                    ->bulkToggleable(),
                Toggle::make('only_without_specific')
                    ->label('Показати лише варіанти без власних фото')
                    ->live(),
                Select::make('variant_ids')
                    ->label('Конкретні варіанти')
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->options(fn (Get $get): array => $this->variantMediaVariantOptions((bool) $get('only_without_specific')))
                    ->helperText('Можна обрати конкретні SKU або скористатися групами за опціями нижче.'),
                Placeholder::make('axis_group_hint')
                    ->hiddenLabel()
                    ->content('Групи нижче охоплюють лише поточні видимі варіанти. Вибір у різних групах об’єднується. Новий варіант, створений пізніше, фото автоматично не успадкує.'),
            ], $this->variantMediaAxisSections(), [
                Radio::make('operation')
                    ->label('Дія')
                    ->options([
                        'add' => 'Додати до власних фото',
                        'replace' => 'Замінити власні фото',
                        'detach' => 'Зняти вибрані призначення',
                    ])
                    ->default('add')
                    ->required()
                    ->live(),
                Toggle::make('make_primary')
                    ->label('Перше вибране фото зробити головним')
                    ->helperText('Головне фото варіанта змінюється лише цією явною дією.')
                    ->visible(fn (Get $get): bool => in_array($get('operation'), ['add', 'replace'], true)),
                Placeholder::make('replace_warning')
                    ->label('Підтвердження заміни')
                    ->content(fn (Get $get): string => sprintf(
                        'Буде замінено власні фото для %d варіантів. Загальні фото товару не зміняться.',
                        $this->variantMediaTargetCount($get('variant_ids'), $get('axis_groups'), (bool) $get('only_without_specific')),
                    ))
                    ->visible(fn (Get $get): bool => $get('operation') === 'replace'),
                Checkbox::make('confirm_replace')
                    ->label(fn (Get $get): string => sprintf(
                        'Підтверджую заміну власних фото для %d варіантів',
                        $this->variantMediaTargetCount($get('variant_ids'), $get('axis_groups'), (bool) $get('only_without_specific')),
                    ))
                    ->accepted()
                    ->required()
                    ->visible(fn (Get $get): bool => $get('operation') === 'replace'),
            ]))
            ->action(function (array $data): void {
                $targetVariantIds = $this->resolveVariantMediaTargetIds($data);

                if ($targetVariantIds === []) {
                    throw ValidationException::withMessages([
                        'variant_ids' => 'Оберіть хоча б один варіант або групу за опцією.',
                    ]);
                }

                $assetIds = array_values(array_filter(
                    array_map('strval', $data['media_asset_ids'] ?? []),
                    fn (string $id): bool => $id !== '',
                ));

                $operation = (string) ($data['operation'] ?? 'add');
                $makePrimary = (bool) ($data['make_primary'] ?? false);

                $this->runVariantMediaMutation(
                    function (User $actor, Workspace $workspace) use (
                        $targetVariantIds,
                        $assetIds,
                        $operation,
                        $makePrimary,
                    ): void {
                        $service = app(VariantMediaMutationService::class);

                        match ($operation) {
                            'add' => $service->assign(
                                $actor,
                                $workspace,
                                $this->record,
                                $targetVariantIds,
                                $assetIds,
                                $makePrimary,
                            ),
                            'replace' => $service->replace(
                                $actor,
                                $workspace,
                                $this->record,
                                $targetVariantIds,
                                $assetIds,
                                $makePrimary,
                            ),
                            'detach' => $service->detach(
                                $actor,
                                $workspace,
                                $this->record,
                                $targetVariantIds,
                                $assetIds,
                            ),
                            default => throw ValidationException::withMessages([
                                'operation' => 'Оберіть підтримувану дію.',
                            ]),
                        };
                    },
                    match ($operation) {
                        'replace' => 'Власні фото варіантів замінено',
                        'detach' => 'Призначення фото знято',
                        default => 'Фото призначено варіантам',
                    },
                );
            });
    }

    private function reorderMediaAction(): Action
    {
        return Action::make('reorder_media')
            ->label('Впорядкувати')
            ->icon('heroicon-o-bars-3')
            ->visible(fn (): bool => $this->productMedia()->count() > 1)
            ->modalHeading('Порядок медіа')
            ->modalDescription('Перший кадр є основним Master-зображенням. Перетягніть рядки у потрібний порядок.')
            ->schema([
                Repeater::make('items')
                    ->hiddenLabel()
                    ->schema([
                        Hidden::make('id'),
                        Placeholder::make('media_label')
                            ->hiddenLabel()
                            ->content(fn (Get $get): string => $this->mediaLabel((string) $get('id'))),
                    ])
                    ->default(fn (): array => $this->productMedia()
                        ->map(fn (ProductMedia $media): array => ['id' => (string) $media->id])
                        ->all())
                    ->addable(false)
                    ->deletable(false)
                    ->reorderable()
                    ->reorderableWithButtons(),
            ])
            ->action(function (array $data): void {
                $ids = collect($data['items'] ?? [])
                    ->pluck('id')
                    ->filter(fn ($id): bool => is_string($id) && $id !== '')
                    ->values()
                    ->all();

                $this->runMediaMutation(
                    fn (User $actor, Workspace $workspace) => app(ProductMediaMutationService::class)
                        ->reorder($actor, $workspace, $this->record, $ids),
                    'Порядок медіа оновлено',
                );
            });
    }

    private function removeMediaAction(): Action
    {
        return Action::make('remove_media')
            ->label('Видалити з товару')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->visible(fn (): bool => $this->productMedia()->isNotEmpty())
            ->modalHeading('Видалити медіа з товару')
            ->modalDescription('Видаляється лише зв’язок із цим товаром. Reusable Original не видаляється автоматично.')
            ->schema([
                Select::make('media_id')
                    ->label('Медіа')
                    ->options(fn (): array => $this->productMediaOptions())
                    ->required(),
            ])
            ->requiresConfirmation()
            ->action(function (array $data): void {
                $this->runMediaMutation(
                    fn (User $actor, Workspace $workspace) => app(ProductMediaMutationService::class)
                        ->remove($actor, $workspace, $this->record, (string) $data['media_id']),
                    'Медіа видалено з товару',
                );
            });
    }

    /** @return Collection<int, ProductMedia> */
    private function productMedia(): Collection
    {
        return app(ProductMediaReadService::class)->productMedia($this->record);
    }

    /** @return array<string,string> */
    private function productMediaOptions(): array
    {
        return $this->productMedia()
            ->mapWithKeys(fn (ProductMedia $media, int $index): array => [
                (string) $media->id => $this->mediaLabel((string) $media->id, $index),
            ])
            ->all();
    }

    private function mediaLabel(string $mediaId, ?int $knownIndex = null): string
    {
        $media = $this->productMedia()->values();
        $item = $media->firstWhere('id', $mediaId);

        if (! $item instanceof ProductMedia) {
            return 'Медіа';
        }

        $index = $knownIndex ?? $media->search(fn (ProductMedia $candidate): bool => (string) $candidate->id === $mediaId);
        $position = is_int($index) ? $index + 1 : ((int) $item->sort_order) + 1;
        $asset = $item->asset;
        $name = filled($asset?->original_filename)
            ? (string) $asset->original_filename
            : (filled($asset?->source_url) ? basename(parse_url((string) $asset->source_url, PHP_URL_PATH) ?: (string) $asset->source_url) : 'Original');

        return ($position === 1 ? 'Основне · ' : 'Кадр '.$position.' · ').$name;
    }

    private function runMediaMutation(\Closure $mutation, string $successTitle): void
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
        } catch (ProductMediaException $e) {
            Notification::make()
                ->danger()
                ->title('Не вдалося змінити медіа')
                ->body($e->getMessage())
                ->send();
        }
    }

    private function variantMediaAuthoringAvailable(): bool
    {
        $activeVariantCount = ProductVariant::withoutWorkspaceScope()
            ->where('workspace_id', $this->record->workspace_id)
            ->where('product_id', $this->record->id)
            ->where('is_active', true)
            ->count();

        return $activeVariantCount > 1
            || app(ProductVariantStructureService::class)->declaredAxes($this->record)->isNotEmpty();
    }

    /** @return array<string,string> */
    private function variantMediaAssetOptions(): array
    {
        $productAssetIds = ProductMedia::withoutWorkspaceScope()
            ->where('workspace_id', $this->record->workspace_id)
            ->where('product_id', $this->record->id)
            ->whereNull('locale')
            ->pluck('media_asset_id');

        $variantIds = $this->activeVariants()->pluck('id');
        $variantAssetIds = $variantIds->isEmpty()
            ? collect()
            : VariantMedia::withoutWorkspaceScope()
                ->where('workspace_id', $this->record->workspace_id)
                ->whereIn('variant_id', $variantIds)
                ->whereNull('locale')
                ->pluck('media_asset_id');

        $assetIds = $productAssetIds
            ->merge($variantAssetIds)
            ->map(fn ($id): string => (string) $id)
            ->unique()
            ->values();

        if ($assetIds->isEmpty()) {
            return [];
        }

        $variantUsage = VariantMedia::withoutWorkspaceScope()
            ->where('workspace_id', $this->record->workspace_id)
            ->whereIn('variant_id', $variantIds)
            ->whereNull('locale')
            ->whereIn('media_asset_id', $assetIds)
            ->selectRaw('media_asset_id, COUNT(DISTINCT variant_id) AS usage_count')
            ->groupBy('media_asset_id')
            ->pluck('usage_count', 'media_asset_id');

        $commonAssetIds = $productAssetIds->map(fn ($id): string => (string) $id)->flip();

        return MediaAsset::withoutWorkspaceScope()
            ->where('workspace_id', $this->record->workspace_id)
            ->whereNull('parent_media_asset_id')
            ->whereIn('id', $assetIds)
            ->get()
            ->sortBy(fn (MediaAsset $asset): string => $this->variantMediaAssetLabel($asset))
            ->mapWithKeys(function (MediaAsset $asset) use ($variantUsage, $commonAssetIds): array {
                $parts = [$this->variantMediaAssetLabel($asset)];

                if ($commonAssetIds->has((string) $asset->id)) {
                    $parts[] = 'загальне фото';
                }

                $usageCount = (int) ($variantUsage[(string) $asset->id] ?? 0);
                if ($usageCount > 0) {
                    $parts[] = 'використовується: '.$usageCount.' вар.';
                }

                return [(string) $asset->id => implode(' · ', $parts)];
            })
            ->all();
    }

    private function variantMediaAssetLabel(MediaAsset $asset): string
    {
        if (filled($asset->original_filename)) {
            return (string) $asset->original_filename;
        }

        if (filled($asset->source_url)) {
            $path = parse_url((string) $asset->source_url, PHP_URL_PATH);
            $basename = is_string($path) ? basename($path) : '';

            return $basename !== '' ? $basename : 'Original';
        }

        return 'Original '.substr((string) $asset->id, 0, 8);
    }

    /** @return list<int> */
    private function variantMediaEligibleVariantIds(bool $onlyWithoutSpecific = false): array
    {
        $ids = $this->activeVariants()
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values();

        if (! $onlyWithoutSpecific || $ids->isEmpty()) {
            return $ids->all();
        }

        $specificVariantIds = VariantMedia::withoutWorkspaceScope()
            ->where('workspace_id', $this->record->workspace_id)
            ->whereIn('variant_id', $ids)
            ->whereNull('locale')
            ->distinct()
            ->pluck('variant_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        return $ids
            ->reject(fn (int $id): bool => in_array($id, $specificVariantIds, true))
            ->values()
            ->all();
    }

    /** @return array<int,string> */
    private function variantMediaVariantOptions(bool $onlyWithoutSpecific = false): array
    {
        $eligibleIds = $this->variantMediaEligibleVariantIds($onlyWithoutSpecific);

        return collect(app(ProductWorkspaceSummaryService::class)->variants($this->record)['rows'])
            ->filter(fn (array $row): bool => in_array((int) $row['id'], $eligibleIds, true))
            ->mapWithKeys(function (array $row): array {
                $sku = filled($row['sku']) ? (string) $row['sku'] : 'Variant #'.$row['id'];
                $options = collect($row['options'])
                    ->filter(fn ($value): bool => is_string($value) && $value !== '' && $value !== '—')
                    ->implode(' · ');

                return [(int) $row['id'] => $options !== '' ? $sku.' · '.$options : $sku];
            })
            ->all();
    }

    /** @return list<Section> */
    private function variantMediaAxisSections(): array
    {
        $service = app(ProductVariantStructureService::class);
        $axes = $service->declaredAxes($this->record);
        $candidates = $service->axisCandidates($this->record);

        if ($axes->isEmpty() || $this->activeVariants()->isEmpty()) {
            return [];
        }

        return $axes
            ->values()
            ->map(function ($axis, int $index) use ($candidates): Section {
                $bindingId = (string) $axis->field_binding_id;
                $candidate = $candidates->get($bindingId);
                $label = is_array($candidate) ? (string) $candidate['label'] : $bindingId;
                $optionLabels = is_array($candidate) ? ($candidate['options'] ?? []) : [];

                return Section::make($label)
                    ->description($index === 0
                        ? 'Основна опція розгорнута. Вибір охоплює лише поточні видимі варіанти.'
                        : 'Окрема група поточних видимих варіантів за цією опцією.')
                    ->schema([
                        CheckboxList::make('axis_groups.'.$bindingId)
                            ->hiddenLabel()
                            ->options(fn (Get $get): array => $this->variantMediaAxisGroupOptions(
                                $bindingId,
                                $optionLabels,
                                (bool) $get('only_without_specific'),
                            ))
                            ->columns(2)
                            ->bulkToggleable(),
                    ])
                    ->collapsible()
                    ->collapsed($index > 0);
            })
            ->all();
    }

    /** @param array<string,string> $optionLabels */
    private function variantMediaAxisGroupOptions(
        string $bindingId,
        array $optionLabels,
        bool $onlyWithoutSpecific = false,
    ): array {
        $eligibleIds = $this->variantMediaEligibleVariantIds($onlyWithoutSpecific);

        if ($eligibleIds === []) {
            return [];
        }

        return VariantFieldValue::withoutWorkspaceScope()
            ->where('workspace_id', $this->record->workspace_id)
            ->whereIn('variant_id', $eligibleIds)
            ->where('field_binding_id', $bindingId)
            ->whereNotNull('value_text')
            ->selectRaw('value_text, COUNT(DISTINCT variant_id) AS variant_count')
            ->groupBy('value_text')
            ->orderBy('value_text')
            ->get()
            ->mapWithKeys(function (VariantFieldValue $row) use ($optionLabels): array {
                $code = (string) $row->value_text;
                $optionLabel = (string) ($optionLabels[$code] ?? $code);

                return [$code => $optionLabel.' · '.(int) $row->variant_count.' вар.'];
            })
            ->all();
    }

    /**
     * @param  array<string,mixed>  $data
     * @return list<int>
     */
    private function resolveVariantMediaTargetIds(array $data): array
    {
        $allowedIds = $this->variantMediaEligibleVariantIds((bool) ($data['only_without_specific'] ?? false));
        $resolved = collect($data['variant_ids'] ?? [])
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => in_array($id, $allowedIds, true));

        $declaredBindingIds = app(ProductVariantStructureService::class)
            ->declaredAxes($this->record)
            ->pluck('field_binding_id')
            ->map(fn ($id): string => (string) $id)
            ->all();

        foreach ((array) ($data['axis_groups'] ?? []) as $bindingId => $codes) {
            $bindingId = (string) $bindingId;
            if (! in_array($bindingId, $declaredBindingIds, true)) {
                continue;
            }

            $selectedCodes = array_values(array_unique(array_filter(
                array_map('strval', (array) $codes),
                fn (string $code): bool => $code !== '',
            )));
            if ($selectedCodes === []) {
                continue;
            }

            $matchingVariantIds = VariantFieldValue::withoutWorkspaceScope()
                ->where('workspace_id', $this->record->workspace_id)
                ->whereIn('variant_id', $allowedIds)
                ->where('field_binding_id', $bindingId)
                ->whereIn('value_text', $selectedCodes)
                ->pluck('variant_id')
                ->map(fn ($id): int => (int) $id);

            $resolved = $resolved->merge($matchingVariantIds);
        }

        return $resolved
            ->filter(fn (int $id): bool => in_array($id, $allowedIds, true))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    private function variantMediaTargetCount(
        mixed $variantIds,
        mixed $axisGroups,
        bool $onlyWithoutSpecific = false,
    ): int {
        return count($this->resolveVariantMediaTargetIds([
            'variant_ids' => is_array($variantIds) ? $variantIds : [],
            'axis_groups' => is_array($axisGroups) ? $axisGroups : [],
            'only_without_specific' => $onlyWithoutSpecific,
        ]));
    }

    private function runVariantMediaMutation(\Closure $mutation, string $successTitle): void
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
        } catch (VariantMediaException $exception) {
            Notification::make()
                ->danger()
                ->title('Не вдалося змінити медіа варіантів')
                ->body($exception->getMessage())
                ->send();

            throw new Halt;
        }
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
