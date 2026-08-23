<?php

namespace App\Services\Sync\EntityTrust;

use App\Enums\EntityTrust\EntityTrustReadinessStatus;
use App\Models\ConnectorAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Sync\EntityTrust\EntityTrustLinkReadinessItem;

/**
 * Thin presentation seam around AdobeProductEntityTrustLinkReadinessProjector.
 *
 * Provides the merchant-facing per-Product linking/remediation working set
 * for the R2b-2 Live area. No new domain truth is added; this is purely a
 * safe, UI-friendly representation of what the projector already returns.
 */
final class EntityTrustMerchantReadService
{
    public function __construct(
        private readonly AdobeProductEntityTrustLinkReadinessProjector $projector,
    ) {}

    /**
     * @return list<array{
     *     product_id: string,
     *     productName: string,
     *     primary_sku: ?string,
     *     readiness: EntityTrustReadinessStatus,
     *     readiness_value: string,
     *     is_configurable_family: bool,
     *     ready_label: string,
     *     ready_explanation: string,
     *     available_action: string,
     * }>
     */
    public function workingSet(User $actor, Workspace $workspace, ConnectorAccount $account): array
    {
        $items = $this->projector->projectForAccount($actor, $workspace, $account->id);

        $presenter = app(EntityTrustFailureReasonPresenter::class);

        $rows = [];

        foreach ($items as $item) {
            $rows[] = $this->present($item, $presenter);
        }

        return $rows;
    }

    /**
     * @return array{
     *     product_id: string,
     *     productName: string,
     *     primary_sku: ?string,
     *     readiness: EntityTrustReadinessStatus,
     *     readiness_value: string,
     *     is_configurable_family: bool,
     *     ready_label: string,
     *     ready_explanation: string,
     *     available_action: string,
     * }
     */
    public function present(
        EntityTrustLinkReadinessItem $item,
        EntityTrustFailureReasonPresenter $presenter,
    ): array {
        [$labelKey, $explanationKey, $action] = $this->copyForReadiness($item->status);

        return [
            'product_id' => $item->productId,
            'productName' => $item->productName,
            'primary_sku' => $item->primarySku,
            'readiness' => $item->status,
            'readiness_value' => $item->status->value,
            'is_configurable_family' => $item->isConfigurableFamily,
            'ready_label' => $labelKey,
            'ready_explanation' => $explanationKey,
            'available_action' => $action,
        ];
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function copyForReadiness(EntityTrustReadinessStatus $status): array
    {
        return match ($status) {
            EntityTrustReadinessStatus::InitialLinkRequired => [
                'entity_trust.readiness.initial_link_required.label',
                'entity_trust.readiness.initial_link_required.explanation',
                'review',
            ],
            EntityTrustReadinessStatus::ReconfirmationRequired => [
                'entity_trust.readiness.reconfirmation_required.label',
                'entity_trust.readiness.reconfirmation_required.explanation',
                'review',
            ],
            EntityTrustReadinessStatus::RelinkReviewRequired => [
                'entity_trust.readiness.relink_review_required.label',
                'entity_trust.readiness.relink_review_required.explanation',
                'relink',
            ],
            EntityTrustReadinessStatus::AlreadyConfirmed => [
                'entity_trust.readiness.already_confirmed.label',
                'entity_trust.readiness.already_confirmed.explanation',
                'none',
            ],
            EntityTrustReadinessStatus::NoAction => [
                'entity_trust.readiness.no_action.label',
                'entity_trust.readiness.no_action.explanation',
                'none',
            ],
        };
    }
}
