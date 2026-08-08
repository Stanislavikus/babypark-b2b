<?php

namespace App\Filament\Resources;

use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;
use Closure;
use Filament\Forms\Components\Placeholder;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\IconEntry;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Actions\ViewAction;
use Filament\Actions\EditAction;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\BulkAction;
use App\Filament\Resources\CustomerResource\Pages\ListCustomers;
use App\Filament\Resources\CustomerResource\RelationManagers\OrdersRelationManager;
use App\Filament\Resources\CustomerResource\Pages\ViewCustomer;
use App\Filament\Resources\CustomerResource\Pages\EditCustomer;
use App\Filament\Resources\CustomerResource\Pages\PreviewAsCustomer;
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
use Filament\Infolists;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Exceptions\Halt;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

use Illuminate\Auth\Access\Response;

class CustomerResource extends Resource
{
    protected static ?string $model = Customer::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-building-office-2';

    protected static string | \UnitEnum | null $navigationGroup = 'B2B';

    protected static ?string $modelLabel = 'клієнт';

    protected static ?string $pluralModelLabel = 'Клієнти';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Основне')->schema([
                    TextInput::make('name')
                        ->label('Назва')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('short_name')
                        ->label('Коротка назва')
                        ->maxLength(255),
                    TextInput::make('login')
                        ->label('Логін')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('password')
                        ->label('Пароль')
                        ->password()
                        ->dehydrated(fn (?string $state): bool => filled($state))
                        ->required(fn (string $operation): bool => $operation === 'create')
                        ->maxLength(255),
                    Toggle::make('is_active')
                        ->label('Активний')
                        ->default(true),
                ])->columns(2),
                Section::make('Реквізити')->schema([
                    TextInput::make('edrpou')
                        ->label('ЄДРПОУ')
                        ->maxLength(20),
                    TextInput::make('ipn')
                        ->label('ІПН')
                        ->maxLength(20),
                    TextInput::make('manager_name')
                        ->label('Менеджер (текст)')
                        ->maxLength(255),
                    TextInput::make('manager_phone')
                        ->label('Телефон менеджера (текст)')
                        ->tel()
                        ->maxLength(255),
                    TextInput::make('email')
                        ->label('Email')
                        ->email()
                        ->maxLength(255),
                    Select::make('account_manager_id')
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
                    Select::make('backup_manager_id')
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
                Section::make('Кредит')->schema([
                    TextInput::make('payment_delay_days')
                        ->label('Відстрочка (днів)')
                        ->numeric()
                        ->default(0),
                    TextInput::make('credit_limit')
                        ->label('Кредитний ліміт')
                        ->numeric()
                        ->prefix('₴'),
                    TextInput::make('current_debt')
                        ->label('Поточний борг')
                        ->numeric()
                        ->prefix('₴'),
                ])->columns(3),
                Section::make('Ціни')->schema([
                    Select::make('default_price_list_id')
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
                            fn (Customer $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
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
                    Placeholder::make('price_list_warning')
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

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Клієнт')->schema([
                    TextEntry::make('name')->label('Назва'),
                    TextEntry::make('login')->label('Логін'),
                    IconEntry::make('is_active')->label('Активний')->boolean(),
                    TextEntry::make('manager_name')->label('Менеджер (текст)'),
                    TextEntry::make('manager_phone')->label('Телефон (текст)'),
                    TextEntry::make('email')->label('Email'),
                    TextEntry::make('accountManager.name')
                        ->label('Акаунт менеджер')
                        ->placeholder('—'),
                    TextEntry::make('backupManager.name')
                        ->label('Резервний менеджер')
                        ->placeholder('—'),
                ])->columns(2),
                Section::make('Кредит')->schema([
                    TextEntry::make('credit_limit')
                        ->label('Кредитний ліміт')
                        ->money('UAH'),
                    TextEntry::make('current_debt')
                        ->label('Поточний борг')
                        ->money('UAH'),
                    TextEntry::make('payment_delay_days')
                        ->label('Відстрочка')
                        ->suffix(' дн.'),
                    TextEntry::make('available_credit')
                        ->label('Доступний кредит')
                        ->state(fn (Customer $record): float => max(0, (float) $record->credit_limit - (float) $record->current_debt))
                        ->money('UAH'),
                ])->columns(2),
                Section::make('Ціни')->schema([
                    TextEntry::make('price_list_assignment')
                        ->label('Прайс-лист')
                        ->state(fn (Customer $record): string => CustomerPriceListUi::resolveDisplay($record)->infolistLabel()),
                    TextEntry::make('price_list_assignment_description')
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
                TextColumn::make('name')
                    ->label('Назва')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('login')
                    ->label('Логін')
                    ->searchable(),
                TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->copyable()
                    ->placeholder('—'),
                TextColumn::make('credit_limit')
                    ->label('Ліміт')
                    ->money('UAH')
                    ->sortable(),
                TextColumn::make('current_debt')
                    ->label('Борг')
                    ->money('UAH')
                    ->sortable(),
                TextColumn::make('payment_delay_days')
                    ->label('Відстрочка')
                    ->suffix(' дн.')
                    ->sortable(),
                TextColumn::make('default_price_list_id')
                    ->label('Прайс-лист')
                    ->state(fn (Customer $record): string => CustomerPriceListUi::resolveDisplay($record)->tableLabel())
                    ->description(fn (Customer $record): ?string => CustomerPriceListUi::resolveDisplay($record)->tableDescription())
                    ->color(fn (Customer $record): string => match (CustomerPriceListUi::resolveDisplay($record)->state) {
                        CustomerPriceListAssignmentDisplayState::InactiveHistorical,
                        CustomerPriceListAssignmentDisplayState::RedundantDirect => 'warning',
                        default => 'gray',
                    }),
                IconColumn::make('is_active')
                    ->label('Активний')
                    ->boolean(),
                TextColumn::make('orders_count')
                    ->label('Замовлень')
                    ->counts('orders')
                    ->sortable(),
            ])
            ->defaultSort('name')
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Активний'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                Action::make('preview_as_customer')
                    ->label('Перегляд як клієнт')
                    ->icon('heroicon-o-eye')
                    ->url(fn (Customer $record): string => static::getUrl('preview', ['record' => $record])),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('assign_price_list')
                        ->label('Призначити прайс-лист')
                        ->icon('heroicon-o-currency-dollar')
                        ->schema([
                            Select::make('target_price_list_id')
                                ->label('Прайс-лист')
                                ->options(fn (): array => CustomerPriceListUi::assignmentService()
                                    ->bulkSelectableOptions(app(WorkspaceContext::class)->id()))
                                ->required()
                                ->native(false)
                                ->searchable()
                                ->live(),
                            Placeholder::make('assignment_preview')
                                ->label('Попередній перегляд')
                                ->content(function (Get $get, ListCustomers $livewire): string {
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
                        ->action(function (Collection $records, array $data, ListCustomers $livewire): void {
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
            OrdersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomers::route('/'),
            'view' => ViewCustomer::route('/{record}'),
            'edit' => EditCustomer::route('/{record}/edit'),
            'preview' => PreviewAsCustomer::route('/{record}/preview'),
        ];
    }


    public static function getCreateAuthorizationResponse(): Response
    {
        return Response::deny();
    }
}
