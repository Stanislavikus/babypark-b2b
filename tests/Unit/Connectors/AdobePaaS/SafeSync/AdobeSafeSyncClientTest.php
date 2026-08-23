<?php

namespace Tests\Unit\Connectors\AdobePaaS\SafeSync;

use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContext;
use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContextFactory;
use App\Support\Connectors\AdobePaaS\SafeSync\AdobeSafeSyncClient;
use App\Support\Connectors\AdobePaaS\SafeSync\AdobeSafeSyncClientException;
use App\Support\Connectors\AdobePaaS\SafeSync\AdobeSafeSyncContract;
use App\Support\Connectors\AdobePaaS\SafeSync\AdobeSafeSyncRequestFactory;
use App\Support\Connectors\AdobePaaS\SafeSync\AdobeSafeSyncSimpleProductWriteCustomAttribute;
use App\Support\Connectors\AdobePaaS\SafeSync\AdobeSafeSyncSimpleProductWriteRequest;
use App\Support\Connectors\OAuth1\OAuth1Credentials;
use App\Support\Connectors\OAuth1\OAuth1RequestSigner;
use App\Support\Connectors\Transport\ConnectorHttpResult;
use App\Support\Connectors\Transport\ConnectorHttpTransport;
use App\Support\Connectors\Transport\ConnectorOutboundRequest;
use App\Support\Connectors\Transport\ConnectorTransportException;
use App\Support\Connectors\Transport\TransportFailureReason;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdobeSafeSyncClientTest extends TestCase
{
    #[Test]
    public function handshake_uses_existing_authenticated_adobe_transport_with_bounded_response_limit(): void
    {
        $transport = new class implements ConnectorHttpTransport
        {
            public ?ConnectorOutboundRequest $captured = null;

            public function send(#[\SensitiveParameter] ConnectorOutboundRequest $request): ConnectorHttpResult
            {
                $this->captured = $request;

                return new ConnectorHttpResult(200, [], json_encode([
                    'contract_version' => AdobeSafeSyncContract::CONTRACT_VERSION,
                    'module_version' => '0.1.0',
                    'supported_operation_families' => [
                        AdobeSafeSyncContract::PRODUCT_VERIFICATION_READ_FAMILY,
                    ],
                ], JSON_THROW_ON_ERROR));
            }
        };

        $client = $this->clientWithTransport($transport);
        $handshake = $client->handshakeWithContext($this->context());

        $this->assertSame(AdobeSafeSyncContract::CONTRACT_VERSION, $handshake->contractVersion);
        $this->assertNotNull($transport->captured);
        $this->assertSame('GET', $transport->captured->request->getMethod());
        $this->assertSame(
            'https://shop.example.com/rest/default/V1/safe-sync/handshake',
            (string) $transport->captured->request->getUri(),
        );
        $this->assertStringContainsString('oauth_consumer_key="ck_test"', $transport->captured->request->getHeaderLine('Authorization'));
        $this->assertSame(AdobeSafeSyncContract::HANDSHAKE_MAX_RESPONSE_BYTES, $transport->captured->limits->maxResponseBodyBytes);
    }

    #[Test]
    public function handshake_accepts_new_optional_simple_write_family(): void
    {
        $client = $this->clientWithTransport(new class implements ConnectorHttpTransport
        {
            public function send(#[\SensitiveParameter] ConnectorOutboundRequest $request): ConnectorHttpResult
            {
                return new ConnectorHttpResult(200, [], json_encode([
                    'contract_version' => AdobeSafeSyncContract::CONTRACT_VERSION,
                    'module_version' => '0.2.0',
                    'supported_operation_families' => [
                        AdobeSafeSyncContract::PRODUCT_VERIFICATION_READ_FAMILY,
                        AdobeSafeSyncContract::SIMPLE_PRODUCT_WRITE_FAMILY,
                    ],
                ], JSON_THROW_ON_ERROR));
            }
        });

        $handshake = $client->handshakeWithContext($this->context());

        $this->assertSame([
            AdobeSafeSyncContract::PRODUCT_VERIFICATION_READ_FAMILY,
            AdobeSafeSyncContract::SIMPLE_PRODUCT_WRITE_FAMILY,
        ], $handshake->supportedOperationFamilies);
    }

    #[Test]
    public function unknown_operation_family_still_fails_closed(): void
    {
        $client = $this->clientWithTransport(new class implements ConnectorHttpTransport
        {
            public function send(#[\SensitiveParameter] ConnectorOutboundRequest $request): ConnectorHttpResult
            {
                return new ConnectorHttpResult(200, [], json_encode([
                    'contract_version' => AdobeSafeSyncContract::CONTRACT_VERSION,
                    'module_version' => '0.2.0',
                    'supported_operation_families' => [
                        AdobeSafeSyncContract::PRODUCT_VERIFICATION_READ_FAMILY,
                        'unexpected_family',
                    ],
                ], JSON_THROW_ON_ERROR));
            }
        });

        $this->expectException(AdobeSafeSyncClientException::class);
        $this->expectExceptionMessage('Safe Sync advertised an unknown operation family.');

        $client->handshakeWithContext($this->context());
    }

    #[Test]
    public function entity_read_uses_safe_sync_endpoint_with_bounded_response_limit(): void
    {
        $transport = new class implements ConnectorHttpTransport
        {
            public ?ConnectorOutboundRequest $captured = null;

            public function send(#[\SensitiveParameter] ConnectorOutboundRequest $request): ConnectorHttpResult
            {
                $this->captured = $request;

                return new ConnectorHttpResult(200, [], json_encode([
                    'logical_entity_id' => 77,
                    'sku' => 'SKU-77',
                    'type_id' => 'simple',
                    'name' => 'Verified Product',
                ], JSON_THROW_ON_ERROR));
            }
        };

        $client = $this->clientWithTransport($transport);
        $product = $client->readProductWithContext($this->context(), 77, 'SKU-77');

        $this->assertSame(77, $product->logicalEntityId);
        $this->assertNotNull($transport->captured);
        $this->assertSame(
            'https://shop.example.com/rest/default/V1/safe-sync/products/77?expectedSku=SKU-77',
            (string) $transport->captured->request->getUri(),
        );
        $this->assertStringNotContainsString('/V1/products/SKU-77', (string) $transport->captured->request->getUri());
        $this->assertSame(AdobeSafeSyncContract::PRODUCT_READ_MAX_RESPONSE_BYTES, $transport->captured->limits->maxResponseBodyBytes);
    }

    #[Test]
    public function malformed_handshake_fails_closed(): void
    {
        $client = $this->clientWithTransport(new class implements ConnectorHttpTransport
        {
            public function send(#[\SensitiveParameter] ConnectorOutboundRequest $request): ConnectorHttpResult
            {
                return new ConnectorHttpResult(200, [], '{');
            }
        });

        $this->expectException(AdobeSafeSyncClientException::class);
        $this->expectExceptionMessage('Safe Sync returned malformed JSON.');

        $client->handshakeWithContext($this->context());
    }

    #[Test]
    public function missing_module_version_fails_closed(): void
    {
        $client = $this->clientWithTransport(new class implements ConnectorHttpTransport
        {
            public function send(#[\SensitiveParameter] ConnectorOutboundRequest $request): ConnectorHttpResult
            {
                return new ConnectorHttpResult(200, [], json_encode([
                    'contract_version' => AdobeSafeSyncContract::CONTRACT_VERSION,
                    'supported_operation_families' => [
                        AdobeSafeSyncContract::PRODUCT_VERIFICATION_READ_FAMILY,
                    ],
                ], JSON_THROW_ON_ERROR));
            }
        });

        $this->expectException(AdobeSafeSyncClientException::class);
        $this->expectExceptionMessage('Safe Sync response field `module_version` is invalid.');

        $client->handshakeWithContext($this->context());
    }

    #[Test]
    public function sentinel_module_version_fails_closed(): void
    {
        $client = $this->clientWithTransport(new class implements ConnectorHttpTransport
        {
            public function send(#[\SensitiveParameter] ConnectorOutboundRequest $request): ConnectorHttpResult
            {
                return new ConnectorHttpResult(200, [], json_encode([
                    'contract_version' => AdobeSafeSyncContract::CONTRACT_VERSION,
                    'module_version' => '0.0.0',
                    'supported_operation_families' => [
                        AdobeSafeSyncContract::PRODUCT_VERIFICATION_READ_FAMILY,
                    ],
                ], JSON_THROW_ON_ERROR));
            }
        });

        $this->expectException(AdobeSafeSyncClientException::class);
        $this->expectExceptionMessage('Safe Sync module version is invalid.');

        $client->handshakeWithContext($this->context());
    }

    #[Test]
    public function malformed_module_version_fails_closed(): void
    {
        $client = $this->clientWithTransport(new class implements ConnectorHttpTransport
        {
            public function send(#[\SensitiveParameter] ConnectorOutboundRequest $request): ConnectorHttpResult
            {
                return new ConnectorHttpResult(200, [], json_encode([
                    'contract_version' => AdobeSafeSyncContract::CONTRACT_VERSION,
                    'module_version' => ' 0.1.0 ',
                    'supported_operation_families' => [
                        AdobeSafeSyncContract::PRODUCT_VERIFICATION_READ_FAMILY,
                    ],
                ], JSON_THROW_ON_ERROR));
            }
        });

        $this->expectException(AdobeSafeSyncClientException::class);
        $this->expectExceptionMessage('Safe Sync module version is invalid.');

        $client->handshakeWithContext($this->context());
    }

    #[Test]
    public function unsupported_contract_version_fails_closed(): void
    {
        $client = $this->clientWithTransport(new class implements ConnectorHttpTransport
        {
            public function send(#[\SensitiveParameter] ConnectorOutboundRequest $request): ConnectorHttpResult
            {
                return new ConnectorHttpResult(200, [], json_encode([
                    'contract_version' => 'legacy-contract',
                    'module_version' => '0.1.0',
                    'supported_operation_families' => [
                        AdobeSafeSyncContract::PRODUCT_VERIFICATION_READ_FAMILY,
                    ],
                ], JSON_THROW_ON_ERROR));
            }
        });

        $this->expectException(AdobeSafeSyncClientException::class);
        $this->expectExceptionMessage('Safe Sync contract version is not supported.');

        $client->handshakeWithContext($this->context());
    }

    #[Test]
    public function malformed_entity_response_fails_closed(): void
    {
        $client = $this->clientWithTransport(new class implements ConnectorHttpTransport
        {
            public function send(#[\SensitiveParameter] ConnectorOutboundRequest $request): ConnectorHttpResult
            {
                return new ConnectorHttpResult(200, [], json_encode([
                    'logical_entity_id' => '77',
                    'sku' => 'SKU-77',
                ], JSON_THROW_ON_ERROR));
            }
        });

        $this->expectException(AdobeSafeSyncClientException::class);
        $this->expectExceptionMessage('Safe Sync response field `logical_entity_id` is invalid.');

        $client->readProductWithContext($this->context(), 77, 'SKU-77');
    }

    #[Test]
    public function identity_mismatch_fails_closed(): void
    {
        $client = $this->clientWithTransport(new class implements ConnectorHttpTransport
        {
            public function send(#[\SensitiveParameter] ConnectorOutboundRequest $request): ConnectorHttpResult
            {
                return new ConnectorHttpResult(200, [], json_encode([
                    'logical_entity_id' => 78,
                    'sku' => 'SKU-77',
                    'type_id' => 'simple',
                    'name' => 'Verified Product',
                ], JSON_THROW_ON_ERROR));
            }
        });

        $this->expectException(AdobeSafeSyncClientException::class);
        $this->expectExceptionMessage('Safe Sync logical entity identity mismatch.');

        $client->readProductWithContext($this->context(), 77, 'SKU-77');
    }

    #[Test]
    public function sku_mismatch_fails_closed(): void
    {
        $client = $this->clientWithTransport(new class implements ConnectorHttpTransport
        {
            public function send(#[\SensitiveParameter] ConnectorOutboundRequest $request): ConnectorHttpResult
            {
                return new ConnectorHttpResult(200, [], json_encode([
                    'logical_entity_id' => 77,
                    'sku' => 'SKU-OTHER',
                    'type_id' => 'simple',
                    'name' => 'Verified Product',
                ], JSON_THROW_ON_ERROR));
            }
        });

        $this->expectException(AdobeSafeSyncClientException::class);
        $this->expectExceptionMessage('Safe Sync SKU mismatch.');

        $client->readProductWithContext($this->context(), 77, 'SKU-77');
    }

    #[Test]
    public function ambiguity_fails_closed_on_non_success_http_semantics(): void
    {
        $client = $this->clientWithTransport(new class implements ConnectorHttpTransport
        {
            public function send(#[\SensitiveParameter] ConnectorOutboundRequest $request): ConnectorHttpResult
            {
                return new ConnectorHttpResult(409, [], json_encode([
                    'message' => 'safe_sync_ambiguous_sku',
                ], JSON_THROW_ON_ERROR));
            }
        });

        $this->expectException(AdobeSafeSyncClientException::class);
        $this->expectExceptionMessage('Safe Sync returned unexpected HTTP status 409.');

        $client->readProductWithContext($this->context(), 77, 'SKU-77');
    }

    #[Test]
    public function transport_failures_do_not_serialize_secrets(): void
    {
        $client = $this->clientWithTransport(new class implements ConnectorHttpTransport
        {
            public function send(#[\SensitiveParameter] ConnectorOutboundRequest $request): ConnectorHttpResult
            {
                throw new ConnectorTransportException(TransportFailureReason::Timeout);
            }
        });

        try {
            $client->handshakeWithContext($this->context());
            $this->fail('Expected Safe Sync client to fail closed on transport failure.');
        } catch (AdobeSafeSyncClientException $exception) {
            $this->assertStringNotContainsString('cs_test', $exception->getMessage());
            $this->assertStringNotContainsString('ts_test', $exception->getMessage());
            $this->assertSame('Safe Sync request failed.', $exception->getMessage());
        }
    }

    #[Test]
    public function simple_product_write_uses_safe_sync_put_with_bounded_response_and_existing_applied_state_vocabulary(): void
    {
        $transport = new class implements ConnectorHttpTransport
        {
            public ?ConnectorOutboundRequest $captured = null;

            public function send(#[\SensitiveParameter] ConnectorOutboundRequest $request): ConnectorHttpResult
            {
                $this->captured = $request;

                return new ConnectorHttpResult(200, [], json_encode([
                    'applied_state' => 'known_not_applied',
                    'reason_code' => 'safe_sync_entity_missing',
                    'logical_entity_id' => 77,
                    'sku' => 'SKU-77',
                    'postcondition_verified' => false,
                    'consequential_write_attempts' => 0,
                    'warning_codes' => [],
                ], JSON_THROW_ON_ERROR));
            }
        };

        $client = $this->clientWithTransport($transport);
        $result = $client->writeSimpleProductWithContext(
            $this->context(),
            77,
            new AdobeSafeSyncSimpleProductWriteRequest(
                expectedSku: 'SKU-77',
                name: 'Updated Product',
                mappedAttributes: [
                    new AdobeSafeSyncSimpleProductWriteCustomAttribute('color', 'red'),
                ],
            ),
        );

        $this->assertSame('known_not_applied', $result->appliedStateKnowledge->value);
        $this->assertNotNull($transport->captured);
        $this->assertSame('PUT', $transport->captured->request->getMethod());
        $this->assertSame(
            'https://shop.example.com/rest/default/V1/safe-sync/products/77',
            (string) $transport->captured->request->getUri(),
        );
        $this->assertSame(AdobeSafeSyncContract::SIMPLE_PRODUCT_WRITE_MAX_RESPONSE_BYTES, $transport->captured->limits->maxResponseBodyBytes);
        $payload = json_decode((string) $transport->captured->request->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['request'], array_keys($payload));
        $this->assertSame('SKU-77', $payload['request']['expected_sku'] ?? null);
        $this->assertSame([['attribute_code' => 'color', 'value' => 'red']], $payload['request']['mapped_attributes'] ?? null);
    }

    #[Test]
    public function malformed_simple_product_write_response_is_unknown_or_ambiguous_after_put_submission(): void
    {
        $client = $this->clientWithTransport(new class implements ConnectorHttpTransport
        {
            public function send(#[\SensitiveParameter] ConnectorOutboundRequest $request): ConnectorHttpResult
            {
                return new ConnectorHttpResult(200, [], json_encode([
                    'applied_state' => 'known_not_applied',
                    'reason_code' => 'safe_sync_entity_missing',
                    'logical_entity_id' => 77,
                    'sku' => 'SKU-77',
                    'postcondition_verified' => false,
                    'consequential_write_attempts' => 2,
                    'warning_codes' => [],
                ], JSON_THROW_ON_ERROR));
            }
        });

        $result = $client->writeSimpleProductWithContext(
            $this->context(),
            77,
            new AdobeSafeSyncSimpleProductWriteRequest(expectedSku: 'SKU-77'),
        );

        $this->assertSame('unknown_or_ambiguous', $result->appliedStateKnowledge->value);
        $this->assertSame('safe_sync_bridge_response_ambiguous', $result->reasonCode);
        $this->assertFalse($result->postconditionVerified);
        $this->assertSame(1, $result->consequentialWriteAttempts);
        $this->assertSame([], $result->warningCodes);
    }

    #[Test]
    public function write_timeout_is_unknown_or_ambiguous_with_single_send_attempt_and_no_secret_leakage(): void
    {
        $transport = new class implements ConnectorHttpTransport
        {
            public int $sendCount = 0;

            public function send(#[\SensitiveParameter] ConnectorOutboundRequest $request): ConnectorHttpResult
            {
                $this->sendCount++;

                throw new ConnectorTransportException(TransportFailureReason::Timeout);
            }
        };

        $client = $this->clientWithTransport($transport);
        $result = $client->writeSimpleProductWithContext(
            $this->context(),
            77,
            new AdobeSafeSyncSimpleProductWriteRequest(expectedSku: 'SKU-77'),
        );

        $this->assertSame('unknown_or_ambiguous', $result->appliedStateKnowledge->value);
        $this->assertSame('safe_sync_transport_ambiguous', $result->reasonCode);
        $this->assertSame(1, $result->consequentialWriteAttempts);
        $this->assertSame(1, $transport->sendCount);
    }

    #[Test]
    public function write_connection_reset_is_unknown_or_ambiguous_with_single_send_attempt(): void
    {
        $transport = new class implements ConnectorHttpTransport
        {
            public int $sendCount = 0;

            public function send(#[\SensitiveParameter] ConnectorOutboundRequest $request): ConnectorHttpResult
            {
                $this->sendCount++;

                throw new ConnectorTransportException(TransportFailureReason::ConnectionFailed);
            }
        };

        $client = $this->clientWithTransport($transport);
        $result = $client->writeSimpleProductWithContext(
            $this->context(),
            77,
            new AdobeSafeSyncSimpleProductWriteRequest(expectedSku: 'SKU-77'),
        );

        $this->assertSame('unknown_or_ambiguous', $result->appliedStateKnowledge->value);
        $this->assertSame('safe_sync_transport_ambiguous', $result->reasonCode);
        $this->assertSame(1, $result->consequentialWriteAttempts);
        $this->assertSame(1, $transport->sendCount);
    }

    #[Test]
    public function non_success_write_http_response_is_unknown_or_ambiguous_after_submission(): void
    {
        $transport = new class implements ConnectorHttpTransport
        {
            public int $sendCount = 0;

            public function send(#[\SensitiveParameter] ConnectorOutboundRequest $request): ConnectorHttpResult
            {
                $this->sendCount++;

                return new ConnectorHttpResult(409, [], json_encode([
                    'message' => 'safe_sync_ambiguous_sku',
                ], JSON_THROW_ON_ERROR));
            }
        };

        $client = $this->clientWithTransport($transport);
        $result = $client->writeSimpleProductWithContext(
            $this->context(),
            77,
            new AdobeSafeSyncSimpleProductWriteRequest(expectedSku: 'SKU-77'),
        );

        $this->assertSame('unknown_or_ambiguous', $result->appliedStateKnowledge->value);
        $this->assertSame('safe_sync_bridge_response_ambiguous', $result->reasonCode);
        $this->assertSame(1, $result->consequentialWriteAttempts);
        $this->assertSame(1, $transport->sendCount);
    }

    #[Test]
    public function known_applied_write_response_must_prove_postcondition_and_single_attempt(): void
    {
        $client = $this->clientWithTransport(new class implements ConnectorHttpTransport
        {
            public function send(#[\SensitiveParameter] ConnectorOutboundRequest $request): ConnectorHttpResult
            {
                return new ConnectorHttpResult(200, [], json_encode([
                    'applied_state' => 'known_applied',
                    'reason_code' => 'safe_sync_simple_product_write_applied',
                    'logical_entity_id' => 77,
                    'sku' => 'SKU-77',
                    'postcondition_verified' => false,
                    'consequential_write_attempts' => 0,
                    'warning_codes' => [],
                ], JSON_THROW_ON_ERROR));
            }
        });

        $result = $client->writeSimpleProductWithContext(
            $this->context(),
            77,
            new AdobeSafeSyncSimpleProductWriteRequest(expectedSku: 'SKU-77'),
        );

        $this->assertSame('unknown_or_ambiguous', $result->appliedStateKnowledge->value);
        $this->assertSame('safe_sync_bridge_response_ambiguous', $result->reasonCode);
        $this->assertSame(1, $result->consequentialWriteAttempts);
    }

    #[Test]
    public function public_context_taking_methods_are_marked_sensitive(): void
    {
        $methods = [
            [AdobeSafeSyncClient::class, 'handshakeWithContext', 0],
            [AdobeSafeSyncClient::class, 'readProductWithContext', 0],
            [AdobeSafeSyncClient::class, 'writeSimpleProductWithContext', 0],
        ];

        foreach ($methods as [$class, $method, $index]) {
            $reflection = new \ReflectionMethod($class, $method);
            $parameter = $reflection->getParameters()[$index];

            $this->assertNotEmpty(
                $parameter->getAttributes(\SensitiveParameter::class),
                "{$class}::{$method} parameter {$parameter->getName()} must carry #[\\SensitiveParameter]",
            );
        }
    }

    private function clientWithTransport(ConnectorHttpTransport $transport): AdobeSafeSyncClient
    {
        return new AdobeSafeSyncClient(
            new AdobePaaSRequestContextFactory,
            new AdobeSafeSyncRequestFactory(new OAuth1RequestSigner),
            $transport,
        );
    }

    private function context(): AdobePaaSRequestContext
    {
        return new AdobePaaSRequestContext(
            baseUrl: 'https://shop.example.com',
            storeCode: 'default',
            credentials: new OAuth1Credentials('ck_test', 'cs_test', 'at_test', 'ts_test'),
        );
    }
}
