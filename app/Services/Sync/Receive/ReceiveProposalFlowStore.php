<?php

namespace App\Services\Sync\Receive;

use App\Support\Sync\Receive\ReceiveProposal;
use App\Support\Sync\Receive\ReceiveProposalFlowBinding;
use App\Support\Sync\Receive\ReceiveStoredProposalFlow;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

final class ReceiveProposalFlowStore
{
    private const DEFAULT_TTL_SECONDS = 600;

    private const LOCK_TTL_SECONDS = 5;

    public function __construct(
        private readonly CacheRepository $cache,
    ) {}

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

        $this->cache->put(
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
        $cacheKey = $this->cacheKey($flowId);
        $lock = $this->cache->lock($this->lockKey($flowId), self::LOCK_TTL_SECONDS);

        if (! $lock->get()) {
            return null;
        }

        try {
            $stored = $this->cache->get($cacheKey);

            if (! $stored instanceof ReceiveStoredProposalFlow) {
                return null;
            }

            // Single-use: invalidate immediately regardless of binding outcome.
            $this->cache->forget($cacheKey);

            if (! $stored->binding->matches($binding)) {
                return null;
            }

            return $stored->proposal;
        } finally {
            optional($lock)->release();
        }
    }

    public function discard(string $flowId, ReceiveProposalFlowBinding $binding): bool
    {
        $cacheKey = $this->cacheKey($flowId);
        $lock = $this->cache->lock($this->lockKey($flowId), self::LOCK_TTL_SECONDS);

        if (! $lock->get()) {
            return false;
        }

        try {
            $stored = $this->cache->get($cacheKey);

            if (! $stored instanceof ReceiveStoredProposalFlow) {
                return false;
            }

            if (! $stored->binding->matches($binding)) {
                return false;
            }

            $this->cache->forget($cacheKey);

            return true;
        } finally {
            optional($lock)->release();
        }
    }

    public static function makeDefault(): self
    {
        return new self(Cache::store());
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
