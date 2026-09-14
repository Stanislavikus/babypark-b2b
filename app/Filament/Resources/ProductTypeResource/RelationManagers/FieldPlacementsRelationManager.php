<?php

namespace App\Filament\Resources\ProductTypeResource\RelationManagers;

use App\Enums\AttributeStatus;
use App\Enums\FieldObjectType;
use App\Filament\Resources\ProductTypeResource;
use App\Models\FieldBinding;
use App\Models\ProductType;
use App\Models\ProductTypeFieldPlacement;
use App\Models\ProductTypeGroupPlacement;
use App\Models\User;
use App\Services\ProductStructure\ProductStructureMutationService;
use App\Support\ProductStructure\Exceptions\ProductStructureStaleException;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class FieldPlacementsRelationManager extends RelationManager
{
    protected static string $relationship = 'fieldPlacements';

    protected static ?string $title = 'Поля типу товару';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof ProductType
            && ! $ownerRecord->is_default
            && ProductTypeResource::canEdit($ownerRecord);
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('fieldBinding.fieldDefinition.localized_labels.uk')->label('Поле'),
            TextColumn::make('groupPlacement.attributeGroup.localized_labels.uk')->label('Група'),
            TextColumn::make('sort_order')->label('Порядок')->sortable(),
            IconColumn::make('required_for_completeness')->label('Обов’язкове')->boolean(),
        ])->defaultSort('sort_order')->headerActions([
            $this->makeAddFieldAction(),
        ])->recordActions([
            $this->makeConfigureFieldAction(),
        ]);
    }

    private function makeAddFieldAction(): Action
    {
        return Action::make('addField')
            ->label('Додати поле')
            ->fillForm(fn (): array => ['expected_structure_revision' => (int) $this->getOwnerRecord()->structure_revision])
            ->schema($this->fieldSchema(includeBinding: true))
            ->action(function (array $data): void {
                $this->mutateField($data, null);
            });
    }

    private function makeConfigureFieldAction(): Action
    {
        return Action::make('configureField')
            ->label('Налаштувати')
            ->fillForm(fn (ProductTypeFieldPlacement $record): array => [
                'product_type_group_placement_id' => $record->product_type_group_placement_id,
                'sort_order' => $record->sort_order,
                'required_for_completeness' => $record->required_for_completeness,
                'expected_structure_revision' => (int) $this->getOwnerRecord()->structure_revision,
            ])
            ->schema($this->fieldSchema(includeBinding: false))
            ->action(function (array $data, ProductTypeFieldPlacement $record): void {
                $this->mutateField($data, $record);
            });
    }

    private function fieldSchema(bool $includeBinding): array
    {
        $schema = [];
        if ($includeBinding) {
            $schema[] = Select::make('field_binding_id')
                ->label('Поле')
                ->options(fn (): array => $this->bindingOptions())
                ->required()
                ->searchable();
        }
        $schema[] = Hidden::make('expected_structure_revision')->required();
        $schema[] = Select::make('product_type_group_placement_id')
            ->label('Група')
            ->options(fn (): array => $this->groupPlacementOptions())
            ->required()
            ->searchable();
        $schema[] = TextInput::make('sort_order')->label('Порядок')->numeric()->required()->default(100);
        $schema[] = Toggle::make('required_for_completeness')->label('Обов’язкове для повноти');

        return $schema;
    }

    private function bindingOptions(): array
    {
        /** @var ProductType $type */
        $type = $this->getOwnerRecord();

        return FieldBinding::withoutWorkspaceScope()
            ->with('fieldDefinition')
            ->where('status', AttributeStatus::Active)
            ->whereIn('object_type', [FieldObjectType::Product, FieldObjectType::ProductVariant])
            ->where(function ($query) use ($type): void {
                $query->whereNull('workspace_id')->orWhere('workspace_id', $type->workspace_id);
            })
            ->orderBy('sort_order')
            ->get()
            ->mapWithKeys(fn (FieldBinding $binding): array => [
                $binding->id => sprintf(
                    '%s — %s',
                    (string) ($binding->fieldDefinition?->localized_labels['uk']
                        ?? $binding->fieldDefinition?->localized_labels['en']
                        ?? $binding->fieldDefinition?->code
                        ?? $binding->id),
                    $binding->object_type === FieldObjectType::Product ? 'Товар' : 'Варіант',
                ),
            ])->all();
    }

    private function groupPlacementOptions(): array
    {
        return ProductTypeGroupPlacement::withoutWorkspaceScope()
            ->with('attributeGroup')
            ->where('product_type_id', $this->getOwnerRecord()->id)
            ->orderBy('sort_order')
            ->get()
            ->mapWithKeys(fn (ProductTypeGroupPlacement $placement): array => [
                $placement->id => (string) ($placement->attributeGroup?->localized_labels['uk']
                    ?? $placement->attributeGroup?->localized_labels['en']
                    ?? $placement->attributeGroup?->code
                    ?? $placement->id),
            ])->all();
    }

    private function mutateField(array $data, ?ProductTypeFieldPlacement $record): void
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);
        /** @var ProductType $type */
        $type = $this->getOwnerRecord()->fresh();
        $groupPlacement = ProductTypeGroupPlacement::withoutWorkspaceScope()
            ->where('workspace_id', $type->workspace_id)
            ->where('product_type_id', $type->id)
            ->findOrFail((string) $data['product_type_group_placement_id']);
        $binding = $record?->fieldBinding
            ?? FieldBinding::withoutWorkspaceScope()->findOrFail((string) $data['field_binding_id']);

        try {
            app(ProductStructureMutationService::class)->putFieldPlacement(
                $actor,
                $type->workspace,
                $type,
                $groupPlacement,
                $binding,
                (int) $data['sort_order'],
                (bool) ($data['required_for_completeness'] ?? false),
                (int) $data['expected_structure_revision'],
            );
            Notification::make()->success()->title('Структуру поля збережено')->send();
        } catch (ProductStructureStaleException $exception) {
            Notification::make()->danger()->title('Структура вже змінилася')->body($exception->getMessage())->send();
        }
    }
}
