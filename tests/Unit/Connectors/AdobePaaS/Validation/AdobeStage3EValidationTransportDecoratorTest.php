<?php

namespace Tests\Unit\Connectors\AdobePaaS\Validation;

use App\Support\Connectors\AdobePaaS\Validation\AdobeStage3EValidationEvidenceWriter;
use App\Support\Connectors\AdobePaaS\Validation\AdobeStage3EValidationTransportArm;
use App\Support\Connectors\AdobePaaS\Validation\AdobeStage3EValidationTransportDecorator;
use App\Support\Connectors\Transport\ConnectorHttpResult;
use App\Support\Connectors\Transport\ConnectorHttpTransport;
use App\Support\Connectors\Transport\ConnectorOutboundRequest;
use App\Support\Connectors\Transport\ConnectorTransportException;
use App\Support\Connectors\Transport\ConnectorTransportLimits;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdobeStage3EValidationTransportDecoratorTest extends TestCase
{
    #[Test]
    public function unrelated_requests_pass_through_and_arm_fires_exactly_once_for_matching_put(): void
    {
        $delegate = new class implements ConnectorHttpTransport
        {
            public int $sendCount = 0;

            public function send(#[\SensitiveParameter] ConnectorOutboundRequest $request): ConnectorHttpResult
            {
                $this->sendCount++;

                return new ConnectorHttpResult(200, [], '{"ok":true}');
            }
        };

        $decorator = new AdobeStage3EValidationTransportDecorator(
            $delegate,
            new AdobeStage3EValidationEvidenceWriter,
        );

        $decorator->armTransportLossAfterWrite(new AdobeStage3EValidationTransportArm(
            normalizedHost: 'shop.example.com',
            storeCode: 'default',
            logicalEntityId: 77,
        ));

        $unrelatedGet = new ConnectorOutboundRequest(
            new Request('GET', 'https://shop.example.com/rest/default/V1/safe-sync/products/77?expectedSku=B2BVAL-77'),
            new ConnectorTransportLimits(10, 30, 1024),
        );
        $this->assertSame(200, $decorator->send($unrelatedGet)->statusCode);

        $unrelatedPut = new ConnectorOutboundRequest(
            new Request('PUT', 'https://shop.example.com/rest/default/V1/safe-sync/products/88'),
            new ConnectorTransportLimits(10, 30, 1024),
        );
        $this->assertSame(200, $decorator->send($unrelatedPut)->statusCode);

        $matchingPut = new ConnectorOutboundRequest(
            new Request('PUT', 'https://shop.example.com/rest/default/V1/safe-sync/products/77'),
            new ConnectorTransportLimits(10, 30, 1024),
        );

        try {
            $decorator->send($matchingPut);
            $this->fail('Expected armed transport loss exception.');
        } catch (ConnectorTransportException) {
            $this->assertSame(1, $decorator->armedTransportLossFireCount());
        }

        $this->assertSame(3, $delegate->sendCount);

        $this->assertSame(200, $decorator->send($matchingPut)->statusCode);
        $this->assertSame(1, $decorator->armedTransportLossFireCount());
        $this->assertSame(4, $delegate->sendCount);
        $this->assertSame(3, $decorator->simpleProductWriteCount());
    }
}
