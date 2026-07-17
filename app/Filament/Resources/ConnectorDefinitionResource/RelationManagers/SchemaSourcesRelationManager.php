<?php

namespace App\Filament\Resources\ConnectorDefinitionResource\RelationManagers;

use App\Enums\ConnectorSchemaAcquisitionMode;
use App\Enums\ConnectorSchemaScope;
use App\Enums\ConnectorSchemaSourceKind;
use App\Enums\ConnectorSchemaVerificationStatus;
use App\Models\ConnectorSchemaSource;
use App\Services\Connectors\ConnectorSchemaSourceService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class SchemaSourcesRelationManager extends RelationManager
{
    protected static string $relationship = 'schemaSources';

    protected static ?string $title = 'Джерела схеми';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('code')
                    ->label('Код')
                    ->required()
                    ->maxLength(64)
                    ->alphaDash()
                    ->disabled(fn (?ConnectorSchemaSource $record): bool => $record !== null)
                    ->dehydrated(),

                Forms\Components\TextInput::make('label')
                    ->label('Мітка')
                    ->required()
                    ->maxLength(255),

                Forms\Components\Select::make('source_kind')
                    ->label('Тип джерела')
                    ->options(ConnectorSchemaSourceKind::options())
                    ->required()
                    ->live(),

                Forms\Components\Select::make('acquisition_mode')
                    ->label('Режим отримання')
                    ->options(ConnectorSchemaAcquisitionMode::options())
                    ->required(),

                Forms\Components\Select::make('schema_scope')
                    ->label('Область схеми')
                    ->options(ConnectorSchemaScope::options())
                    ->required()
                    ->live(),

                Forms\Components\TextInput::make('reference_url')
                    ->label('URL довідки')
                    ->url()
                    ->maxLength(2048),

                Forms\Components\TextInput::make('endpoint_path')
                    ->label('Шлях endpoint')
                    ->maxLength(255)
                    ->visible(fn (Forms\Get $get): bool => $get('schema_scope') === ConnectorSchemaScope::Account->value),

                Forms\Components\TextInput::make('schema_version')
                    ->label('Версія схеми')
                    ->maxLength(64),

                Forms\Components\Toggle::make('is_primary')
                    ->label('Первинне')
                    ->helperText('Лише одне первинне джерело на область схеми.'),

                Forms\Components\Select::make('verification_status')
                    ->label('Статус перевірки')
                    ->options(ConnectorSchemaVerificationStatus::options())
                    ->required()
                    ->live(),

                Forms\Components\DateTimePicker::make('last_verified_at')
                    ->label('Остання перевірка')
                    ->visible(fn (Forms\Get $get): bool => $get('verification_status') === ConnectorSchemaVerificationStatus::Verified->value),

                Forms\Components\TextInput::make('sort_order')
                    ->label('Порядок')
                    ->numeric()
                    ->default(0),

                Forms\Components\Textarea::make('notes')
                    ->label('Примітки')
                    ->rows(2)
                    ->columnSpanFull(),
            ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('label')
            ->columns([
                Tables\Columns\TextColumn::make('code')->label('Код'),
                Tables\Columns\TextColumn::make('label')->label('Мітка'),
                Tables\Columns\TextColumn::make('schema_scope')
                    ->label('Область')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state instanceof ConnectorSchemaScope ? $state->label() : (string) $state),
                Tables\Columns\IconColumn::make('is_primary')->label('Первинне')->boolean(),
                Tables\Columns\TextColumn::make('verification_status')
                    ->label('Перевірка')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state instanceof ConnectorSchemaVerificationStatus ? $state->label() : (string) $state),
                Tables\Columns\TextColumn::make('last_verified_at')
                    ->label('Перевірено')
                    ->dateTime('d.m.Y H:i')
                    ->placeholder('—'),
            ])
            ->defaultSort('sort_order')
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->mutateFormDataUsing(function (array $data): array {
                        app(ConnectorSchemaSourceService::class)->validateInvariants($data);

                        return $data;
                    })
                    ->after(function (ConnectorSchemaSource $record, array $data): void {
                        if (! empty($data['is_primary'])) {
                            app(ConnectorSchemaSourceService::class)->setPrimary($record, true);
                        }
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->mutateFormDataUsing(function (array $data, ConnectorSchemaSource $record): array {
                        app(ConnectorSchemaSourceService::class)->validateInvariants($data, $record);

                        return $data;
                    })
                    ->after(function (ConnectorSchemaSource $record, array $data): void {
                        if (array_key_exists('is_primary', $data)) {
                            app(ConnectorSchemaSourceService::class)->setPrimary($record, (bool) $data['is_primary']);
                        }
                    }),
                Tables\Actions\DeleteAction::make(),
            ]);
    }
}
