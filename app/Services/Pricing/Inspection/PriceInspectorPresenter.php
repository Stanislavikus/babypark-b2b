<?php

namespace App\Services\Pricing\Inspection;

use App\Enums\PriceDisplayContext;
use App\Models\PriceList;
use App\Services\Pricing\PriceDisplayModeResolver;
use App\Services\Pricing\PriceDisplayPresenter;
use App\Services\Pricing\Resolution\PriceResolutionReason;
use App\Services\Pricing\Resolution\PriceResolutionResult;
use App\Services\Pricing\Resolution\PriceResolutionSource;
use App\Services\Pricing\Resolution\PriceResolutionStatus;
use App\Services\Pricing\Resolution\PriceResolutionStep;
use App\Services\Pricing\Resolution\PriceResolutionStepStatus;
use App\Services\Pricing\Resolution\PriceResolutionTracePresenter;
use App\Support\Workspace\WorkspaceContext;
use Carbon\CarbonImmutable;

final class PriceInspectorPresenter
{
    public function __construct(
        private readonly PriceResolutionTracePresenter $tracePresenter,
        private readonly PriceInspectorActionResolver $actionResolver,
    ) {}

    public function present(
        PriceResolutionResult $result,
        PriceInspectorContext $context,
    ): PriceInspectorPresentation {
        $steps = $this->buildSteps($result, $context);

        if ($result->status === PriceResolutionStatus::Resolved) {
            $steps = array_values(array_filter(
                $steps,
                fn (PriceInspectorSourceStep $step) => $step->stepStatus !== PriceResolutionStepStatus::NotChecked,
            ));
        }

        $recommendedActions = $this->aggregateRecommendedActions($steps);

        if (
            $result->status === PriceResolutionStatus::ConfigurationError
            && $result->failure?->reason === PriceResolutionReason::DefaultPriceListMisconfigured
            && $recommendedActions === []
        ) {
            $configAction = $this->actionResolver->forStep(
                new PriceResolutionStep(
                    source: PriceResolutionSource::WorkspaceDefaultPriceList,
                    status: PriceResolutionStepStatus::Failed,
                    reason: PriceResolutionReason::DefaultPriceListMisconfigured,
                ),
                $context,
            );

            if ($configAction !== null) {
                $recommendedActions = [$configAction];
            }
        }

        return new PriceInspectorPresentation(
            headline: $this->headline($result->status),
            tone: $this->tone($result->status),
            priceSummary: $this->priceSummary($result),
            summary: $this->summary($result->status),
            sourceSteps: $steps,
            recommendedActions: $recommendedActions,
            technicalDetails: $this->technicalDetails($result, $context),
        );
    }

    private function headline(PriceResolutionStatus $status): string
    {
        return match ($status) {
            PriceResolutionStatus::Resolved => __('price_inspector.headline.resolved'),
            PriceResolutionStatus::Unavailable => __('price_inspector.headline.unavailable'),
            PriceResolutionStatus::ConfigurationError => __('price_inspector.headline.configuration_error'),
        };
    }

    private function tone(PriceResolutionStatus $status): PriceInspectorTone
    {
        return match ($status) {
            PriceResolutionStatus::Resolved => PriceInspectorTone::Success,
            PriceResolutionStatus::Unavailable => PriceInspectorTone::Warning,
            PriceResolutionStatus::ConfigurationError => PriceInspectorTone::Critical,
        };
    }

    private function summary(PriceResolutionStatus $status): string
    {
        return match ($status) {
            PriceResolutionStatus::Resolved => __('price_inspector.outcome.resolved'),
            PriceResolutionStatus::Unavailable => __('price_inspector.outcome.unavailable'),
            PriceResolutionStatus::ConfigurationError => __('price_inspector.outcome.configuration_error'),
        };
    }

    private function priceSummary(PriceResolutionResult $result): ?string
    {
        if ($result->price === null) {
            return null;
        }

        $workspace = app(WorkspaceContext::class)->current();
        $mode = app(PriceDisplayModeResolver::class)->resolve($workspace, PriceDisplayContext::Internal);
        $presentation = app(PriceDisplayPresenter::class)->present($result->price, $mode);

        return $presentation->decisionPathLabel !== ''
            ? $presentation->decisionPathLabel
            : $presentation->fullLabel();
    }

    /**
     * @return list<PriceInspectorSourceStep>
     */
    private function buildSteps(
        PriceResolutionResult $result,
        PriceInspectorContext $context,
    ): array {
        $steps = [];

        foreach ($result->trace->steps as $step) {
            $steps[] = new PriceInspectorSourceStep(
                sourceLabel: $this->sourceLabel($step->source),
                sourceName: $this->sourceName($step),
                outcomeLabel: $this->outcomeLabel($step),
                explanation: $this->explanation($step, $context),
                action: $this->actionResolver->forStep($step, $context),
                stepStatus: $step->status,
            );
        }

        if (
            $steps === []
            && $result->status === PriceResolutionStatus::ConfigurationError
            && $result->failure?->reason === PriceResolutionReason::DefaultPriceListMisconfigured
        ) {
            $syntheticStep = new PriceResolutionStep(
                source: PriceResolutionSource::WorkspaceDefaultPriceList,
                status: PriceResolutionStepStatus::Failed,
                reason: PriceResolutionReason::DefaultPriceListMisconfigured,
            );

            $steps[] = new PriceInspectorSourceStep(
                sourceLabel: $this->sourceLabel($syntheticStep->source),
                sourceName: null,
                outcomeLabel: $this->outcomeLabel($syntheticStep),
                explanation: __('price_inspector.explanation.workspace_default_price_list.default_price_list_misconfigured'),
                action: $this->actionResolver->forStep($syntheticStep, $context),
                stepStatus: $syntheticStep->status,
            );
        }

        return $steps;
    }

    private function sourceLabel(PriceResolutionSource $source): string
    {
        return __('price_inspector.source.'.$source->value);
    }

    private function sourceName(PriceResolutionStep $step): ?string
    {
        if ($step->priceListId === null) {
            return null;
        }

        $workspaceId = app(WorkspaceContext::class)->id();

        return PriceList::query()
            ->where('workspace_id', $workspaceId)
            ->where('id', $step->priceListId)
            ->value('name');
    }

    private function outcomeLabel(PriceResolutionStep $step): string
    {
        if (
            $step->status === PriceResolutionStepStatus::NotChecked
            && $step->reason === PriceResolutionReason::PreviousSourceResolved
        ) {
            return __('price_inspector.step_outcome.not_checked_resolved');
        }

        if ($step->status === PriceResolutionStepStatus::Matched) {
            return __('price_inspector.step_outcome.used');
        }

        return __('price_inspector.step_outcome.not_used');
    }

    private function explanation(PriceResolutionStep $step, PriceInspectorContext $context): string
    {
        $key = 'price_inspector.explanation.'.$step->source->value.'.'.$step->reason->value;

        if (! trans()->has($key)) {
            return $this->tracePresenter->reasonLabel($step->reason);
        }

        return __($key, $this->explanationReplacements($step, $context));
    }

    /**
     * @return array<string, string|int>
     */
    private function explanationReplacements(PriceResolutionStep $step, PriceInspectorContext $context): array
    {
        $timezone = config('app.timezone', 'UTC');
        $currency = $step->currency ?? 'UAH';
        $currencySymbol = $currency === 'UAH' ? '₴' : $currency;

        $amount = $step->amount !== null
            ? number_format($step->amount, 2, ',', ' ').' '.$currencySymbol
            : '—';

        return [
            'sku' => $context->variant->sku,
            'name' => $this->sourceName($step) ?? '—',
            'quantity' => (int) ($step->metadata['quantity_min'] ?? $context->quantity),
            'status' => (string) ($step->metadata['status'] ?? '—'),
            'date' => $this->formatMetadataDate($step->metadata['valid_until'] ?? $step->metadata['valid_from'] ?? null, $timezone),
            'amount' => $amount,
        ];
    }

    private function formatMetadataDate(?string $isoDate, string $timezone): string
    {
        if ($isoDate === null) {
            return '—';
        }

        return CarbonImmutable::parse($isoDate)
            ->timezone($timezone)
            ->locale('uk')
            ->isoFormat('D MMMM YYYY');
    }

    /**
     * @param  list<PriceInspectorSourceStep>  $steps
     * @return list<PriceInspectorAction>
     */
    private function aggregateRecommendedActions(array $steps): array
    {
        $actions = [];
        $seen = [];

        foreach ($steps as $step) {
            if ($step->action === null) {
                continue;
            }

            $key = $step->action->deduplicationKey;

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $actions[] = $step->action;
        }

        return $actions;
    }

    /**
     * @return array<string, mixed>
     */
    private function technicalDetails(
        PriceResolutionResult $result,
        PriceInspectorContext $context,
    ): array {
        return [
            'status' => $result->status->value,
            'reason_codes' => array_map(
                fn (PriceResolutionReason $reason) => $reason->value,
                $result->reasonCodes,
            ),
            'reason_labels' => array_map(
                fn (PriceResolutionReason $reason) => __('price_inspector.reason.'.$reason->value),
                $result->reasonCodes,
            ),
            'price' => $result->price !== null ? [
                'effective_net' => $result->price->effectiveNetPrice,
                'gross' => $result->price->grossPrice,
                'currency' => $result->price->currency,
                'source' => $result->price->source,
                'vat_rate' => $result->price->vatRate,
            ] : null,
            'failure' => $result->failure !== null ? [
                'reason' => $result->failure->reason->value,
                'reason_label' => __('price_inspector.reason.'.$result->failure->reason->value),
                'message' => $result->failure->message,
                'context' => $result->failure->context,
            ] : null,
            'trace' => array_map(fn (PriceResolutionStep $step) => [
                'source' => $step->source->value,
                'source_label' => $this->tracePresenter->sourceLabel($step->source),
                'status' => $step->status->value,
                'status_label' => $this->tracePresenter->stepStatusLabel($step->status),
                'reason' => $step->reason->value,
                'reason_label' => $this->tracePresenter->reasonLabel($step->reason),
                'price_list_id' => $step->priceListId,
                'price_list_item_id' => $step->priceListItemId,
                'amount' => $step->amount,
                'currency' => $step->currency,
                'metadata' => $step->metadata,
            ], $result->trace->steps),
            'context' => [
                'customer_id' => $context->customer->id,
                'customer_name' => $context->customer->name,
                'variant_id' => $context->variant->id,
                'variant_sku' => $context->variant->sku,
                'product_id' => $context->variant->product_id,
                'quantity' => $context->quantity,
                'effective_at' => $context->effectiveAt->utc()->toIso8601String(),
                'timezone' => config('app.timezone', 'UTC'),
            ],
        ];
    }
}
