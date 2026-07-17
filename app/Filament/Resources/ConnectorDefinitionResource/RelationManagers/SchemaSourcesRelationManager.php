<?php

namespace App\Filament\Resources\ConnectorDefinitionResource\RelationManagers;

use App\Enums\ConnectorDefinitionStatus;
use App\Enums\ConnectorSchemaAcquisitionMode;
use App\Enums\ConnectorSchemaScope;
use App\Enums\ConnectorSchemaSourceKind;
use App\Enums\ConnectorSchemaVerificationStatus;
use App\Models\ConnectorDefinition;
use App\Models\ConnectorSchemaSource;
use App\Services\Connectors\ConnectorDefinitionGovernanceService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

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
                    ->using(function (array $data, Table $table): Model {
                        $owner = $this->resolveOwnerDefinition($table);

                        return $this->mutateSourceWithGovernance(
                            $owner,
                            fn () => app(ConnectorDefinitionGovernanceService::class)->createSource($owner, $data),
                        );
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->using(function (array $data, Model $record, Table $table): Model {
                        $owner = $this->resolveOwnerDefinition($table);

                        return $this->mutateSourceWithGovernance(
                            $owner,
                            fn () => app(ConnectorDefinitionGovernanceService::class)->updateSource($record, $data),
                        );
                    }),
                Tables\Actions\DeleteAction::make()
                    ->using(function (Model $record, Table $table): bool {
                        $owner = $this->resolveOwnerDefinition($table);

                        $this->mutateSourceWithGovernance(
                            $owner,
                            function () use ($record): void {
                                app(ConnectorDefinitionGovernanceService::class)->deleteSource($record);
                            },
                        );

                        return true;
                    }),
            ]);
    }

    private function resolveOwnerDefinition(Table $table): ConnectorDefinition
    {
        /** @var RelationManager $livewire */
        $livewire = $table->getLivewire();

        return $livewire->getOwnerRecord();
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function mutateSourceWithGovernance(ConnectorDefinition $owner, callable $callback): mixed
    {
        $wasActive = $owner->status === ConnectorDefinitionStatus::Active;

        $result = $callback();

        $owner->refresh();

        if ($wasActive && $owner->status === ConnectorDefinitionStatus::Draft) {
            Notification::make()
                ->warning()
                ->title('Платформу переведено в чернетку')
                ->body('Втрачено перевірене первинне глобальне джерело схеми.')
                ->send();
        }

        return $result;
    }
}
