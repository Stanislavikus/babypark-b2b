<?php

namespace App\Support\Connectors\Transport;

use App\Support\Connectors\Transport\Internal\ConnectorDestinationResolverImpl;
use App\Support\Connectors\Transport\Internal\ConnectorRequestSenderImpl;

final class SsrfSafeConnectorHttpTransport implements ConnectorHttpTransport
{
    public function __construct(
        private readonly ConnectorDestinationResolver $destinationResolver,
        private readonly ConnectorRequestSender $requestSender,
        private readonly MonotonicClock $clock = new SystemMonotonicClock,
    ) {}

    public function send(#[\SensitiveParameter] ConnectorOutboundRequest $request): ConnectorHttpResult
    {
        $deadline = ConnectorTransportDeadline::fromLimits($request->limits, $this->clock);

        $destination = $this->destinationResolver->resolveAndValidate(
            $request->request->getUri(),
            $deadline,
        );

        return $this->requestSender->send(
            $request->request,
            $destination,
            $request->limits,
            $deadline,
        );
    }

    public static function create(
        ConnectorDestinationResolverImpl $destinationResolver,
        ConnectorRequestSenderImpl $requestSender,
        ?MonotonicClock $clock = null,
    ): self {
        return new self($destinationResolver, $requestSender, $clock ?? new SystemMonotonicClock);
    }
}
