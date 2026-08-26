<?php

namespace Tests\Feature\Sync;

use App\Enums\FieldObjectType;
use App\Enums\ReceiveDiffState;
use App\Enums\ReceiveDomainRoute;
use App\Services\Sync\Receive\ReceiveProposalPlanner;
use App\Support\Sync\Receive\ReceiveFieldCandidate;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReceiveProposalPlannerTest extends TestCase
{
    private ReceiveProposalPlanner $planner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->planner = new ReceiveProposalPlanner;
    }

    #[Test]
    public function diff_vocabulary_is_frozen_and_contains_no_hidden_conflict_semantics(): void
    {
        $this->assertSame([
            'equal',
            'differs',
            'remote_absent',
            'local_absent',
            'unsupported_or_blocked',
            'explicit_clear',
        ], array_map(
            static fn (ReceiveDiffState $case): string => $case->value,
            ReceiveDiffState::cases(),
        ));
    }

    #[Test]
    public function planner_emits_equal_differs_remote_absent_and_local_absent_states(): void
    {
        $entries = $this->planner->plan([
            $this->candidate('binding-equal', true, 'same', true, 'same'),
            $this->candidate('binding-differs', true, 'left', true, 'right'),
            $this->candidate('binding-remote-absent', true, 'local', false, null),
            $this->candidate('binding-local-absent', false, null, true, 'remote'),
            $this->candidate('binding-both-absent', false, null, false, null),
        ]);

        $this->assertSame([
            'binding-both-absent' => ReceiveDiffState::Equal,
            'binding-differs' => ReceiveDiffState::Differs,
            'binding-equal' => ReceiveDiffState::Equal,
            'binding-local-absent' => ReceiveDiffState::LocalAbsent,
            'binding-remote-absent' => ReceiveDiffState::RemoteAbsent,
        ], collect($entries)->mapWithKeys(
            static fn ($entry): array => [$entry->fieldBindingId => $entry->diffState],
        )->all());
    }

    #[Test]
    public function blocked_candidate_becomes_unsupported_or_blocked_and_preserves_reason_and_route(): void
    {
        $entry = $this->planner->plan([
            new ReceiveFieldCandidate(
                fieldBindingId: 'binding-blocked',
                objectType: FieldObjectType::ProductVariant,
                domainRoute: ReceiveDomainRoute::Pricing,
                localValuePresent: true,
                localCanonicalValue: ['gross' => '10.00'],
                remoteValuePresent: true,
                remoteCanonicalValue: ['gross' => '12.00'],
                explicitClear: true,
                isSupported: false,
                blockedReasonCode: 'writer_not_available',
            ),
        ])[0];

        $this->assertSame(ReceiveDiffState::UnsupportedOrBlocked, $entry->diffState);
        $this->assertSame('writer_not_available', $entry->blockedReasonCode);
        $this->assertSame(ReceiveDomainRoute::Pricing, $entry->domainRoute);
    }

    #[Test]
    public function explicit_clear_takes_precedence_over_ordinary_remote_absence(): void
    {
        $entry = $this->planner->plan([
            $this->candidate(
                fieldBindingId: 'binding-clear',
                localValuePresent: true,
                localCanonicalValue: 'local-value',
                remoteValuePresent: false,
                remoteCanonicalValue: null,
                explicitClear: true,
            ),
        ])[0];

        $this->assertSame(ReceiveDiffState::ExplicitClear, $entry->diffState);
    }

    #[Test]
    public function planner_orders_entries_deterministically(): void
    {
        $entries = $this->planner->plan([
            new ReceiveFieldCandidate('z-binding', FieldObjectType::ProductVariant, ReceiveDomainRoute::Media, false, null, false, null),
            new ReceiveFieldCandidate('a-binding', FieldObjectType::Product, ReceiveDomainRoute::DynamicField, false, null, false, null),
            new ReceiveFieldCandidate('a-binding', FieldObjectType::ProductVariant, ReceiveDomainRoute::DynamicField, false, null, false, null),
        ]);

        $this->assertSame([
            'a-binding|product|dynamic_field',
            'a-binding|product_variant|dynamic_field',
            'z-binding|product_variant|media',
        ], array_map(
            static fn ($entry): string => $entry->fieldBindingId.'|'.$entry->objectType->value.'|'.$entry->domainRoute->value,
            $entries,
        ));
    }

    #[Test]
    public function canonical_structured_equality_is_deterministic_for_associative_structures(): void
    {
        $entry = $this->planner->plan([
            $this->candidate(
                fieldBindingId: 'binding-structured',
                localValuePresent: true,
                localCanonicalValue: [
                    'b' => 2,
                    'nested' => ['z' => 9, 'a' => 1],
                    'a' => 1,
                ],
                remoteValuePresent: true,
                remoteCanonicalValue: [
                    'nested' => ['a' => 1, 'z' => 9],
                    'a' => 1,
                    'b' => 2,
                ],
            ),
        ])[0];

        $this->assertSame(ReceiveDiffState::Equal, $entry->diffState);
    }

    private function candidate(
        string $fieldBindingId,
        bool $localValuePresent,
        mixed $localCanonicalValue,
        bool $remoteValuePresent,
        mixed $remoteCanonicalValue,
        bool $explicitClear = false,
    ): ReceiveFieldCandidate {
        return new ReceiveFieldCandidate(
            fieldBindingId: $fieldBindingId,
            objectType: FieldObjectType::Product,
            domainRoute: ReceiveDomainRoute::DynamicField,
            localValuePresent: $localValuePresent,
            localCanonicalValue: $localCanonicalValue,
            remoteValuePresent: $remoteValuePresent,
            remoteCanonicalValue: $remoteCanonicalValue,
            explicitClear: $explicitClear,
        );
    }
}
