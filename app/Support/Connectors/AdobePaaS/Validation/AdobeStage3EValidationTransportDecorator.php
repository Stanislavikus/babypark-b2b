<?php

namespace App\Support\Connectors\AdobePaaS\Validation;

use App\Support\Connectors\Transport\ConnectorHttpResult;
use App\Support\Connectors\Transport\ConnectorHttpTransport;
use App\Support\Connectors\Transport\ConnectorOutboundRequest;
use App\Support\Connectors\Transport\ConnectorTransportException;
use App\Support\Connectors\Transport\TransportFailureReason;

final class AdobeStage3EValidationTransportDecorator implements ConnectorHttpTransport
{
    /** @var array<string, AdobeStage3EValidationTransportFaultShape> */
    private array $armedFaults = [];

    public function __construct(
        private readonly ConnectorHttpTransport $delegate,
        private readonly AdobeStage3EValidationEvidenceSink $evidenceSink,
    ) {}

    public function armFault(
        AdobeStage3EValidationTransportArmKey $armKey,
        AdobeStage3EValidationTransportFaultShape $faultShape,
    ): void {
        $this->armedFaults[$armKey->signature()] = $faultShape;
    }

    public function send(#[\SensitiveParameter] ConnectorOutboundRequest $request): ConnectorHttpResult
    {
        $delegateResult = $this->delegate->send($request);
        $armKey = $this->resolveArmKey($request);
        $faultShape = $armKey !== null ? ($this->armedFaults[$armKey->signature()] ?? null) : null;

        if ($armKey !== null) {
            $this->evidenceSink->record(
                method: $armKey->method,
                resourceCategory: $armKey->resourceCategory,
                externalTestIdentity: $armKey->externalIdentifier,
                delegateStatusCode: $delegateResult->statusCode,
                responseBody: $delegateResult->body,
            );
            unset($this->armedFaults[$armKey->signature()]);
        }

        if ($faultShape === null) {
            return $delegateResult;
        }

        return match ($faultShape) {
            AdobeStage3EValidationTransportFaultShape::TransportUnknown => throw new ConnectorTransportException(
                TransportFailureReason::OtherTransportFailure,
            ),
            AdobeStage3EValidationTransportFaultShape::SyntheticNon2xx => new ConnectorHttpResult(
                503,
                [],
                '{"message":"stage3e_validation_synthetic_non_2xx"}',
            ),
            AdobeStage3EValidationTransportFaultShape::InconclusiveBody => new ConnectorHttpResult(
                200,
                [],
                '{"message":"stage3e_validation_inconclusive_body"}',
            ),
        };
    }

    private function resolveArmKey(ConnectorOutboundRequest $request): ?AdobeStage3EValidationTransportArmKey
    {
        $method = strtoupper($request->request->getMethod());
        $path = $request->request->getUri()->getPath();

        if (preg_match('#/products/([^/]+)$#', $path, $matches) === 1) {
            return new AdobeStage3EValidationTransportArmKey(
                method: $method,
                resourceCategory: 'product',
                externalIdentifier: $matches[1],
            );
        }

        return null;
    }
}
