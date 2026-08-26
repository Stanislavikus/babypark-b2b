<?php

namespace App\Support\Sync\Receive;

use App\Enums\FieldObjectType;

final readonly class ReceiveProposalFlowBinding
{
    public string $actorUserId;

    public string $targetId;

    public function __construct(
        int|string $actorUserId,
        public string $workspaceId,
        public string $connectorAccountId,
        public string $syncConfigurationId,
        public FieldObjectType $targetType,
        int|string $targetId,
    ) {
        $this->actorUserId = (string) $actorUserId;
        $this->targetId = (string) $targetId;
    }

    /**
     * Actor binding is not authorization. Future Apply must re-authorize
     * against current workspace/account state before any consequential write.
     */
    public function matches(self $other): bool
    {
        return $this->actorUserId === $other->actorUserId
            && $this->workspaceId === $other->workspaceId
            && $this->connectorAccountId === $other->connectorAccountId
            && $this->syncConfigurationId === $other->syncConfigurationId
            && $this->targetType === $other->targetType
            && $this->targetId === $other->targetId;
    }
}
