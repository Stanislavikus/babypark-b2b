<?php

namespace App\Support\Connectors\Transport;

interface ConnectorHttpTransport
{
    public function send(#[\SensitiveParameter] ConnectorOutboundRequest $request): ConnectorHttpResult;
}
