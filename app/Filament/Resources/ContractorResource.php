<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ContractorResource\Pages;
use App\Filament\Resources\ContractorResource\RelationManagers;
use App\Models\Contractor;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ContractorResource extends Resource
{
    protected static ?string $model = Contractor::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $navigationGroup = 'B2B';

    protected static ?string $modelLabel = 'контрагент';

    protected static ?string $pluralModelLabel = 'Контрагенти';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Основне')->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Назва')
                        ->required()
                        ->maxLength(255),
                    Forms\Components\TextInput::make('short_name')
                        ->label('Коротка назва')
                        ->maxLength(255),
                    Forms\Components\TextInput::make('login')
                        ->label('Логін')
                        ->required()
                        ->maxLength(255),
                    Forms\Components\TextInput::make('password')
                        ->label('Пароль')
                        ->password()
                        ->dehydrated(fn (?string $state): bool => filled($state))
                        ->required(fn (string $operation): bool => $operation === 'create')
                        ->maxLength(255),
                    Forms\Components\Toggle::make('is_active')
                        ->label('Активний')
                        ->default(true),
                ])->columns(2),
                Forms\Components\Section::make('Реквізити')->schema([
                    Forms\Components\TextInput::make('edrpou')
                        ->label('ЄДРПОУ')
                        ->maxLength(20),
                    Forms\Components\TextInput::make('ipn')
                        ->label('ІПН')
                        ->maxLength(20),
                    Forms\Components\TextInput::make('manager_name')
                        ->label('Менеджер')
                        ->maxLength(255),
                    Forms\Components\TextInput::make('manager_phone')
                        ->label('Телефон менеджера')
                        ->tel()
                        ->maxLength(255),
                    Forms\Components\TextInput::make('email')
                        ->label('Email')
                        ->email()
                        ->maxLength(255),
                ])->columns(2),
                Forms\Components\Section::make('Кредит')->schema([
                    Forms\Components\TextInput::make('payment_delay_days')
                        ->label('Відстрочка (днів)')
                        ->numeric()
                        ->default(0),
                    Forms\Components\TextInput::make('credit_limit')
                        ->label('Кредитний ліміт')
                        ->numeric()
                        ->prefix('₴'),
                    Forms\Components\TextInput::make('current_debt')
                        ->label('Поточний борг')
                        ->numeric()
                        ->prefix('₴'),
                ])->columns(3),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Контрагент')->schema([
                    Infolists\Components\TextEntry::make('name')->label('Назва'),
                    Infolists\Components\TextEntry::make('login')->label('Логін'),
                    Infolists\Components\IconEntry::make('is_active')->label('Активний')->boolean(),
                    Infolists\Components\TextEntry::make('manager_name')->label('Менеджер'),
                    Infolists\Components\TextEntry::make('manager_phone')->label('Телефон'),
                    Infolists\Components\TextEntry::make('email')->label('Email'),
                ])->columns(2),
                Infolists\Components\Section::make('Кредит')->schema([
                    Infolists\Components\TextEntry::make('credit_limit')
                        ->label('Кредитний ліміт')
                        ->money('UAH'),
                    Infolists\Components\TextEntry::make('current_debt')
                        ->label('Поточний борг')
                        ->money('UAH'),
                    Infolists\Components\TextEntry::make('payment_delay_days')
                        ->label('Відстрочка')
                        ->suffix(' дн.'),
                    Infolists\Components\TextEntry::make('available_credit')
                        ->label('Доступний кредит')
                        ->state(fn (Contractor $record): float => max(0, (float) $record->credit_limit - (float) $record->current_debt))
                        ->money('UAH'),
                ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Назва')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('login')
                    ->label('Логін')
                    ->searchable(),
                Tables\Columns\TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->copyable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('credit_limit')
                    ->label('Ліміт')
                    ->money('UAH')
                    ->sortable(),
                Tables\Columns\TextColumn::make('current_debt')
                    ->label('Борг')
                    ->money('UAH')
                    ->sortable(),
                Tables\Columns\TextColumn::make('payment_delay_days')
                    ->label('Відстрочка')
                    ->suffix(' дн.')
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Активний')
                    ->boolean(),
                Tables\Columns\TextColumn::make('orders_count')
                    ->label('Замовлень')
                    ->counts('orders')
                    ->sortable(),
            ])
            ->defaultSort('name')
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Активний'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\OrdersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListContractors::route('/'),
            'view' => Pages\ViewContractor::route('/{record}'),
            'edit' => Pages\EditContractor::route('/{record}/edit'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
