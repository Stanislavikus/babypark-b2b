<?php

namespace App\Services\Sync\EntityTrust;

use App\Enums\EntityTrust\EntityTrustConfirmationMode;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Sync\EntityTrust\EntityTrustReviewFlowPayload;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;

/**
 * Narrow, server-side transient store for the R2b-2 merchant Entity Trust UI.
 *
 * The UI is given only an opaque, cryptographically unguessable flow ID. The
 * real reviewToken stays in this cache and is retrieved on confirm after fresh
 * authorization. Every flow entry is bound to the actor, workspace, account
 * and Product that created it; stale or mismatched retrievals fail closed.
 *
 * No database table, no new migration. The cache is the Laravel configured
 * cache store (file/redis/database) with a namespaced key prefix.
 */
final class EntityTrustReviewFlowStore
{
    /**
     * Flow TTL must be <= the R2b-1 envelope TTL (15 min). 10 minutes keeps
     * the flow shorter than the underlying reviewToken TTL.
     */
    private const int TTL_MINUTES = 10;

    private const string KEY_PREFIX = 'entity_trust_review_flow:';

    public function __construct(
        private readonly CacheRepository $cache,
    ) {}

    public function issue(
        User $actor,
        Workspace $workspace,
        string $connectorAccountId,
        string $productId,
        string $reviewToken,
        EntityTrustConfirmationMode $mode,
        ?string $existingParentSkuHint,
        bool $explicitRelink,
    ): string {
        $flowId = $this->generateFlowId();

        $payload = new EntityTrustReviewFlowPayload(
            actorUserId: (string) $actor->id,
            workspaceId: $workspace->id,
            connectorAccountId: $connectorAccountId,
            productId: $productId,
            reviewToken: $reviewToken,
            mode: $mode,
            existingParentSkuHint: $existingParentSkuHint,
            explicitRelink: $explicitRelink,
        );

        $this->cache->put(
            $this->key($flowId),
            $payload,
            now()->addMinutes(self::TTL_MINUTES),
        );

        return $flowId;
    }

    /**
     * Retrieve and CONSUME the flow atomically. Any binding mismatch or missing
     * entry returns null and the caller must treat this as "flow invalid".
     */
    public function consume(
        User $actor,
        Workspace $workspace,
        string $connectorAccountId,
        string $productId,
        string $flowId,
    ): ?EntityTrustReviewFlowPayload {
        $key = $this->key($flowId);

        $payload = $this->cache->get($key);

        if (! $payload instanceof EntityTrustReviewFlowPayload) {
            return null;
        }

        // Single-use: invalidate immediately regardless of binding outcome.
        $this->cache->forget($key);

        if (! $this->matches($payload, $actor, $workspace, $connectorAccountId, $productId)) {
            return null;
        }

        return $payload;
    }

    /**
     * Discard a flow entry without consuming. Used by stale/invalid paths
     * that the merchant must redo from scratch.
     */
    public function discard(string $flowId): void
    {
        $this->cache->forget($this->key($flowId));
    }

    private function matches(
        EntityTrustReviewFlowPayload $payload,
        User $actor,
        Workspace $workspace,
        string $connectorAccountId,
        string $productId,
    ): bool {
        return $payload->actorUserId === (string) $actor->id
            && $payload->workspaceId === $workspace->id
            && $payload->connectorAccountId === $connectorAccountId
            && $payload->productId === $productId;
    }

    private function key(string $flowId): string
    {
        return self::KEY_PREFIX.$flowId;
    }

    private function generateFlowId(): string
    {
        // 32 random bytes -> 64 hex chars, unguessable. Prefix with a tag so
        // the value is never mistaken for a token, but only the prefix is
        // ever exposed to the browser.
        return 'etflow_'.bin2hex(random_bytes(32));
    }

    public static function makeDefault(): self
    {
        return new self(Cache::store());
    }
}
