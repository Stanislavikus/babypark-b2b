<?php

namespace Tests\Feature;

use App\Models\ConnectorAccount;
use App\Models\FieldDefinition;
use App\Models\Workspace;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class Laravel13UpgradeRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceSeeder::class);
        $this->seed(ConnectorFoundationSeeder::class);
    }

    #[Test]
    public function representative_models_generate_uuid_version_4_identifiers(): void
    {
        $models = [
            Workspace::query()->firstOrFail(),
            ConnectorAccount::factory()->create(),
            FieldDefinition::query()->firstOrFail(),
        ];

        foreach ($models as $model) {
            $uuid = (string) $model->getKey();
            $modelClass = $model::class;

            $this->assertTrue(
                Str::isUuid($uuid),
                "Expected valid UUID for {$modelClass}, got: {$uuid}"
            );

            $this->assertMatchesRegularExpression(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
                $uuid,
                "Expected UUIDv4 for {$modelClass}, got: {$uuid}"
            );
        }
    }

    #[Test]
    public function connector_account_credentials_decrypt_after_app_key_rotation_with_previous_keys(): void
    {
        $oldKey = 'base64:'.base64_encode(random_bytes(32));
        $newKey = 'base64:'.base64_encode(random_bytes(32));
        $credentials = ['client_secret' => 'rotation-test-secret'];

        config(['app.key' => $oldKey, 'app.previous_keys' => []]);
        $this->resetApplicationEncrypter();

        $account = ConnectorAccount::factory()->create([
            'credentials' => $credentials,
        ]);

        $accountId = $account->id;

        config([
            'app.key' => $newKey,
            'app.previous_keys' => [$oldKey],
        ]);
        $this->resetApplicationEncrypter();

        $reloaded = ConnectorAccount::query()->findOrFail($accountId);

        $this->assertSame($credentials, $reloaded->credentials);
    }

    #[Test]
    public function connector_account_credentials_fail_to_decrypt_after_rotation_without_previous_key(): void
    {
        $oldKey = 'base64:'.base64_encode(random_bytes(32));
        $newKey = 'base64:'.base64_encode(random_bytes(32));

        config(['app.key' => $oldKey, 'app.previous_keys' => []]);
        $this->resetApplicationEncrypter();

        $account = ConnectorAccount::factory()->create([
            'credentials' => ['client_secret' => 'rotation-negative-control'],
        ]);

        $accountId = $account->id;

        config([
            'app.key' => $newKey,
            'app.previous_keys' => [],
        ]);
        $this->resetApplicationEncrypter();

        $this->expectException(DecryptException::class);

        ConnectorAccount::query()->findOrFail($accountId)->credentials;
    }

    #[Test]
    public function application_boots_and_caches_round_trip_after_upgrade(): void
    {
        Artisan::call('config:cache');
        Artisan::call('route:cache');
        Artisan::call('view:cache');

        $this->artisan('about')->assertExitCode(0);

        Artisan::call('optimize:clear');
    }

    private function resetApplicationEncrypter(): void
    {
        $this->app->forgetInstance('encrypter');
        $this->app->forgetInstance(Encrypter::class);
        Crypt::clearResolvedInstance('encrypter');
    }
}
