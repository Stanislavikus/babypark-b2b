<?php

namespace Tests\Concerns;

use App\Enums\ConnectorAccountConnectionStatus;
use App\Enums\UserRole;
use App\Models\ConnectorAccount;
use App\Models\ConnectorDefinition;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Connectors\AdobePaaS\AdobePaaSCredentialMapper;
use App\Support\Connectors\OAuth1\OAuth1Credentials;
use Illuminate\Support\Str;

trait CreatesConnectorAccountFixtures
{
    protected function defaultWorkspace(): Workspace
    {
        return Workspace::query()->where('is_default', true)->sole();
    }

    protected function adobeConnectorDefinition(): ConnectorDefinition
    {
        return ConnectorDefinition::query()->where('code', 'adobe_commerce')->firstOrFail();
    }

    protected function createStaffUser(UserRole $role): User
    {
        return User::query()->create([
            'name' => 'User '.$role->value,
            'email' => $role->value.'-'.Str::random(6).'@babypark.ua',
            'password' => 'password',
            'role' => $role,
            'is_active' => true,
        ]);
    }

    protected function createConnectorAccount(
        ?Workspace $workspace = null,
        array $overrides = [],
    ): ConnectorAccount {
        $workspace ??= $this->defaultWorkspace();

        return ConnectorAccount::withoutWorkspaceScope()->create(array_merge([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'connector_definition_id' => $this->adobeConnectorDefinition()->id,
            'name' => 'Account '.Str::random(6),
            'auth_profile' => 'adobe_commerce_paas_oauth1_integration',
            'base_url' => 'https://shop.example.com',
            'store_code' => 'default',
            'tenant_context' => null,
            'is_enabled' => true,
            'settings' => [],
            'credentials' => AdobePaaSCredentialMapper::toStorageArray(
                new OAuth1Credentials('ck_live', 'cs_live', 'at_live', 'ts_live'),
            ),
            'connection_status' => ConnectorAccountConnectionStatus::Untested,
        ], $overrides));
    }
}
