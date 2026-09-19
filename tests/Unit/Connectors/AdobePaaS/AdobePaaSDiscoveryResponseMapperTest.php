<?php

namespace Tests\Unit\Connectors\AdobePaaS;

use App\Enums\ConnectorDiscoveryRunErrorCode;
use App\Support\Connectors\AdobePaaS\AdobePaaSDiscoveryResponseMapper;
use App\Support\Connectors\Transport\ConnectorHttpResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdobePaaSDiscoveryResponseMapperTest extends TestCase
{
    #[Test]
    #[DataProvider('structuredAccessDenialProvider')]
    public function structured_resource_denial_is_authorization_for_401_and_403(int $status): void
    {
        $result = (new AdobePaaSDiscoveryResponseMapper)->map(new ConnectorHttpResult(
            $status,
            [],
            '{"message":"ignored","parameters":{"resources":"Magento_Catalog::products"}}',
        ));

        $this->assertNull($result->page);
        $this->assertSame(ConnectorDiscoveryRunErrorCode::AdobeInsufficientPermissions, $result->failure?->errorCode);
        $this->assertSame($status, $result->failure?->httpStatus);
    }

    #[Test]
    #[DataProvider('ambiguousAccessRejectionProvider')]
    public function access_rejection_without_structured_resource_evidence_is_conservative(
        int $status,
        string $body,
    ): void {
        $result = (new AdobePaaSDiscoveryResponseMapper)->map(new ConnectorHttpResult($status, [], $body));

        $this->assertNull($result->page);
        $this->assertSame(ConnectorDiscoveryRunErrorCode::AdobeAccessRejectedUndetermined, $result->failure?->errorCode);
        $this->assertSame($status, $result->failure?->httpStatus);
    }

    public static function structuredAccessDenialProvider(): iterable
    {
        yield '401' => [401];
        yield '403' => [403];
    }

    public static function ambiguousAccessRejectionProvider(): iterable
    {
        yield '401 free form permission sentence' => [401, '{"message":"The consumer is not authorized to access this resource."}'];
        yield '403 empty object' => [403, '{}'];
        yield '401 malformed json' => [401, 'not-json'];
        yield '403 blank resources' => [403, '{"parameters":{"resources":"   "}}'];
    }
}
