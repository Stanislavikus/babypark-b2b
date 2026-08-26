<?php

namespace App\Services\Sync\Receive;

use App\Support\Sync\Receive\ReceiveProposal;
use App\Support\Sync\Receive\ReceiveProposalFlowBinding;
use App\Support\Sync\Receive\ReceiveStoredProposalFlow;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

final class ReceiveProposalFlowStore
{
    private const DEFAULT_TTL_SECONDS = 600;

    private const LOCK_TTL_SECONDS = 5;

    /**
     * The flow id is only an opaque server-side lookup handle.
     * It is not authorization, identity authority, or execution history.
     */
    public function issue(
        ReceiveProposalFlowBinding $binding,
        ReceiveProposal $proposal,
        int $ttlSeconds = self::DEFAULT_TTL_SECONDS,
    ): string {
        if ($ttlSeconds < 1 || $ttlSeconds > self::DEFAULT_TTL_SECONDS) {
            throw new InvalidArgumentException('ttlSeconds must be between 1 and 600.');
        }

        if (
            $binding->workspaceId !== $proposal->workspaceId
            || $binding->connectorAccountId !== $proposal->connectorAccountId
            || $binding->syncConfigurationId !== $proposal->syncConfigurationId
            || $binding->targetType !== $proposal->targetType
            || $binding->targetId !== $proposal->targetId
        ) {
            throw new InvalidArgumentException('Flow binding must match proposal correlation identifiers.');
        }

        $flowId = bin2hex(random_bytes(32));

        Cache::put(
            $this->cacheKey($flowId),
            new ReceiveStoredProposalFlow($binding, $proposal),
            now()->addSeconds($ttlSeconds),
        );

        return $flowId;
    }

    /**
     * Actor binding is not authorization. Future Apply must freshly authorize
     * before any consequential write.
     */
    public function consume(string $flowId, ReceiveProposalFlowBinding $binding): ?ReceiveProposal
    {
        return Cache::lock($this->lockKey($flowId), self::LOCK_TTL_SECONDS)->block(
            self::LOCK_TTL_SECONDS,
            function () use ($flowId, $binding): ?ReceiveProposal {
                $stored = Cache::get($this->cacheKey($flowId));

                if (! $stored instanceof ReceiveStoredProposalFlow) {
                    return null;
                }

                if (! $stored->binding->matches($binding)) {
                    return null;
                }

                Cache::forget($this->cacheKey($flowId));

                return $stored->proposal;
            },
        );
    }

    public function discard(string $flowId, ReceiveProposalFlowBinding $binding): bool
    {
        return Cache::lock($this->lockKey($flowId), self::LOCK_TTL_SECONDS)->block(
            self::LOCK_TTL_SECONDS,
            function () use ($flowId, $binding): bool {
                $stored = Cache::get($this->cacheKey($flowId));

                if (! $stored instanceof ReceiveStoredProposalFlow) {
                    return false;
                }

                if (! $stored->binding->matches($binding)) {
                    return false;
                }

                Cache::forget($this->cacheKey($flowId));

                return true;
            },
        );
    }

    private function cacheKey(string $flowId): string
    {
        return 'receive-proposal-flow:'.$flowId;
    }

    private function lockKey(string $flowId): string
    {
        return 'receive-proposal-flow-lock:'.$flowId;
    }
}
