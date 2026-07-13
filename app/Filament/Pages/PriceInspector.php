<?php

namespace App\Filament\Pages;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Pricing\PriceResolver;
use App\Services\Pricing\Resolution\PriceResolutionTracePresenter;
use App\Support\Workspace\WorkspaceContext;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

class PriceInspector extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-magnifying-glass';

    protected static ?string $navigationGroup = 'B2B';

    protected static ?string $navigationLabel = 'Інспектор цін';

    protected static ?string $title = 'Інспектор цін';

    protected static ?int $navigationSort = 5;

    protected static string $view = 'filament.pages.price-inspector';

    public ?array $data = [];

    public ?string $resultStatus = null;

    public ?string $presentedOutput = null;

    /** @var array<string, mixed>|null */
    public ?array $resultSummary = null;

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User
            && $user->is_active
            && in_array($user->role, [
                UserRole::Admin,
                UserRole::Manager,
                UserRole::Director,
                UserRole::Programmer,
            ], true);
    }

    public function mount(): void
    {
        $prefill = [
            'quantity' => max(1, (int) request()->query('quantity', 1)),
            'effective_at' => request()->query('effective_at'),
            'customer_id' => request()->query('customer_id'),
            'variant_id' => request()->query('variant_id'),
            'product_id' => request()->query('product_id'),
        ];

        if ($prefill['effective_at'] !== null) {
            $prefill['effective_at'] = CarbonImmutable::parse($prefill['effective_at'])->format('Y-m-d H:i:s');
        } else {
            $prefill['effective_at'] = now()->toDateTimeString();
        }

        $this->form->fill($prefill);

        if ($prefill['customer_id'] && $prefill['variant_id']) {
            $this->resolvePrice();
        }
    }

    public function form(Form $form): Form
    {
        $workspaceId = app(WorkspaceContext::class)->id();

        return $form
            ->schema([
                Section::make('Параметри перевірки')->schema([
                    Select::make('customer_id')
                        ->label('Клієнт')
                        ->required()
                        ->searchable()
                        ->options(fn (): array => Customer::query()
                            ->where('workspace_id', $workspaceId)
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all()),
                    Select::make('product_id')
                        ->label('Товар (фільтр)')
                        ->searchable()
                        ->live()
                        ->options(fn (): array => Product::query()
                            ->where('workspace_id', $workspaceId)
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all()),
                    Select::make('variant_id')
                        ->label('Варіант')
                        ->required()
                        ->searchable()
                        ->options(function (Get $get) use ($workspaceId): array {
                            $query = ProductVariant::query()
                                ->where('workspace_id', $workspaceId)
                                ->with('product')
                                ->orderBy('sku');

                            if ($productId = $get('product_id')) {
                                $query->where('product_id', $productId);
                            }

                            return $query->get()
                                ->mapWithKeys(fn (ProductVariant $variant) => [
                                    $variant->id => $variant->sku.' — '.$variant->product->name,
                                ])
                                ->all();
                        }),
                    TextInput::make('quantity')
                        ->label('Кількість')
                        ->numeric()
                        ->integer()
                        ->minValue(1)
                        ->required()
                        ->default(1),
                    DateTimePicker::make('effective_at')
                        ->label('Дата/час дії ціни')
                        ->seconds(true)
                        ->default(now()),
                ])->columns(2),
            ])
            ->statePath('data');
    }

    public function resolvePrice(): void
    {
        $data = $this->form->getState();
        $workspaceId = app(WorkspaceContext::class)->id();

        $customer = Customer::query()
            ->where('workspace_id', $workspaceId)
            ->findOrFail($data['customer_id']);

        $variant = ProductVariant::query()
            ->where('workspace_id', $workspaceId)
            ->findOrFail($data['variant_id']);

        $effectiveAt = isset($data['effective_at']) && $data['effective_at'] !== null
            ? CarbonImmutable::parse($data['effective_at'])
            : CarbonImmutable::now();

        $result = app(PriceResolver::class)->resolveWithTrace(
            variant: $variant,
            customer: $customer,
            quantity: (int) $data['quantity'],
            effectiveAt: $effectiveAt,
        );

        $this->resultStatus = $result->status->value;
        $this->presentedOutput = app(PriceResolutionTracePresenter::class)->present($result);
        $this->resultSummary = [
            'status' => $result->status->value,
            'reason_codes' => array_map(fn ($r) => $r->value, $result->reasonCodes),
            'price' => $result->price !== null ? [
                'effective_net' => $result->price->effectiveNetPrice,
                'gross' => $result->price->grossPrice,
                'currency' => $result->price->currency,
                'source' => $result->price->source,
                'vat_rate' => $result->price->vatRate,
            ] : null,
            'failure' => $result->failure !== null ? [
                'reason' => $result->failure->reason->value,
                'message' => $result->failure->message,
                'context' => $result->failure->context,
            ] : null,
            'trace' => array_map(fn ($step) => [
                'source' => $step->source->value,
                'status' => $step->status->value,
                'reason' => $step->reason->value,
                'price_list_id' => $step->priceListId,
                'price_list_item_id' => $step->priceListItemId,
                'amount' => $step->amount,
                'currency' => $step->currency,
                'metadata' => $step->metadata,
            ], $result->trace->steps),
        ];

        Notification::make()
            ->title('Ціну перевірено')
            ->success()
            ->send();
    }

    protected function getFormActions(): array
    {
        return [];
    }
}
