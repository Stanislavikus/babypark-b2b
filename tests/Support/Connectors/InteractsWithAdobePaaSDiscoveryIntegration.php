<?php

namespace Tests\Support\Connectors;

use App\Enums\ConnectorDiscoveryRunStatus;
use App\Models\ConnectorAccount;
use App\Models\ConnectorDiscoveryRun;
use App\Models\ConnectorSchemaSnapshot;
use App\Services\Connectors\ConnectorDiscoveryRunPersistence;
use App\Services\Connectors\ConnectorDiscoverySourceResolver;
use App\Support\Connectors\AdobePaaS\AdobePaaSAttributeNormalizer;
use App\Support\Connectors\AdobePaaS\AdobePaaSDiscoveryCapabilityImpl;
use App\Support\Connectors\AdobePaaS\AdobePaaSDiscoveryRequestFactory;
use App\Support\Connectors\AdobePaaS\AdobePaaSDiscoveryResponseMapper;
use App\Support\Connectors\AdobePaaS\AdobePaaSDiscoveryTransportMapper;
use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContext;
use App\Support\Connectors\AdobePaaS\AdobePaaSServiceOnlyAttributeEligibility;
use App\Support\Connectors\CanonicalSchemaFieldHasher;
use App\Support\Connectors\CanonicalSchemaSnapshotHasher;
use App\Support\Connectors\ConnectorDiscoveryAttemptResult;
use App\Support\Connectors\ConnectorSchemaSourceEndpointPathValidator;
use App\Support\Connectors\OAuth1\OAuth1Credentials;
use App\Support\Connectors\OAuth1\OAuth1RequestSigner;

trait InteractsWithAdobePaaSDiscoveryIntegration
{
    private const DISCOVERY_ENDPOINT_PATH = '/V1/products/attributes';

    private function discoverWithTransport(RecordingConnectorHttpTransport $transport): ConnectorDiscoveryAttemptResult
    {
        return $this->capabilityWithTransport($transport)->discover($this->sampleContext(), self::DISCOVERY_ENDPOINT_PATH);
    }

    private function publishCandidate(
        ConnectorAccount $account,
        ConnectorDiscoveryAttemptResult $result,
    ): string {
        $row = $this->createQueuedRow($account);

        return $this->publishCandidateForRow($account, $row, $result);
    }

    private function publishCandidateForRow(
        ConnectorAccount $account,
        ConnectorDiscoveryRun $row,
        ConnectorDiscoveryAttemptResult $result,
    ): string {
        app(ConnectorDiscoveryRunPersistence::class)->finalizeAfterVendorAttempt(
            $account->workspace_id,
            $account->id,
            $row->id,
            $result,
            10,
            now()->addHour(),
        );

        $snapshot = ConnectorSchemaSnapshot::withoutWorkspaceScope()
            ->whereKey($row->fresh()->snapshot_id)
            ->firstOrFail();

        return $snapshot->canonical_hash;
    }

    private function createQueuedRow(ConnectorAccount $account): ConnectorDiscoveryRun
    {
        $source = app(ConnectorDiscoverySourceResolver::class)->resolve($account);

        return ConnectorDiscoveryRun::withoutWorkspaceScope()->create([
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'connector_schema_source_id' => $source->id,
            'trigger' => 'manual',
            'status' => ConnectorDiscoveryRunStatus::Running,
            'execution_attempts' => 1,
            'retry_until_at' => now()->addHour(),
            'next_attempt_at' => null,
            'started_at' => now(),
        ]);
    }

    private function capabilityWithTransport(RecordingConnectorHttpTransport $transport): AdobePaaSDiscoveryCapabilityImpl
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
            new AdobePaaSServiceOnlyAttributeEligibility,
            new CanonicalSchemaFieldHasher,
            new CanonicalSchemaSnapshotHasher,
        );
    }

    private function sampleContext(): AdobePaaSRequestContext
    {
        return new AdobePaaSRequestContext(
            baseUrl: 'https://shop.example.com',
            storeCode: 'default',
            credentials: new OAuth1Credentials('ck_test', 'cs_test', 'at_test', 'ts_test'),
        );
    }

    private function attribute(string $code, string $frontendInput = 'text'): \stdClass
    {
        return json_decode(
            sprintf('{"attribute_code":"%s","frontend_input":"%s","scope":"global"}', $code, $frontendInput),
            associative: false,
            depth: 512,
            flags: JSON_THROW_ON_ERROR,
        );
    }

    private function extractOAuthNonce(string $authorizationHeader): string
    {
        if (preg_match('/oauth_nonce="([^"]+)"/', $authorizationHeader, $matches) !== 1) {
            $this->fail('Authorization header missing oauth_nonce.');
        }

        return $matches[1];
    }
}
