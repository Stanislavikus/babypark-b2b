<?php

namespace Tests\Support\Connectors;

use App\Support\Connectors\Transport\ConnectorHttpResult;
use App\Support\Connectors\Transport\ConnectorHttpTransport;
use App\Support\Connectors\Transport\ConnectorOutboundRequest;

final class RecordingConnectorHttpTransport implements ConnectorHttpTransport
{
    /** @var list<ConnectorOutboundRequest> */
    public array $recordedRequests = [];

    public int $sendCount = 0;

    public function __construct(
        private readonly \Closure $responder,
    ) {}

    public function send(#[\SensitiveParameter] ConnectorOutboundRequest $request): ConnectorHttpResult
    {
        $this->sendCount++;
        $this->recordedRequests[] = $request;

        return ($this->responder)($request, $this->sendCount);
    }
}
