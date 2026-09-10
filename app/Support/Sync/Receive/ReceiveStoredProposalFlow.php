<?php

namespace App\Support\Sync\Receive;

final readonly class ReceiveStoredProposalFlow
{
    public function __construct(
        public ReceiveProposalFlowBinding $binding,
        public ReceiveProposal $proposal,
    ) {}
}
