<?php

namespace App\Filament\Resources;

use App\Enums\ConnectorDefinitionStatus;
use App\Enums\ConnectorDirection;
use App\Filament\Resources\ConnectorDefinitionResource\Pages;
use App\Filament\Resources\ConnectorDefinitionResource\RelationManagers\SchemaSourcesRelationManager;
use App\Models\ConnectorDefinition;
use App\Models\User;
use App\Support\Platform\PlatformAdminAuthorization;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class ConnectorDefinitionResource extends Resource
{
    protected static bool $hasTitleCaseModelLabel = false;

    protected static ?string $model = ConnectorDefinition::class;

    protected static ?string $navigationIcon = 'heroicon-o-server-stack';

    protected static ?string $navigationGroup = 'Модель даних і коннектори';

    protected static ?string $navigationLabel = 'Платформи та джерела';

    protected static ?string $modelLabel = 'платформу';

    protected static ?string $pluralModelLabel = 'Платформи та джерела';

    protected static ?int $navigationSort = 2;

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User && PlatformAdminAuthorization::canManage($user);
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Платформа')->schema([
                    Forms\Components\TextInput::make('code')
                        ->label('Код')
                        ->required()
                        ->maxLength(64)
                        ->alphaDash()
                        ->unique(ignoreRecord: true)
                        ->disabled(fn (?ConnectorDefinition $record): bool => $record !== null)
                        ->dehydrated(),

                    Forms\Components\TextInput::make('name')
                        ->label('Назва')
                        ->required()
                        ->maxLength(255),

                    Forms\Components\Select::make('direction')
                        ->label('Напрямок')
                        ->options(ConnectorDirection::options())
                        ->required(),

                    Forms\Components\Select::make('status')
                        ->label('Статус')
                        ->options(ConnectorDefinitionStatus::options())
                        ->required()
                        ->visible(fn (?ConnectorDefinition $record): bool => $record !== null),

                    Forms\Components\Textarea::make('notes')
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
                Tables\Columns\TextColumn::make('code')
                    ->label('Код')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('name')
                    ->label('Назва')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('direction')
                    ->label('Напрямок')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state instanceof ConnectorDirection ? $state->label() : (string) $state),
                Tables\Columns\TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->color(fn ($state): string => match ($state) {
                        ConnectorDefinitionStatus::Active => 'success',
                        ConnectorDefinitionStatus::Deprecated => 'gray',
                        default => 'warning',
                    })
                    ->formatStateUsing(fn ($state): string => $state instanceof ConnectorDefinitionStatus ? $state->label() : (string) $state),
                Tables\Columns\TextColumn::make('schema_sources_count')
                    ->label('Джерела')
                    ->counts('schemaSources'),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Оновлено')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('code')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Статус')
                    ->options(ConnectorDefinitionStatus::options()),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([]);
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
            'index' => Pages\ListConnectorDefinitions::route('/'),
            'create' => Pages\CreateConnectorDefinition::route('/create'),
            'edit' => Pages\EditConnectorDefinition::route('/{record}/edit'),
        ];
    }
}
