<?php

namespace App\Support\Connectors\AdobePaaS\Receive;

use App\Support\Sync\Receive\ReceiveProposal;

final readonly class AdobeProductReceiveProposalResult
{
    public function __construct(
        public string $flowId,
        public ReceiveProposal $proposal,
    ) {}
}
