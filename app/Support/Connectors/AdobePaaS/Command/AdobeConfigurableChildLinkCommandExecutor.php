<?php

namespace App\Support\Connectors\AdobePaaS\Command;

use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContextFactory;

final class AdobeConfigurableChildLinkCommandExecutor
{
    public function __construct(
        private readonly AdobePaaSRequestContextFactory $contextFactory,
        private readonly AdobeProductRemoteStateClient $remoteStateClient,
        private readonly AdobeConfigurableRemoteOptionStateReader $optionStateReader,
    ) {}

    public function execute(
        AdobeConfigurableCommandInput $input,
        AdobeConfigurableChildLinkDesiredState $desiredLink,
    ): AdobeConfigurableCommandEvidence {
        $context = $this->contextFactory->create($input->workspaceId, $input->connectorAccountId);
        $parentSku = $input->desiredState->parentSku;

        [$childrenGetResult] = $this->remoteStateClient->getConfigurableChildren($context, $parentSku);
        $childSkus = $this->optionStateReader->readChildSkus($childrenGetResult);

        if ($childSkus === null) {
            return $this->unknownOrAmbiguous('configurable_children_get_untrusted', $parentSku, $desiredLink);
        }

        if (in_array($desiredLink->childSku, $childSkus, true)) {
            return $this->knownApplied('configurable_child_link_no_op', $parentSku, $desiredLink);
        }

        if (! $this->permitsConsequentialWrite($input)) {
            return $this->knownNotApplied('writer_lease_expired_before_consequential_write', $parentSku, $desiredLink);
        }

        [$postResult, $postTransportException] = $this->remoteStateClient->postConfigurableChildLink(
            $context,
            $parentSku,
            $desiredLink->childSku,
        );

        [$reconciliationGetResult] = $this->remoteStateClient->getConfigurableChildren($context, $parentSku);
        $reconciledChildSkus = $this->optionStateReader->readChildSkus($reconciliationGetResult);

        if ($reconciledChildSkus === null) {
            return $this->unknownOrAmbiguous(
                'configurable_child_link_reconciliation_inconclusive',
                $parentSku,
                $desiredLink,
                consequentialWriteAttempts: 1,
                reconciliationGetAttempts: 1,
            );
        }

        if (! in_array($desiredLink->childSku, $reconciledChildSkus, true)) {
            return $this->unknownOrAmbiguous(
                'configurable_child_link_reconciliation_missing',
                $parentSku,
                $desiredLink,
                consequentialWriteAttempts: 1,
                reconciliationGetAttempts: 1,
            );
        }

        return $this->knownApplied(
            'configurable_child_link_reconciled',
            $parentSku,
            $desiredLink,
            consequentialWriteAttempts: 1,
            reconciliationGetAttempts: 1,
        );
    }

    private function permitsConsequentialWrite(AdobeConfigurableCommandInput $input): bool
    {
        if ($input->consequentialWriteGate === null) {
            return true;
        }

        return $input->consequentialWriteGate->permitsConsequentialWrite();
    }

    private function knownNotApplied(
        string $reasonCode,
        string $parentSku,
        AdobeConfigurableChildLinkDesiredState $desiredLink,
    ): AdobeConfigurableCommandEvidence {
        return new AdobeConfigurableCommandEvidence(
            commandKind: 'child_link',
            appliedStateKnowledge: AdobeProductAppliedStateKnowledge::KnownNotApplied,
            reasonCode: $reasonCode,
            subjectSku: $desiredLink->childSku,
            variantId: $desiredLink->variantId,
        );
    }

    private function knownApplied(
        string $reasonCode,
        string $parentSku,
        AdobeConfigurableChildLinkDesiredState $desiredLink,
        int $consequentialWriteAttempts = 0,
        int $reconciliationGetAttempts = 0,
    ): AdobeConfigurableCommandEvidence {
        return new AdobeConfigurableCommandEvidence(
            commandKind: 'child_link',
            appliedStateKnowledge: AdobeProductAppliedStateKnowledge::KnownApplied,
            reasonCode: $reasonCode,
            subjectSku: $desiredLink->childSku,
            variantId: $desiredLink->variantId,
            consequentialWriteAttempts: $consequentialWriteAttempts,
            reconciliationGetAttempts: $reconciliationGetAttempts,
        );
    }

    private function unknownOrAmbiguous(
        string $reasonCode,
        string $parentSku,
        AdobeConfigurableChildLinkDesiredState $desiredLink,
        int $consequentialWriteAttempts = 0,
        int $reconciliationGetAttempts = 0,
    ): AdobeConfigurableCommandEvidence {
        return new AdobeConfigurableCommandEvidence(
            commandKind: 'child_link',
            appliedStateKnowledge: AdobeProductAppliedStateKnowledge::UnknownOrAmbiguous,
            reasonCode: $reasonCode,
            subjectSku: $desiredLink->childSku,
            variantId: $desiredLink->variantId,
            consequentialWriteAttempts: $consequentialWriteAttempts,
            reconciliationGetAttempts: $reconciliationGetAttempts,
        );
    }
}
