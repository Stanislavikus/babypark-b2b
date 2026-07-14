<?php

namespace App\Filament\Pages;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Pricing\Inspection\PriceInspectorContext;
use App\Services\Pricing\Inspection\PriceInspectorPresentation;
use App\Services\Pricing\Inspection\PriceInspectorPresenter;
use App\Services\Pricing\PriceResolver;
use App\Services\Pricing\Resolution\PriceResolutionTracePresenter;
use App\Support\Workspace\WorkspaceContext;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
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

    protected static ?string $navigationLabel = null;

    protected static ?string $title = null;

    protected static ?int $navigationSort = 5;

    protected static string $view = 'filament.pages.price-inspector';

    public ?array $data = [];

    public ?string $resultStatus = null;

    public ?string $presentedOutput = null;

    /** @var array<string, mixed>|null */
    public ?array $presentation = null;

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

    public static function getNavigationLabel(): string
    {
        return __('price_inspector.page.navigation');
    }

    public function getTitle(): string
    {
        return __('price_inspector.page.title');
    }

    public function getSubheading(): ?string
    {
        return __('price_inspector.page.subheading');
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
        $timezone = config('app.timezone', 'UTC');

        return $form
            ->schema([
                Section::make(__('price_inspector.form.parameters'))->schema([
                    Select::make('customer_id')
                        ->label(__('price_inspector.form.customer'))
                        ->required()
                        ->searchable()
                        ->options(fn (): array => Customer::query()
                            ->where('workspace_id', $workspaceId)
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all()),
                    Select::make('product_id')
                        ->label(__('price_inspector.form.product_filter'))
                        ->searchable()
                        ->live()
                        ->options(fn (): array => Product::query()
                            ->where('workspace_id', $workspaceId)
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all()),
                    Select::make('variant_id')
                        ->label(__('price_inspector.form.variant'))
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
                        ->label(__('price_inspector.form.quantity'))
                        ->numeric()
                        ->integer()
                        ->minValue(1)
                        ->required()
                        ->default(1),
                    DateTimePicker::make('effective_at')
                        ->label(__('price_inspector.form.effective_at'))
                        ->seconds(true)
                        ->default(now())
                        ->helperText(__('price_inspector.form.timezone_hint', ['timezone' => $timezone])),
                    Placeholder::make('timezone_hint')
                        ->label(__('price_inspector.form.timezone'))
                        ->content($timezone),
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

        $user = Auth::user();
        $context = new PriceInspectorContext(
            customer: $customer,
            variant: $variant,
            quantity: (int) $data['quantity'],
            effectiveAt: $effectiveAt,
            user: $user instanceof User ? $user : null,
        );

        $presentation = app(PriceInspectorPresenter::class)->present($result, $context);

        $this->resultStatus = $result->status->value;
        $this->presentedOutput = app(PriceResolutionTracePresenter::class)->present($result);
        $this->presentation = $this->serializePresentation($presentation);

        Notification::make()
            ->title(__('price_inspector.form.price_checked'))
            ->success()
            ->send();
    }

    /**
     * @return array<string, mixed>
     */
    private function serializePresentation(PriceInspectorPresentation $presentation): array
    {
        return [
            'headline' => $presentation->headline,
            'tone' => $presentation->tone->value,
            'price_summary' => $presentation->priceSummary,
            'summary' => $presentation->summary,
            'source_steps' => array_map(fn ($step) => [
                'source_label' => $step->sourceLabel,
                'source_name' => $step->sourceName,
                'outcome_label' => $step->outcomeLabel,
                'explanation' => $step->explanation,
                'action' => $step->action !== null ? [
                    'label' => $step->action->label,
                    'url' => $step->action->url,
                ] : null,
            ], $presentation->sourceSteps),
            'recommended_actions' => array_map(fn ($action) => [
                'label' => $action->label,
                'url' => $action->url,
            ], $presentation->recommendedActions),
            'technical_details' => $presentation->technicalDetails,
        ];
    }

    protected function getFormActions(): array
    {
        return [];
    }
}
