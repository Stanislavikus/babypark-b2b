<?php

namespace App\Support\Sync\EntityTrust;

use App\Enums\EntityTrust\EntityTrustConfirmationMode;

/**
 * Server-side, ephemeral payload binding an opaque reviewFlowId to a reviewed
 * Entity Trust intent and its issued R2b-1 reviewToken.
 *
 * The flow ID is the ONLY thing the merchant UI ever sees. The reviewToken and
 * explicitRelink flag live exclusively in this server-side cache and never enter
 * Livewire public state, dehydrated snapshots, rendered HTML, query strings or
 * logs.
 */
final readonly class EntityTrustReviewFlowPayload
{
    public function __construct(
        public string $actorUserId,
        public string $workspaceId,
        public string $connectorAccountId,
        public string $productId,
        public string $reviewToken,
        public EntityTrustConfirmationMode $mode,
        public bool $isConfigurableFamily,
        public ?string $existingParentSkuHint,
        public bool $explicitRelink,
    ) {}
}
