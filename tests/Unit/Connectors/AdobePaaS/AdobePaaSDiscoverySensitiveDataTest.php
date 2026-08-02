<?php

namespace Tests\Unit\Connectors\AdobePaaS;

use App\Services\Connectors\ConnectorDiscoveryRunPersistence;
use App\Support\Connectors\AdobePaaS\AdobePaaSAttributeNormalizer;
use App\Support\Connectors\AdobePaaS\AdobePaaSDiscoveryCapabilityImpl;
use App\Support\Connectors\AdobePaaS\AdobePaaSDiscoveryPage;
use App\Support\Connectors\AdobePaaS\AdobePaaSDiscoveryPageResult;
use App\Support\Connectors\AdobePaaS\AdobePaaSDiscoveryRequestFactory;
use App\Support\Connectors\AdobePaaS\AdobePaaSDiscoveryResponseMapper;
use App\Support\Connectors\AdobePaaS\AdobePaaSDiscoveryTransportMapper;
use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContext;
use App\Support\Connectors\CanonicalSchemaFieldHasher;
use App\Support\Connectors\CanonicalSchemaSnapshotHasher;
use App\Support\Connectors\ConnectorDiscoveryAttemptResult;
use App\Support\Connectors\ConnectorDiscoveryNormalizedField;
use App\Support\Connectors\ConnectorDiscoverySnapshotCandidate;
use App\Support\Connectors\ConnectorSchemaSourceEndpointPathValidator;
use App\Support\Connectors\OAuth1\OAuth1Credentials;
use App\Support\Connectors\OAuth1\OAuth1RequestSigner;
use App\Support\Connectors\Transport\ConnectorHttpResult;
use App\Support\Connectors\Transport\ConnectorHttpTransport;
use App\Support\Connectors\Transport\ConnectorOutboundRequest;
use App\Support\Connectors\Transport\ConnectorTransportException;
use App\Support\Connectors\Transport\TransportFailureReason;
use PHPUnit\Framework\Attributes\Test;
use SensitiveParameterValue;
use Tests\TestCase;

class AdobePaaSDiscoverySensitiveDataTest extends TestCase
{
    private const CREDENTIAL_CANARY = 'CANARY_DISCOVERY_SECRET_4B2B1F';

    #[Test]
    public function secrets_do_not_leak_through_discovery_capability_stack(): void
    {
        $canary = self::CREDENTIAL_CANARY;
        $context = $this->sampleContext($canary);

        $transport = new class($canary) implements ConnectorHttpTransport
        {
            public function __construct(private readonly string $canary) {}

            public function send(#[\SensitiveParameter] ConnectorOutboundRequest $request): ConnectorHttpResult
            {
                return new ConnectorHttpResult(401, [], 'oauth_problem=signature_invalid&secret='.$this->canary);
            }
        };

        $result = $this->capabilityWithTransport($transport)->discover($context, '/V1/products/attributes');

        $this->assertFalse($result->succeeded);
        $this->assertStringNotContainsString($canary, (string) $result->technicalSummary());
        $this->assertStringNotContainsString($canary, (string) $result->messageKey());
    }

    #[Test]
    public function transport_failure_result_does_not_leak_request_secrets(): void
    {
        $canary = self::CREDENTIAL_CANARY;
        $context = $this->sampleContext($canary);

        $transport = new class implements ConnectorHttpTransport
        {
            public function send(#[\SensitiveParameter] ConnectorOutboundRequest $request): ConnectorHttpResult
            {
                throw new ConnectorTransportException(TransportFailureReason::ConnectionFailed);
            }
        };

        $result = $this->capabilityWithTransport($transport)->discover($context, '/V1/products/attributes');

        $this->assertFalse($result->succeeded);
        $this->assertStringNotContainsString($canary, (string) $result->technicalSummary());
        $this->assertStringNotContainsString($canary, (string) $result->messageKey());
    }

    #[Test]
    public function discovery_stack_marks_expected_parameters_as_sensitive(): void
    {
        $methods = [
            [AdobePaaSDiscoveryCapabilityImpl::class, 'discover', [0], false],
            [AdobePaaSDiscoveryRequestFactory::class, 'build', [0, 3], false],
            [AdobePaaSDiscoveryRequestFactory::class, 'buildAbsoluteUrl', [0], true],
            [AdobePaaSDiscoveryResponseMapper::class, 'map', [0], false],
            [AdobePaaSDiscoveryResponseMapper::class, 'mapSuccessResponse', [0], true],
            [AdobePaaSDiscoveryResponseMapper::class, 'mapHttpStatus', [1], true],
            [AdobePaaSDiscoveryTransportMapper::class, 'map', [0], false],
            [AdobePaaSDiscoveryPage::class, '__construct', [0], false],
            [AdobePaaSDiscoveryPageResult::class, '__construct', [0, 1], true],
            [AdobePaaSDiscoveryPageResult::class, 'success', [0], false],
            [AdobePaaSDiscoveryPageResult::class, 'failure', [0], false],
            [ConnectorDiscoveryAttemptResult::class, '__construct', [5], true],
            [ConnectorDiscoveryAttemptResult::class, 'success', [0], false],
            [ConnectorDiscoveryNormalizedField::class, '__construct', [0, 1], false],
            [ConnectorDiscoverySnapshotCandidate::class, 'create', [0, 1], false],
            [ConnectorDiscoveryRunPersistence::class, 'finalizeAfterVendorAttempt', [3], false],
            [ConnectorDiscoveryRunPersistence::class, 'computeRetryDelay', [0], true],
        ];

        foreach ($methods as [$class, $method, $sensitiveIndexes, $isPrivate]) {
            $reflection = new \ReflectionMethod($class, $method);

            if ($isPrivate) {
                $this->assertTrue($reflection->isPrivate(), "{$class}::{$method} must be private");
            }

            foreach ($sensitiveIndexes as $index) {
                $parameter = $reflection->getParameters()[$index];
                $attributes = $parameter->getAttributes(\SensitiveParameter::class);
                $this->assertNotEmpty(
                    $attributes,
                    "{$class}::{$method} parameter {$parameter->getName()} must carry #[\\SensitiveParameter]",
                );
            }
        }
    }

    #[Test]
    public function capability_discover_trace_redacts_context_when_transport_throws(): void
    {
        $sentinel = self::CREDENTIAL_CANARY;
        $previousIgnoreArgs = ini_get('zend.exception_ignore_args');

        try {
            $this->assertTrue(ini_set('zend.exception_ignore_args', '0') !== false);
            $this->assertSame('0', ini_get('zend.exception_ignore_args'));

            $context = $this->sampleContext($sentinel);
            $transport = new class implements ConnectorHttpTransport
            {
                public function send(#[\SensitiveParameter] ConnectorOutboundRequest $request): never
                {
                    throw new \RuntimeException('forced transport throw');
                }
            };

            try {
                $this->capabilityWithTransport($transport)->discover($context, '/V1/products/attributes');
                $this->fail('Expected transport exception');
            } catch (\Throwable $exception) {
                $discoverFrame = $this->findTraceFrame($exception, 'discover');
                $this->assertNotNull($discoverFrame);
                $this->assertInstanceOf(SensitiveParameterValue::class, $discoverFrame['args'][0] ?? null);
                $this->assertExceptionChainDoesNotLeakSentinel($exception, $sentinel);
            }
        } finally {
            ini_set('zend.exception_ignore_args', (string) $previousIgnoreArgs);
        }
    }

    private function capabilityWithTransport(ConnectorHttpTransport $transport): AdobePaaSDiscoveryCapabilityImpl
    {
        return new AdobePaaSDiscoveryCapabilityImpl(
            new AdobePaaSDiscoveryRequestFactory(
                new OAuth1RequestSigner,
                new ConnectorSchemaSourceEndpointPathValidator,
            ),
            $transport,
            new AdobePaaSDiscoveryResponseMapper,
            new AdobePaaSDiscoveryTransportMapper,
            new AdobePaaSAttributeNormalizer,
            new CanonicalSchemaFieldHasher,
            new CanonicalSchemaSnapshotHasher,
        );
    }

    private function sampleContext(string $canary): AdobePaaSRequestContext
    {
        return new AdobePaaSRequestContext(
            baseUrl: 'https://shop.example.com',
            storeCode: 'default',
            credentials: new OAuth1Credentials(
                'ck_'.$canary,
                'cs_'.$canary,
                'at_'.$canary,
                'ts_'.$canary,
            ),
        );
    }

    private function assertExceptionChainDoesNotLeakSentinel(\Throwable $exception, string $sentinel): void
    {
        $current = $exception;

        while ($current !== null) {
            $this->assertStringNotContainsString($sentinel, $current->getMessage());
            $this->assertStringNotContainsString($sentinel, (string) $current);
            $this->assertStringNotContainsString($sentinel, $current->getTraceAsString());
            $this->inspectTraceArguments($current->getTrace(), $sentinel);
            $current = $current->getPrevious();
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $trace
     */
    private function inspectTraceArguments(array $trace, string $sentinel): void
    {
        foreach ($trace as $frame) {
            foreach ($frame['args'] ?? [] as $argument) {
                $this->inspectTraceArgument($argument, $sentinel);
            }
        }
    }

    private function inspectTraceArgument(mixed $argument, string $sentinel): void
    {
        if ($argument instanceof SensitiveParameterValue) {
            return;
        }

        if (is_string($argument)) {
            $this->assertStringNotContainsString($sentinel, $argument);

            return;
        }

        if (is_array($argument)) {
            foreach ($argument as $nested) {
                $this->inspectTraceArgument($nested, $sentinel);
            }

            return;
        }

        if (is_object($argument)) {
            $reflection = new \ReflectionObject($argument);

            foreach ($reflection->getProperties() as $property) {
                if ($property->isInitialized($argument)) {
                    $this->inspectTraceArgument($property->getValue($argument), $sentinel);
                }
            }
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findTraceFrame(\Throwable $exception, string $function): ?array
    {
        foreach ($exception->getTrace() as $frame) {
            if (($frame['function'] ?? '') === $function) {
                return $frame;
            }
        }

        return null;
    }
}
