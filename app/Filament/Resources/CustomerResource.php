<?php

namespace App\Filament\Resources;

use App\Enums\UserRole;
use App\Exceptions\Pricing\InvalidCustomerBatchException;
use App\Exceptions\Pricing\InvalidPriceListAssignmentException;
use App\Filament\Resources\CustomerResource\Pages;
use App\Filament\Resources\CustomerResource\RelationManagers;
use App\Filament\Resources\CustomerResource\Support\CustomerPriceListUi;
use App\Models\Customer;
use App\Models\PriceList;
use App\Models\User;
use App\Services\Pricing\CustomerPriceListAssignmentDisplayState;
use App\Support\Workspace\WorkspaceContext;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Exceptions\Halt;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class CustomerResource extends Resource
{
    protected static ?string $model = Customer::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $navigationGroup = 'B2B';

    protected static ?string $modelLabel = 'клієнт';

    protected static ?string $pluralModelLabel = 'Клієнти';

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
                        ->label('Менеджер (текст)')
                        ->maxLength(255),
                    Forms\Components\TextInput::make('manager_phone')
                        ->label('Телефон менеджера (текст)')
                        ->tel()
                        ->maxLength(255),
                    Forms\Components\TextInput::make('email')
                        ->label('Email')
                        ->email()
                        ->maxLength(255),
                    Forms\Components\Select::make('account_manager_id')
                        ->label('Акаунт менеджер')
                        ->options(
                            User::whereIn('role', [
                                UserRole::Manager->value,
                                UserRole::Merchandiser->value,
                                UserRole::Director->value,
                            ])
                                ->where('is_active', true)
                                ->orderBy('name')
                                ->pluck('name', 'id')
                        )
                        ->searchable()
                        ->nullable()
                        ->placeholder('— не призначено —'),
                    Forms\Components\Select::make('backup_manager_id')
                        ->label('Резервний менеджер')
                        ->options(
                            User::whereIn('role', [
                                UserRole::Manager->value,
                                UserRole::Merchandiser->value,
                                UserRole::Director->value,
                            ])
                                ->where('is_active', true)
                                ->orderBy('name')
                                ->pluck('name', 'id')
                        )
                        ->searchable()
                        ->nullable()
                        ->placeholder('— не призначено —'),
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
                Forms\Components\Section::make('Ціни')->schema([
                    Forms\Components\Select::make('default_price_list_id')
                        ->label('Прайс-лист')
                        ->options(fn (Customer $record): array => CustomerPriceListUi::assignmentService()
                            ->selectableOptions($record->workspace_id))
                        ->getOptionLabelUsing(function (?string $value, Customer $record): ?string {
                            if ($value === null || $value === '') {
                                return 'За замовчуванням (використовується основний прайс-лист компанії)';
                            }

                            $previewRecord = clone $record;
                            $previewRecord->default_price_list_id = $value;
                            $display = CustomerPriceListUi::resolveDisplay($previewRecord);

                            return $display->historicalSelectLabel()
                                ?? PriceList::withoutWorkspaceScope()->find($value)?->name;
                        })
                        ->placeholder('За замовчуванням (використовується основний прайс-лист компанії)')
                        ->native(false)
                        ->searchable()
                        ->live()
                        ->helperText(fn (Get $get, Customer $record): string => CustomerPriceListUi::resolveDisplay($record)
                            ->formHelperText($get('default_price_list_id')))
                        ->rules([
                            fn (Customer $record): \Closure => function (string $attribute, mixed $value, \Closure $fail) use ($record): void {
                                $originalTargetId = $record->getOriginal('default_price_list_id');
                                $submittedTargetId = $value === '' ? null : $value;

                                if ($submittedTargetId === $originalTargetId) {
                                    return;
                                }

                                try {
                                    CustomerPriceListUi::assignmentService()
                                        ->validateTarget($record->workspace_id, $submittedTargetId);
                                } catch (InvalidPriceListAssignmentException $exception) {
                                    $fail($exception->getMessage());
                                }
                            },
                        ]),
                    Forms\Components\Placeholder::make('price_list_warning')
                        ->label('')
                        ->content(function (Get $get, Customer $record): ?string {
                            $selected = $get('default_price_list_id');

                            if ($selected === null || $selected === '') {
                                return null;
                            }

                            $previewRecord = clone $record;
                            $previewRecord->default_price_list_id = $selected;

                            return CustomerPriceListUi::resolveDisplay($previewRecord)->formWarning();
                        })
                        ->visible(function (Get $get, Customer $record): bool {
                            $selected = $get('default_price_list_id');

                            if ($selected === null || $selected === '') {
                                return false;
                            }

                            $previewRecord = clone $record;
                            $previewRecord->default_price_list_id = $selected;

                            return in_array(
                                CustomerPriceListUi::resolveDisplay($previewRecord)->state,
                                [
                                    CustomerPriceListAssignmentDisplayState::InactiveHistorical,
                                    CustomerPriceListAssignmentDisplayState::RedundantDirect,
                                ],
                                true,
                            );
                        }),
                ]),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Клієнт')->schema([
                    Infolists\Components\TextEntry::make('name')->label('Назва'),
                    Infolists\Components\TextEntry::make('login')->label('Логін'),
                    Infolists\Components\IconEntry::make('is_active')->label('Активний')->boolean(),
                    Infolists\Components\TextEntry::make('manager_name')->label('Менеджер (текст)'),
                    Infolists\Components\TextEntry::make('manager_phone')->label('Телефон (текст)'),
                    Infolists\Components\TextEntry::make('email')->label('Email'),
                    Infolists\Components\TextEntry::make('accountManager.name')
                        ->label('Акаунт менеджер')
                        ->placeholder('—'),
                    Infolists\Components\TextEntry::make('backupManager.name')
                        ->label('Резервний менеджер')
                        ->placeholder('—'),
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
                        ->state(fn (Customer $record): float => max(0, (float) $record->credit_limit - (float) $record->current_debt))
                        ->money('UAH'),
                ])->columns(2),
                Infolists\Components\Section::make('Ціни')->schema([
                    Infolists\Components\TextEntry::make('price_list_assignment')
                        ->label('Прайс-лист')
                        ->state(fn (Customer $record): string => CustomerPriceListUi::resolveDisplay($record)->infolistLabel()),
                    Infolists\Components\TextEntry::make('price_list_assignment_description')
                        ->label('')
                        ->state(fn (Customer $record): ?string => CustomerPriceListUi::resolveDisplay($record)->infolistDescription())
                        ->placeholder('—')
                        ->color('gray'),
                ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('defaultPriceList'))
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
                Tables\Columns\TextColumn::make('default_price_list_id')
                    ->label('Прайс-лист')
                    ->state(fn (Customer $record): string => CustomerPriceListUi::resolveDisplay($record)->tableLabel())
                    ->description(fn (Customer $record): ?string => CustomerPriceListUi::resolveDisplay($record)->tableDescription())
                    ->color(fn (Customer $record): string => match (CustomerPriceListUi::resolveDisplay($record)->state) {
                        CustomerPriceListAssignmentDisplayState::InactiveHistorical,
                        CustomerPriceListAssignmentDisplayState::RedundantDirect => 'warning',
                        default => 'gray',
                    }),
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
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('assign_price_list')
                        ->label('Призначити прайс-лист')
                        ->icon('heroicon-o-currency-dollar')
                        ->form([
                            Forms\Components\Select::make('target_price_list_id')
                                ->label('Прайс-лист')
                                ->options(fn (): array => CustomerPriceListUi::assignmentService()
                                    ->bulkSelectableOptions(app(WorkspaceContext::class)->id()))
                                ->required()
                                ->native(false)
                                ->searchable()
                                ->live(),
                            Forms\Components\Placeholder::make('assignment_preview')
                                ->label('Попередній перегляд')
                                ->content(function (Get $get, Pages\ListCustomers $livewire): string {
                                    $sentinel = $get('target_price_list_id');

                                    if (blank($sentinel)) {
                                        return 'Оберіть прайс-лист для попереднього перегляду.';
                                    }

                                    $workspaceId = app(WorkspaceContext::class)->id();
                                    $service = CustomerPriceListUi::assignmentService();
                                    $targetId = $service->resolveTargetFromSentinel($sentinel);
                                    $customerIds = $livewire->selectedTableRecords;

                                    if ($customerIds === []) {
                                        return '—';
                                    }

                                    try {
                                        $preview = $service->preview($workspaceId, $customerIds, $targetId);

                                        return CustomerPriceListUi::formatPreviewText($preview, $targetId);
                                    } catch (InvalidPriceListAssignmentException|InvalidCustomerBatchException $exception) {
                                        return $exception->getMessage();
                                    }
                                })
                                ->visible(fn (Get $get): bool => filled($get('target_price_list_id'))),
                        ])
                        ->action(function (Collection $records, array $data, Pages\ListCustomers $livewire): void {
                            $workspaceId = app(WorkspaceContext::class)->id();
                            $service = CustomerPriceListUi::assignmentService();
                            $targetId = $service->resolveTargetFromSentinel($data['target_price_list_id'] ?? null);
                            $customerIds = $records->pluck('id')->all();

                            try {
                                $result = $service->apply($workspaceId, $customerIds, $targetId);
                            } catch (InvalidPriceListAssignmentException|InvalidCustomerBatchException $exception) {
                                Notification::make()
                                    ->danger()
                                    ->title('Не вдалося призначити прайс-лист')
                                    ->body($exception->getMessage())
                                    ->send();

                                throw new Halt;
                            }

                            Notification::make()
                                ->success()
                                ->title('Прайс-лист призначено')
                                ->body(CustomerPriceListUi::formatResultNotification($result, $targetId))
                                ->send();

                            $livewire->deselectAllTableRecords();
                        })
                        ->deselectRecordsAfterCompletion(false),
                ]),
            ]);
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
            'index' => Pages\ListCustomers::route('/'),
            'view' => Pages\ViewCustomer::route('/{record}'),
            'edit' => Pages\EditCustomer::route('/{record}/edit'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
