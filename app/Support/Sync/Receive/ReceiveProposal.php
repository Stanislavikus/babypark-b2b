<?php

namespace App\Support\Sync\Receive;

use App\Enums\FieldObjectType;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ReceiveProposal
{
    /**
     * @param  list<ReceiveProposalEntry>  $entries
     */
    public function __construct(
        public string $workspaceId,
        public string $connectorAccountId,
        public string $syncConfigurationId,
        public string $configurationRevision,
        public FieldObjectType $targetType,
        public string $targetId,
        public string $trustedExternalLinkEvidenceId,
        public array $entries,
        public DateTimeImmutable $issuedAt,
    ) {
        if (
            $this->workspaceId === ''
            || $this->connectorAccountId === ''
            || $this->syncConfigurationId === ''
            || $this->configurationRevision === ''
            || $this->targetId === ''
            || $this->trustedExternalLinkEvidenceId === ''
        ) {
            throw new InvalidArgumentException('ReceiveProposal correlation identifiers must not be empty.');
        }
    }
}
