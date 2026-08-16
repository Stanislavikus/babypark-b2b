<?php

namespace Tests\Concerns;

use App\Enums\ConnectorAccountConnectionStatus;
use App\Enums\UserRole;
use App\Models\ConnectorAccount;
use App\Models\ConnectorDefinition;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use App\Support\Connectors\AdobePaaS\AdobePaaSCredentialMapper;
use App\Support\Connectors\OAuth1\OAuth1Credentials;
use App\Support\Workspace\WorkspacePermissions;
use Illuminate\Support\Str;

trait CreatesConnectorAccountFixtures
{
    use InteractsWithWorkspaceRbac;

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

    protected function grantConnectorManage(Workspace $workspace, User $user): void
    {
        $membership = $this->makeWorkspaceMembership($workspace, $user, true);
        $role = $this->createRoleWithPermissions(
            $workspace->id,
            'Connector Manager '.$user->id,
            [
                WorkspacePermissions::VIEW_CONNECTOR_ACCOUNTS,
                WorkspacePermissions::RUN_CONNECTOR_DISCOVERY,
                WorkspacePermissions::MANAGE_CONNECTOR_ACCOUNTS,
            ],
        );
        $this->assignRoleToMembership($membership, $role);
    }

    protected function grantConnectorView(Workspace $workspace, User $user): void
    {
        $membership = $this->makeWorkspaceMembership($workspace, $user, true);
        $role = $this->createRoleWithPermissions(
            $workspace->id,
            'Connector Viewer '.$user->id,
            [WorkspacePermissions::VIEW_CONNECTOR_ACCOUNTS],
        );
        $this->assignRoleToMembership($membership, $role);
    }

    protected function grantConnectorDiscovery(Workspace $workspace, User $user): void
    {
        $membership = $this->makeWorkspaceMembership($workspace, $user, true);
        $role = $this->createRoleWithPermissions(
            $workspace->id,
            'Connector Discovery '.$user->id,
            [
                WorkspacePermissions::VIEW_CONNECTOR_ACCOUNTS,
                WorkspacePermissions::RUN_CONNECTOR_DISCOVERY,
            ],
        );
        $this->assignRoleToMembership($membership, $role);
    }

    protected function grantSyncMappingsView(Workspace $workspace, User $user): void
    {
        $membership = WorkspaceUser::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $user->id)
            ->first();

        if ($membership === null) {
            $membership = $this->makeWorkspaceMembership($workspace, $user, true);
        }

        $role = $this->createRoleWithPermissions(
            $workspace->id,
            'Sync Mappings Viewer '.$user->id,
            [WorkspacePermissions::VIEW_SYNC_MAPPINGS],
        );
        $this->assignRoleToMembership($membership, $role);
    }

    protected function grantSyncMappingsManage(Workspace $workspace, User $user): void
    {
        $membership = WorkspaceUser::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $user->id)
            ->first();

        if ($membership === null) {
            $membership = $this->makeWorkspaceMembership($workspace, $user, true);
        }

        $role = $this->createRoleWithPermissions(
            $workspace->id,
            'Sync Mappings Manager '.$user->id,
            [WorkspacePermissions::MANAGE_SYNC_MAPPINGS],
        );
        $this->assignRoleToMembership($membership, $role);
    }

    protected function createStaffUserWithConnectorManage(UserRole $role): User
    {
        $user = $this->createStaffUser($role);
        $this->grantConnectorManage($this->defaultWorkspace(), $user);

        return $user;
    }
}
