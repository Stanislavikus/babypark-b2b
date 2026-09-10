<?php

namespace Tests\Unit\Connectors\AdobePaaS;

use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContextFactory;
use App\Support\Connectors\AdobePaaS\AdobeStoreConfigReader;
use App\Support\Connectors\AdobePaaS\Exceptions\AdobeStoreConfigReadException;
use App\Support\Connectors\OAuth1\OAuth1RequestSigner;
use App\Support\Connectors\Transport\ConnectorHttpResult;
use App\Support\Connectors\Transport\ConnectorHttpTransport;
use App\Support\Connectors\Transport\ConnectorOutboundRequest;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\Support\Connectors\RecordingConnectorHttpTransport;
use Tests\TestCase;

class AdobeStoreConfigReaderTest extends TestCase
{
    use CreatesConnectorAccountFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceSeeder::class);
        $this->seed(ConnectorFoundationSeeder::class);
    }

    #[Test]
    public function it_requests_exact_configured_store_code_filter(): void
    {
        $account = $this->createConnectorAccount(null, ['store_code' => 'default']);
        $capturedUri = null;

        $reader = $this->readerWithTransport(new RecordingConnectorHttpTransport(function (ConnectorOutboundRequest $request) use (&$capturedUri): ConnectorHttpResult {
            $capturedUri = (string) $request->request->getUri();

            return new ConnectorHttpResult(200, [], json_encode([
                [
                    'code' => 'default',
                    'base_currency_code' => 'UAH',
                ],
            ], JSON_THROW_ON_ERROR));
        }));

        $currency = $reader->readBaseCurrency($account->workspace_id, $account->id);

        $this->assertSame('UAH', $currency);
        $this->assertNotNull($capturedUri);
        $this->assertStringContainsString('/V1/store/storeConfigs', $capturedUri);
        $this->assertStringContainsString('storeCodes%5B%5D=default', $capturedUri);
    }

    #[Test]
    public function it_rejects_missing_base_currency_code(): void
    {
        $account = $this->createConnectorAccount();

        $reader = $this->readerWithTransport(new RecordingConnectorHttpTransport(
            fn (): ConnectorHttpResult => new ConnectorHttpResult(200, [], json_encode([
                ['code' => 'default'],
            ], JSON_THROW_ON_ERROR)),
        ));

        $this->expectException(AdobeStoreConfigReadException::class);
        $reader->readBaseCurrency($account->workspace_id, $account->id);
    }

    #[Test]
    public function it_rejects_multiple_or_wrong_store_rows(): void
    {
        $account = $this->createConnectorAccount();

        $reader = $this->readerWithTransport(new RecordingConnectorHttpTransport(
            fn (): ConnectorHttpResult => new ConnectorHttpResult(200, [], json_encode([
                ['code' => 'other', 'base_currency_code' => 'USD'],
                ['code' => 'default', 'base_currency_code' => 'UAH'],
            ], JSON_THROW_ON_ERROR)),
        ));

        $this->expectException(AdobeStoreConfigReadException::class);
        $reader->readBaseCurrency($account->workspace_id, $account->id);
    }

    #[Test]
    public function transport_failure_is_fail_closed(): void
    {
        $account = $this->createConnectorAccount();

        $reader = $this->readerWithTransport(new RecordingConnectorHttpTransport(
            fn (): ConnectorHttpResult => new ConnectorHttpResult(503, [], '{"message":"down"}'),
        ));

        $this->expectException(AdobeStoreConfigReadException::class);
        $reader->readBaseCurrency($account->workspace_id, $account->id);
    }

    private function readerWithTransport(RecordingConnectorHttpTransport $transport): AdobeStoreConfigReader
    {
        $this->app->instance(ConnectorHttpTransport::class, $transport);

        return new AdobeStoreConfigReader(
            app(AdobePaaSRequestContextFactory::class),
            new OAuth1RequestSigner,
            $transport,
        );
    }
}
