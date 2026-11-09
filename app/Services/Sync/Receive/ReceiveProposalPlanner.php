<?php

namespace App\Services\Sync\Receive;

use App\Enums\ReceiveDiffState;
use App\Support\Sync\Receive\ReceiveFieldCandidate;
use App\Support\Sync\Receive\ReceiveProposalEntry;

final class ReceiveProposalPlanner
{
    /**
     * @param  list<ReceiveFieldCandidate>  $candidates
     * @return list<ReceiveProposalEntry>
     */
    public function plan(array $candidates): array
    {
        usort($candidates, [$this, 'compareCandidates']);

        return array_map(
            fn (ReceiveFieldCandidate $candidate): ReceiveProposalEntry => new ReceiveProposalEntry(
                fieldBindingId: $candidate->fieldBindingId,
                objectType: $candidate->objectType,
                domainRoute: $candidate->domainRoute,
                diffState: $this->resolveDiffState($candidate),
                localValuePresent: $candidate->localValuePresent,
                localCanonicalValue: $candidate->localCanonicalValue,
                remoteValuePresent: $candidate->remoteValuePresent,
                remoteCanonicalValue: $candidate->remoteCanonicalValue,
                explicitClear: $candidate->explicitClear,
                blockedReasonCode: $candidate->blockedReasonCode,
            ),
            $candidates,
        );
    }

    private function resolveDiffState(ReceiveFieldCandidate $candidate): ReceiveDiffState
    {
        if (! $candidate->isSupported) {
            return ReceiveDiffState::UnsupportedOrBlocked;
        }

        if ($candidate->explicitClear) {
            return ReceiveDiffState::ExplicitClear;
        }

        if (! $candidate->localValuePresent && ! $candidate->remoteValuePresent) {
            return ReceiveDiffState::Equal;
        }

        if (! $candidate->localValuePresent && $candidate->remoteValuePresent) {
            return ReceiveDiffState::LocalAbsent;
        }

        if ($candidate->localValuePresent && ! $candidate->remoteValuePresent) {
            return ReceiveDiffState::RemoteAbsent;
        }

        if ($this->canonicalValuesAreEqual($candidate->localCanonicalValue, $candidate->remoteCanonicalValue)) {
            return ReceiveDiffState::Equal;
        }

        return ReceiveDiffState::Differs;
    }

    private function compareCandidates(ReceiveFieldCandidate $left, ReceiveFieldCandidate $right): int
    {
        return [
            $left->fieldBindingId,
            $left->objectType->value,
            $left->domainRoute->value,
            $left->blockedReasonCode ?? '',
            $left->explicitClear ? '1' : '0',
            $left->localValuePresent ? '1' : '0',
            $left->remoteValuePresent ? '1' : '0',
        ] <=> [
            $right->fieldBindingId,
            $right->objectType->value,
            $right->domainRoute->value,
            $right->blockedReasonCode ?? '',
            $right->explicitClear ? '1' : '0',
            $right->localValuePresent ? '1' : '0',
            $right->remoteValuePresent ? '1' : '0',
        ];
    }

    private function canonicalValuesAreEqual(mixed $left, mixed $right): bool
    {
        return $this->normalizeCanonicalValue($left) === $this->normalizeCanonicalValue($right);
    }

    private function normalizeCanonicalValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->normalizeCanonicalValue($item), $value);
        }

        ksort($value);

        foreach ($value as $key => $item) {
            $value[$key] = $this->normalizeCanonicalValue($item);
        }

        return $value;
    }
}
