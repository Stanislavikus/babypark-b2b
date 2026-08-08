<?php

namespace App\Filament\Resources;

use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Actions\EditAction;
use App\Filament\Resources\ConnectorDefinitionResource\Pages\ListConnectorDefinitions;
use App\Filament\Resources\ConnectorDefinitionResource\Pages\CreateConnectorDefinition;
use App\Filament\Resources\ConnectorDefinitionResource\Pages\EditConnectorDefinition;
use App\Enums\ConnectorDefinitionStatus;
use App\Enums\ConnectorDirection;
use App\Filament\Resources\ConnectorDefinitionResource\Pages;
use App\Filament\Resources\ConnectorDefinitionResource\RelationManagers\SchemaSourcesRelationManager;
use App\Models\ConnectorDefinition;
use App\Models\User;
use App\Support\Platform\PlatformAdminAuthorization;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class ConnectorDefinitionResource extends Resource
{
    protected static bool $hasTitleCaseModelLabel = false;

    protected static ?string $model = ConnectorDefinition::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-server-stack';

    protected static string | \UnitEnum | null $navigationGroup = 'Модель даних і коннектори';

    protected static ?string $navigationLabel = 'Платформи та джерела';

    protected static ?string $modelLabel = 'платформу';

    protected static ?string $pluralModelLabel = 'Платформи та джерела';

    protected static ?int $navigationSort = 2;

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User && PlatformAdminAuthorization::canManage($user);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Платформа')->schema([
                    TextInput::make('code')
                        ->label('Код')
                        ->required()
                        ->maxLength(64)
                        ->alphaDash()
                        ->unique(ignoreRecord: true)
                        ->disabled(fn (?ConnectorDefinition $record): bool => $record !== null)
                        ->dehydrated(),

                    TextInput::make('name')
                        ->label('Назва')
                        ->required()
                        ->maxLength(255),

                    Select::make('direction')
                        ->label('Напрямок')
                        ->options(ConnectorDirection::options())
                        ->required(),

                    Select::make('status')
                        ->label('Статус')
                        ->options(ConnectorDefinitionStatus::options())
                        ->required()
                        ->visible(fn (?ConnectorDefinition $record): bool => $record !== null),

                    Textarea::make('notes')
                        ->label('Примітки')
                        ->rows(3)
                        ->columnSpanFull(),
                ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label('Код')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Назва')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('direction')
                    ->label('Напрямок')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state instanceof ConnectorDirection ? $state->label() : (string) $state),
                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->color(fn ($state): string => match ($state) {
                        ConnectorDefinitionStatus::Active => 'success',
                        ConnectorDefinitionStatus::Deprecated => 'gray',
                        default => 'warning',
                    })
                    ->formatStateUsing(fn ($state): string => $state instanceof ConnectorDefinitionStatus ? $state->label() : (string) $state),
                TextColumn::make('schema_sources_count')
                    ->label('Джерела')
                    ->counts('schemaSources'),
                TextColumn::make('updated_at')
                    ->label('Оновлено')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('code')
            ->filters([
                SelectFilter::make('status')
                    ->label('Статус')
                    ->options(ConnectorDefinitionStatus::options()),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([]);
    }

    public static function getRelations(): array
    {
        return [
            SchemaSourcesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListConnectorDefinitions::route('/'),
            'create' => CreateConnectorDefinition::route('/create'),
            'edit' => EditConnectorDefinition::route('/{record}/edit'),
        ];
    }
}
