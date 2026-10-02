<?php

namespace App\Support\Connectors\Transport;

use Psr\Http\Message\RequestInterface;

final readonly class ConnectorOutboundRequest
{
    public function __construct(
        public RequestInterface $request,
        public ConnectorTransportLimits $limits,
    ) {}
}
