<?php

namespace App\Filament\Resources\ProductTypeResource\RelationManagers;

use App\Filament\Resources\ProductTypeResource;
use App\Models\AttributeGroup;
use App\Models\ProductType;
use App\Models\ProductTypeGroupPlacement;
use App\Models\User;
use App\Services\ProductStructure\ProductStructureMutationService;
use App\Support\ProductStructure\Exceptions\ProductStructureStaleException;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class GroupPlacementsRelationManager extends RelationManager
{
    protected static string $relationship = 'groupPlacements';

    protected static ?string $title = 'Групи типу товару';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof ProductType
            && ! $ownerRecord->is_default
            && ProductTypeResource::canEdit($ownerRecord);
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('attributeGroup.localized_labels.uk')->label('Група'),
            TextColumn::make('sort_order')->label('Порядок')->sortable(),
            IconColumn::make('is_optional')->label('Опційна')->boolean(),
            IconColumn::make('default_active')->label('Активна за замовчуванням')->boolean(),
        ])->defaultSort('sort_order')->headerActions([
            $this->makeAddGroupAction(),
        ])->recordActions([
            $this->makeConfigureGroupAction(),
        ]);
    }

    private function makeAddGroupAction(): Action
    {
        return Action::make('addGroup')
            ->label('Додати групу')
            ->schema($this->groupSchema(includeGroup: true))
            ->action(function (array $data): void {
                $this->mutateGroup($data, null);
            });
    }

    private function makeConfigureGroupAction(): Action
    {
        return Action::make('configureGroup')
            ->label('Налаштувати')
            ->fillForm(fn (ProductTypeGroupPlacement $record): array => [
                'sort_order' => $record->sort_order,
                'is_optional' => $record->is_optional,
                'default_active' => $record->default_active,
            ])
            ->schema($this->groupSchema(includeGroup: false))
            ->action(function (array $data, ProductTypeGroupPlacement $record): void {
                $this->mutateGroup($data, $record);
            });
    }

    private function groupSchema(bool $includeGroup): array
    {
        $schema = [];
        if ($includeGroup) {
            $schema[] = Select::make('attribute_group_id')
                ->label('Група')
                ->options(fn (): array => AttributeGroup::withoutWorkspaceScope()
                    ->where('workspace_id', $this->getOwnerRecord()->workspace_id)
                    ->orderBy('code')
                    ->get()
                    ->mapWithKeys(fn (AttributeGroup $group): array => [
                        $group->id => (string) ($group->localized_labels['uk'] ?? $group->localized_labels['en'] ?? $group->code),
                    ])->all())
                ->required()
                ->searchable();
        }
        $schema[] = TextInput::make('sort_order')->label('Порядок')->numeric()->required()->default(100);
        $schema[] = Toggle::make('is_optional')->label('Опційна група')->live();
        $schema[] = Toggle::make('default_active')->label('Активна за замовчуванням')->default(true);

        return $schema;
    }

    private function mutateGroup(array $data, ?ProductTypeGroupPlacement $record): void
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);
        /** @var ProductType $type */
        $type = $this->getOwnerRecord()->fresh();
        $group = $record?->attributeGroup
            ?? AttributeGroup::withoutWorkspaceScope()
                ->where('workspace_id', $type->workspace_id)
                ->findOrFail((string) $data['attribute_group_id']);

        try {
            app(ProductStructureMutationService::class)->putGroupPlacement(
                $actor,
                $type->workspace,
                $type,
                $group,
                (int) $data['sort_order'],
                (bool) ($data['is_optional'] ?? false),
                (bool) ($data['default_active'] ?? true),
                $type->structure_revision,
            );
            Notification::make()->success()->title('Структуру групи збережено')->send();
        } catch (ProductStructureStaleException $exception) {
            Notification::make()->danger()->title('Структура вже змінилася')->body($exception->getMessage())->send();
        }
    }
}
