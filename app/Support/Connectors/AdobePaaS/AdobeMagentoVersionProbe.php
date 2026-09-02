<?php

namespace App\Support\Connectors\AdobePaaS;

use App\Support\Connectors\Transport\ConnectorHttpTransport;
use App\Support\Connectors\Transport\ConnectorOutboundRequest;
use App\Support\Connectors\Transport\ConnectorTransportException;
use App\Support\Connectors\Transport\ConnectorTransportLimits;
use GuzzleHttp\Psr7\Request;

final class AdobeMagentoVersionProbe implements AdobeMagentoVersionProbeCapability
{
    public function __construct(
        private readonly ConnectorHttpTransport $transport,
    ) {}

    public function probe(#[\SensitiveParameter] AdobePaaSRequestContext $context): ?string
    {
        try {
            $result = $this->transport->send(new ConnectorOutboundRequest(
                new Request('GET', $this->buildAbsoluteUrl($context)),
                new ConnectorTransportLimits(
                    connectTimeoutSeconds: 5.0,
                    totalTimeoutSeconds: 15.0,
                    maxResponseBodyBytes: 4 * 1024,
                ),
            ));
        } catch (ConnectorTransportException) {
            return null;
        }

        if ($result->statusCode !== 200) {
            return null;
        }

        $value = trim($result->body);

        if ($value === '' || strlen($value) > 120) {
            return null;
        }

        if (preg_match('/^[[:print:]\s]+$/', $value) !== 1) {
            return null;
        }

        return str_contains($value, 'Magento/') ? $value : null;
    }

    private function buildAbsoluteUrl(AdobePaaSRequestContext $context): string
    {
        $baseUrl = AdobePaaSBaseUrl::parse($context->baseUrl);
        $parsed = parse_url($baseUrl->value);

        if ($parsed === false || ! isset($parsed['scheme'], $parsed['host'])) {
            throw new \InvalidArgumentException('Adobe PaaS base URL must be an absolute URL.');
        }

        $path = rtrim($parsed['path'] ?? '', '/').'/magento_version';
        $port = isset($parsed['port']) ? ':'.$parsed['port'] : '';

        return $parsed['scheme'].'://'.$parsed['host'].$port.$path;
    }
}
