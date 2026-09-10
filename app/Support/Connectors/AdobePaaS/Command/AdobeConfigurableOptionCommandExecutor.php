<?php

namespace App\Support\Connectors\AdobePaaS\Command;

use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContext;
use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContextFactory;
use App\Support\Connectors\Transport\ConnectorHttpResult;
use App\Support\Connectors\Transport\ConnectorTransportException;

final class AdobeConfigurableOptionCommandExecutor
{
    public function __construct(
        private readonly AdobePaaSRequestContextFactory $contextFactory,
        private readonly AdobeProductRemoteStateClient $remoteStateClient,
        private readonly AdobeConfigurableRemoteOptionStateReader $optionStateReader,
    ) {}

    public function execute(
        AdobeConfigurableCommandInput $input,
        AdobeConfigurableOptionDesiredState $desiredOption,
    ): AdobeConfigurableCommandEvidence {
        $context = $this->contextFactory->create($input->workspaceId, $input->connectorAccountId);
        $parentSku = $input->desiredState->parentSku;

        [$optionsGetResult] = $this->remoteStateClient->getConfigurableOptions($context, $parentSku);
        $remoteOptions = $this->optionStateReader->read($optionsGetResult);

        if ($remoteOptions === null) {
            return $this->unknownOrAmbiguous('configurable_options_get_untrusted', $parentSku, $desiredOption);
        }

        $matchingByAttribute = array_values(array_filter(
            $remoteOptions,
            static fn (AdobeConfigurableRemoteOptionState $option): bool => $option->attributeId === $desiredOption->attributeId,
        ));

        if (count($matchingByAttribute) > 1) {
            return $this->unknownOrAmbiguous('ambiguous_configurable_option_identity', $parentSku, $desiredOption);
        }

        $existing = $matchingByAttribute[0] ?? null;

        if ($existing !== null && $this->controlledStateMatches($desiredOption, $existing)) {
            return $this->knownApplied('configurable_option_no_op', $parentSku, $desiredOption, $existing->optionId);
        }

        if ($existing !== null && $this->requiresDestructiveValueRemoval($desiredOption, $existing)) {
            return $this->knownNotApplied(
                'configurable_option_value_removal_requires_adobe_validation',
                $parentSku,
                $desiredOption,
                $existing->optionId,
            );
        }

        if ($existing === null) {
            if (! $this->permitsConsequentialWrite($input)) {
                return $this->knownNotApplied('writer_lease_expired_before_consequential_write', $parentSku, $desiredOption);
            }

            [$postResult, $postTransportException] = $this->remoteStateClient->postConfigurableOption(
                $context,
                $parentSku,
                $desiredOption,
            );

            return $this->reconcileAfterWrite(
                $input,
                $context,
                $parentSku,
                $desiredOption,
                null,
                $postResult,
                $postTransportException,
                consequentialWriteAttempts: 1,
                reasonCode: 'configurable_option_post',
            );
        }

        if (! $this->permitsConsequentialWrite($input)) {
            return $this->knownNotApplied(
                'writer_lease_expired_before_consequential_write',
                $parentSku,
                $desiredOption,
                $existing->optionId,
            );
        }

        [$putResult, $putTransportException] = $this->remoteStateClient->putConfigurableOption(
            $context,
            $parentSku,
            $existing->optionId,
            $desiredOption,
        );

        return $this->reconcileAfterWrite(
            $input,
            $context,
            $parentSku,
            $desiredOption,
            $existing->optionId,
            $putResult,
            $putTransportException,
            consequentialWriteAttempts: 1,
            reasonCode: 'configurable_option_put',
        );
    }

    private function reconcileAfterWrite(
        AdobeConfigurableCommandInput $input,
        AdobePaaSRequestContext $context,
        string $parentSku,
        AdobeConfigurableOptionDesiredState $desiredOption,
        ?int $knownOptionId,
        ?ConnectorHttpResult $writeResult,
        ?ConnectorTransportException $transportException,
        int $consequentialWriteAttempts,
        string $reasonCode,
    ): AdobeConfigurableCommandEvidence {
        [$optionsGetResult] = $this->remoteStateClient->getConfigurableOptions($context, $parentSku);
        $remoteOptions = $this->optionStateReader->read($optionsGetResult);

        if ($remoteOptions === null) {
            return $this->unknownOrAmbiguous(
                $reasonCode.'_reconciliation_inconclusive',
                $parentSku,
                $desiredOption,
                $knownOptionId,
                consequentialWriteAttempts: $consequentialWriteAttempts,
                reconciliationGetAttempts: 1,
            );
        }

        $matchingByAttribute = array_values(array_filter(
            $remoteOptions,
            static fn (AdobeConfigurableRemoteOptionState $option): bool => $option->attributeId === $desiredOption->attributeId,
        ));

        if (count($matchingByAttribute) !== 1) {
            return $this->unknownOrAmbiguous(
                $reasonCode.'_reconciliation_ambiguous',
                $parentSku,
                $desiredOption,
                $knownOptionId,
                consequentialWriteAttempts: $consequentialWriteAttempts,
                reconciliationGetAttempts: 1,
            );
        }

        $observed = $matchingByAttribute[0];

        if ($this->controlledStateMatches($desiredOption, $observed)) {
            return $this->knownApplied(
                $reasonCode.'_reconciled',
                $parentSku,
                $desiredOption,
                $observed->optionId,
                consequentialWriteAttempts: $consequentialWriteAttempts,
                reconciliationGetAttempts: 1,
            );
        }

        return $this->unknownOrAmbiguous(
            $reasonCode.'_reconciliation_mismatch',
            $parentSku,
            $desiredOption,
            $observed->optionId,
            consequentialWriteAttempts: $consequentialWriteAttempts,
            reconciliationGetAttempts: 1,
        );
    }

    private function controlledStateMatches(
        AdobeConfigurableOptionDesiredState $desired,
        AdobeConfigurableRemoteOptionState $observed,
    ): bool {
        if ($desired->attributeId !== $observed->attributeId) {
            return false;
        }

        if ($desired->label !== $observed->label) {
            return false;
        }

        if ($desired->position !== $observed->position) {
            return false;
        }

        $desiredIndexes = array_map(
            static fn (AdobeConfigurableOptionValueDesiredState $value): int => $value->valueIndex,
            $desired->values,
        );

        sort($desiredIndexes);

        $observedIndexes = $observed->values;
        sort($observedIndexes);

        return $desiredIndexes === $observedIndexes;
    }

    private function requiresDestructiveValueRemoval(
        AdobeConfigurableOptionDesiredState $desired,
        AdobeConfigurableRemoteOptionState $observed,
    ): bool {
        $desiredIndexes = array_map(
            static fn (AdobeConfigurableOptionValueDesiredState $value): int => $value->valueIndex,
            $desired->values,
        );

        foreach ($observed->values as $observedIndex) {
            if (! in_array($observedIndex, $desiredIndexes, true)) {
                return true;
            }
        }

        return false;
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
        AdobeConfigurableOptionDesiredState $desiredOption,
        ?int $optionId = null,
    ): AdobeConfigurableCommandEvidence {
        return new AdobeConfigurableCommandEvidence(
            commandKind: 'configurable_option',
            appliedStateKnowledge: AdobeProductAppliedStateKnowledge::KnownNotApplied,
            reasonCode: $reasonCode,
            subjectSku: $parentSku,
            attributeId: $desiredOption->attributeId,
            configurableOptionId: $optionId,
        );
    }

    private function knownApplied(
        string $reasonCode,
        string $parentSku,
        AdobeConfigurableOptionDesiredState $desiredOption,
        ?int $optionId = null,
        int $consequentialWriteAttempts = 0,
        int $reconciliationGetAttempts = 0,
    ): AdobeConfigurableCommandEvidence {
        return new AdobeConfigurableCommandEvidence(
            commandKind: 'configurable_option',
            appliedStateKnowledge: AdobeProductAppliedStateKnowledge::KnownApplied,
            reasonCode: $reasonCode,
            subjectSku: $parentSku,
            attributeId: $desiredOption->attributeId,
            configurableOptionId: $optionId,
            consequentialWriteAttempts: $consequentialWriteAttempts,
            reconciliationGetAttempts: $reconciliationGetAttempts,
        );
    }

    private function unknownOrAmbiguous(
        string $reasonCode,
        string $parentSku,
        AdobeConfigurableOptionDesiredState $desiredOption,
        ?int $optionId = null,
        int $consequentialWriteAttempts = 0,
        int $reconciliationGetAttempts = 0,
    ): AdobeConfigurableCommandEvidence {
        return new AdobeConfigurableCommandEvidence(
            commandKind: 'configurable_option',
            appliedStateKnowledge: AdobeProductAppliedStateKnowledge::UnknownOrAmbiguous,
            reasonCode: $reasonCode,
            subjectSku: $parentSku,
            attributeId: $desiredOption->attributeId,
            configurableOptionId: $optionId,
            consequentialWriteAttempts: $consequentialWriteAttempts,
            reconciliationGetAttempts: $reconciliationGetAttempts,
        );
    }
}
