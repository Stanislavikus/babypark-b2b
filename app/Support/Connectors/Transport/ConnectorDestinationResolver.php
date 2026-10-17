<?php

namespace App\Support\Connectors\Transport;

use Psr\Http\Message\UriInterface;

interface ConnectorDestinationResolver
{
    public function resolveAndValidate(
        #[\SensitiveParameter] UriInterface $uri,
        ConnectorTransportDeadline $deadline,
    ): ValidatedConnectorDestination;
}
