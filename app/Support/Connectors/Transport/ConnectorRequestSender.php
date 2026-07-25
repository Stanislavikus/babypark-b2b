<?php

namespace App\Support\Connectors\Transport;

use Psr\Http\Message\RequestInterface;

interface ConnectorRequestSender
{
    public function send(
        #[\SensitiveParameter] RequestInterface $request,
        ValidatedConnectorDestination $destination,
        ConnectorTransportLimits $limits,
        ConnectorTransportDeadline $deadline,
    ): ConnectorHttpResult;
}
