<?php

namespace App\Filament\Resources\ConnectorDefinitionResource\RelationManagers;

use Filament\Schemas\Schema;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;
use App\Enums\ConnectorDefinitionStatus;
use App\Enums\ConnectorSchemaAcquisitionMode;
use App\Enums\ConnectorSchemaScope;
use App\Enums\ConnectorSchemaSourceKind;
use App\Enums\ConnectorSchemaVerificationStatus;
use App\Models\ConnectorDefinition;
use App\Models\ConnectorSchemaSource;
use App\Services\Connectors\ConnectorDefinitionGovernanceService;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class SchemaSourcesRelationManager extends RelationManager
{
    protected static string $relationship = 'schemaSources';

    protected static ?string $title = 'Джерела схеми';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')
                    ->label('Код')
                    ->required()
                    ->maxLength(64)
                    ->alphaDash()
                    ->disabled(fn (?ConnectorSchemaSource $record): bool => $record !== null)
                    ->dehydrated(),

                TextInput::make('label')
                    ->label('Мітка')
                    ->required()
                    ->maxLength(255),

                Select::make('source_kind')
                    ->label('Тип джерела')
                    ->options(ConnectorSchemaSourceKind::options())
                    ->required()
                    ->live(),

                Select::make('acquisition_mode')
                    ->label('Режим отримання')
                    ->options(ConnectorSchemaAcquisitionMode::options())
                    ->required(),

                Select::make('schema_scope')
                    ->label('Область схеми')
                    ->options(ConnectorSchemaScope::options())
                    ->required()
                    ->live(),

                TextInput::make('reference_url')
                    ->label('URL довідки')
                    ->url()
                    ->maxLength(2048),

                TextInput::make('endpoint_path')
                    ->label('Шлях endpoint')
                    ->maxLength(255)
                    ->visible(fn (Get $get): bool => $get('schema_scope') === ConnectorSchemaScope::Account->value),

                TextInput::make('schema_version')
                    ->label('Версія схеми')
                    ->maxLength(64),

                Toggle::make('is_primary')
                    ->label('Первинне')
                    ->helperText('Лише одне первинне джерело на область схеми.'),

                Select::make('verification_status')
                    ->label('Статус перевірки')
                    ->options(ConnectorSchemaVerificationStatus::options())
                    ->required()
                    ->live(),

                DateTimePicker::make('last_verified_at')
                    ->label('Остання перевірка')
                    ->visible(fn (Get $get): bool => $get('verification_status') === ConnectorSchemaVerificationStatus::Verified->value),

                TextInput::make('sort_order')
                    ->label('Порядок')
                    ->numeric()
                    ->default(0),

                Textarea::make('notes')
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
                TextColumn::make('code')->label('Код'),
                TextColumn::make('label')->label('Мітка'),
                TextColumn::make('schema_scope')
                    ->label('Область')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state instanceof ConnectorSchemaScope ? $state->label() : (string) $state),
                IconColumn::make('is_primary')->label('Первинне')->boolean(),
                TextColumn::make('verification_status')
                    ->label('Перевірка')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state instanceof ConnectorSchemaVerificationStatus ? $state->label() : (string) $state),
                TextColumn::make('last_verified_at')
                    ->label('Перевірено')
                    ->dateTime('d.m.Y H:i')
                    ->placeholder('—'),
            ])
            ->defaultSort('sort_order')
            ->headerActions([
                CreateAction::make()
                    ->using(function (array $data, Table $table): Model {
                        $owner = $this->resolveOwnerDefinition($table);

                        return $this->mutateSourceWithGovernance(
                            $owner,
                            fn () => app(ConnectorDefinitionGovernanceService::class)->createSource($owner, $data),
                        );
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->using(function (array $data, Model $record, Table $table): Model {
                        $owner = $this->resolveOwnerDefinition($table);

                        return $this->mutateSourceWithGovernance(
                            $owner,
                            fn () => app(ConnectorDefinitionGovernanceService::class)->updateSource($record, $data),
                        );
                    }),
                DeleteAction::make()
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
