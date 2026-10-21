<?php

namespace App\Support\Connectors\AdobePaaS\Command;

use App\Enums\SyncLiveOutcome;

final class AdobeConfigurableAppliedStateAggregator
{
    /**
     * @param  list<AdobeConfigurableCommandEvidence>  $evidence
     */
    public function aggregate(array $evidence): SyncLiveOutcome
    {
        if ($evidence === []) {
            return SyncLiveOutcome::NotApplied;
        }

        $states = array_map(
            static fn (AdobeConfigurableCommandEvidence $entry): AdobeProductAppliedStateKnowledge => $entry->appliedStateKnowledge,
            $evidence,
        );

        if (in_array(AdobeProductAppliedStateKnowledge::UnknownOrAmbiguous, $states, true)) {
            return SyncLiveOutcome::Ambiguous;
        }

        $allApplied = array_reduce(
            $states,
            static fn (bool $carry, AdobeProductAppliedStateKnowledge $state): bool => $carry && $state === AdobeProductAppliedStateKnowledge::KnownApplied,
            true,
        );

        if ($allApplied) {
            return SyncLiveOutcome::Synchronized;
        }

        $allNotApplied = array_reduce(
            $states,
            static fn (bool $carry, AdobeProductAppliedStateKnowledge $state): bool => $carry && $state === AdobeProductAppliedStateKnowledge::KnownNotApplied,
            true,
        );

        if ($allNotApplied) {
            return SyncLiveOutcome::NotApplied;
        }

        return SyncLiveOutcome::Partial;
    }
}
