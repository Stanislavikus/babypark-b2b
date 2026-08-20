<?php

namespace App\Support\Connectors\AdobePaaS\Media;

use App\Enums\SyncLiveOutcome;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductAppliedStateKnowledge;
use App\Support\Sync\Live\SyncLiveFinding;
use App\Support\Sync\Live\SyncLiveProductExecutionResult;

final class AdobeProductMediaOutcomeComposer
{
    /**
     * @param  list<AdobeProductMediaCommandEvidence>  $mediaEvidence
     */
    public function compose(
        SyncLiveProductExecutionResult $coreResult,
        array $mediaEvidence,
    ): SyncLiveProductExecutionResult {
        if ($mediaEvidence === []) {
            return $coreResult;
        }

        if ($coreResult->outcome !== SyncLiveOutcome::Synchronized) {
            return $coreResult;
        }

        $mediaStates = array_map(
            static fn (AdobeProductMediaCommandEvidence $evidence): AdobeProductAppliedStateKnowledge => $evidence->appliedStateKnowledge,
            $mediaEvidence,
        );

        if (in_array(AdobeProductAppliedStateKnowledge::UnknownOrAmbiguous, $mediaStates, true)) {
            return new SyncLiveProductExecutionResult(
                outcome: SyncLiveOutcome::Ambiguous,
                findings: $this->mergeFindings($coreResult->findings, $mediaEvidence),
            );
        }

        $allApplied = array_reduce(
            $mediaStates,
            static fn (bool $carry, AdobeProductAppliedStateKnowledge $state): bool => $carry && $state === AdobeProductAppliedStateKnowledge::KnownApplied,
            true,
        );

        if ($allApplied) {
            return new SyncLiveProductExecutionResult(
                outcome: SyncLiveOutcome::Synchronized,
                findings: $this->mergeFindings($coreResult->findings, $mediaEvidence),
            );
        }

        return new SyncLiveProductExecutionResult(
            outcome: SyncLiveOutcome::Partial,
            findings: $this->mergeFindings($coreResult->findings, $mediaEvidence),
        );
    }

    /**
     * @param  list<SyncLiveFinding>  $coreFindings
     * @param  list<AdobeProductMediaCommandEvidence>  $mediaEvidence
     * @return list<SyncLiveFinding>
     */
    private function mergeFindings(array $coreFindings, array $mediaEvidence): array
    {
        $findings = $coreFindings;

        foreach ($mediaEvidence as $evidence) {
            $findings[] = new SyncLiveFinding(
                code: 'media_evidence',
                subject: (string) $evidence->declarationIndex,
                context: [
                    'role' => $evidence->role->value,
                    'reason_code' => $evidence->reasonCode,
                    'applied_state_knowledge' => $evidence->appliedStateKnowledge->value,
                    'mime_type' => $evidence->mimeType,
                    'media_entry_id' => $evidence->mediaEntryId,
                    'consequential_write_attempts' => $evidence->consequentialWriteAttempts,
                    'reconciliation_get_attempts' => $evidence->reconciliationGetAttempts,
                    'content_sha256_prefix' => $evidence->contentSha256Prefix,
                ],
            );
        }

        return $findings;
    }
}
