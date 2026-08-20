<?php

namespace App\Support\Connectors\AdobePaaS\Command;

use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContext;
use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContextFactory;
use App\Support\Connectors\OAuth1\OAuth1SigningContext;
use App\Support\Connectors\Transport\ConnectorHttpResult;
use App\Support\Connectors\Transport\ConnectorHttpTransport;
use App\Support\Connectors\Transport\ConnectorOutboundRequest;
use App\Support\Connectors\Transport\ConnectorTransportException;
use App\Support\Connectors\Transport\ConnectorTransportLimits;
use Psr\Http\Message\RequestInterface;

final class AdobeProductRemoteStateClient
{
    public function __construct(
        private readonly AdobePaaSRequestContextFactory $contextFactory,
        private readonly AdobeProductCommandRequestFactory $requestFactory,
        private readonly ConnectorHttpTransport $transport,
        private readonly AdobeProductRemoteGetClassifier $getClassifier,
    ) {}

    public function getProduct(
        string $workspaceId,
        string $connectorAccountId,
        string $sku,
    ): AdobeProductRemoteGetResult {
        $context = $this->contextFactory->create($workspaceId, $connectorAccountId);

        return $this->getProductWithContext($context, $sku);
    }

    public function getProductWithContext(
        AdobePaaSRequestContext $context,
        string $sku,
    ): AdobeProductRemoteGetResult {
        [$httpResult, $transportException] = $this->send(
            $this->requestFactory->buildGet(
                $context,
                $sku,
                $this->newSigningContext(),
            ),
        );

        return $this->getClassifier->classify($sku, $httpResult, $transportException);
    }

    /**
     * @return array{0: ?ConnectorHttpResult, 1: ?ConnectorTransportException}
     */
    public function postProduct(
        AdobePaaSRequestContext $context,
        AdobeProductDesiredState $desiredState,
    ): array {
        return $this->send(
            $this->requestFactory->buildPost(
                $context,
                $desiredState,
                $this->newSigningContext(),
            ),
        );
    }

    /**
     * @return array{0: ?ConnectorHttpResult, 1: ?ConnectorTransportException}
     */
    public function putProduct(
        AdobePaaSRequestContext $context,
        AdobeProductDesiredState $desiredState,
    ): array {
        return $this->send(
            $this->requestFactory->buildPut(
                $context,
                $desiredState,
                $this->newSigningContext(),
            ),
        );
    }

    public function getParentWithContext(
        AdobePaaSRequestContext $context,
        string $sku,
    ): AdobeProductParentRemoteGetResult {
        [$httpResult, $transportException] = $this->send(
            $this->requestFactory->buildGet(
                $context,
                $sku,
                $this->newSigningContext(),
            ),
        );

        return $this->getClassifier->classifyParent($sku, $httpResult, $transportException);
    }

    /**
     * @return array{0: ?ConnectorHttpResult, 1: ?ConnectorTransportException}
     */
    public function postParentProduct(
        AdobePaaSRequestContext $context,
        AdobeProductParentDesiredState $desiredState,
    ): array {
        return $this->send(
            $this->requestFactory->buildPostParent(
                $context,
                $desiredState,
                $this->newSigningContext(),
            ),
        );
    }

    /**
     * @return array{0: ?ConnectorHttpResult, 1: ?ConnectorTransportException}
     */
    public function putParentProduct(
        AdobePaaSRequestContext $context,
        AdobeProductParentDesiredState $desiredState,
    ): array {
        return $this->send(
            $this->requestFactory->buildPutParent(
                $context,
                $desiredState,
                $this->newSigningContext(),
            ),
        );
    }

    /**
     * @return array{0: ?ConnectorHttpResult, 1: ?ConnectorTransportException}
     */
    public function putProductStatus(
        AdobePaaSRequestContext $context,
        string $sku,
        int $status,
    ): array {
        return $this->send(
            $this->requestFactory->buildPutProductStatus(
                $context,
                $sku,
                $status,
                $this->newSigningContext(),
            ),
        );
    }

    /**
     * @return array{0: ?ConnectorHttpResult, 1: ?ConnectorTransportException}
     */
    public function getConfigurableOptions(
        AdobePaaSRequestContext $context,
        string $parentSku,
    ): array {
        return $this->send(
            $this->requestFactory->buildGetConfigurableOptions(
                $context,
                $parentSku,
                $this->newSigningContext(),
            ),
        );
    }

    /**
     * @return array{0: ?ConnectorHttpResult, 1: ?ConnectorTransportException}
     */
    public function postConfigurableOption(
        AdobePaaSRequestContext $context,
        string $parentSku,
        AdobeConfigurableOptionDesiredState $desiredOption,
    ): array {
        return $this->send(
            $this->requestFactory->buildPostConfigurableOption(
                $context,
                $parentSku,
                $desiredOption,
                $this->newSigningContext(),
            ),
        );
    }

    /**
     * @return array{0: ?ConnectorHttpResult, 1: ?ConnectorTransportException}
     */
    public function putConfigurableOption(
        AdobePaaSRequestContext $context,
        string $parentSku,
        int $optionId,
        AdobeConfigurableOptionDesiredState $desiredOption,
    ): array {
        return $this->send(
            $this->requestFactory->buildPutConfigurableOption(
                $context,
                $parentSku,
                $optionId,
                $desiredOption,
                $this->newSigningContext(),
            ),
        );
    }

    /**
     * @return array{0: ?ConnectorHttpResult, 1: ?ConnectorTransportException}
     */
    public function getConfigurableChildren(
        AdobePaaSRequestContext $context,
        string $parentSku,
    ): array {
        return $this->send(
            $this->requestFactory->buildGetConfigurableChildren(
                $context,
                $parentSku,
                $this->newSigningContext(),
            ),
        );
    }

    /**
     * @return array{0: ?ConnectorHttpResult, 1: ?ConnectorTransportException}
     */
    public function postConfigurableChildLink(
        AdobePaaSRequestContext $context,
        string $parentSku,
        string $childSku,
    ): array {
        return $this->send(
            $this->requestFactory->buildPostConfigurableChildLink(
                $context,
                $parentSku,
                $childSku,
                $this->newSigningContext(),
            ),
        );
    }

    /**
     * @return array{0: ?ConnectorHttpResult, 1: ?ConnectorTransportException}
     */
    private function send(RequestInterface $request): array
    {
        $outboundRequest = new ConnectorOutboundRequest(
            $request,
            new ConnectorTransportLimits(
                connectTimeoutSeconds: 10.0,
                totalTimeoutSeconds: 60.0,
                maxResponseBodyBytes: 2 * 1024 * 1024,
            ),
        );

        try {
            return [$this->transport->send($outboundRequest), null];
        } catch (ConnectorTransportException $exception) {
            return [null, $exception];
        }
    }

    private function newSigningContext(): OAuth1SigningContext
    {
        return new OAuth1SigningContext(
            bin2hex(random_bytes(16)),
            time(),
        );
    }
}
