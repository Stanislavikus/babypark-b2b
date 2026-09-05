<?php

namespace App\Support\Connectors\AdobePaaS\Command;

final class AdobeConfigurableParentCommandExecutor
{
    public function __construct(
        private readonly AdobeProductExternalRecordLinkGuard $linkGuard,
    ) {}

    public function execute(AdobeConfigurableCommandInput $input): AdobeConfigurableCommandEvidence
    {
        $desiredState = $input->desiredState->parent;

        if ($this->linkGuard->hasParentSkuCrossSubjectCollision(
            $input->workspaceId,
            $input->connectorAccountId,
            $desiredState->sku,
            $desiredState->productId,
        )) {
            return $this->knownNotApplied('external_record_link_collision', $desiredState->sku);
        }

        $trustedLookup = $this->linkGuard->resolveTrustedParentLinkBySubject(
            $input->workspaceId,
            $input->connectorAccountId,
            $desiredState->productId,
        );

        if ($trustedLookup->isAmbiguous()) {
            return $this->unknownOrAmbiguous('ambiguous_parent_identity_links', $desiredState->sku);
        }

        if ($trustedLookup->isTrusted()) {
            return $this->knownNotApplied(
                'entity_bound_mutation_bridge_required',
                $trustedLookup->link?->external_identifier ?? $desiredState->sku,
            );
        }

        return $this->knownNotApplied('link_required', $desiredState->sku);
    }

    private function knownNotApplied(string $reasonCode, ?string $subjectSku = null): AdobeConfigurableCommandEvidence
    {
        return new AdobeConfigurableCommandEvidence(
            commandKind: 'configurable_parent',
            appliedStateKnowledge: AdobeProductAppliedStateKnowledge::KnownNotApplied,
            reasonCode: $reasonCode,
            subjectSku: $subjectSku,
        );
    }

    private function unknownOrAmbiguous(
        string $reasonCode,
        ?string $subjectSku = null,
        int $consequentialWriteAttempts = 0,
        int $reconciliationGetAttempts = 0,
    ): AdobeConfigurableCommandEvidence {
        return new AdobeConfigurableCommandEvidence(
            commandKind: 'configurable_parent',
            appliedStateKnowledge: AdobeProductAppliedStateKnowledge::UnknownOrAmbiguous,
            reasonCode: $reasonCode,
            subjectSku: $subjectSku,
            consequentialWriteAttempts: $consequentialWriteAttempts,
            reconciliationGetAttempts: $reconciliationGetAttempts,
        );
    }
}
