<?php

namespace Tests\Concerns;

use App\Enums\ExternalRecordLinkTrustOrigin;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;

trait CreatesMerchantConfirmedExternalRecordLinks
{
    protected function createWorkspaceActor(Workspace $workspace): WorkspaceUser
    {
        $user = User::factory()->create(['customer_id' => null]);

        return WorkspaceUser::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'is_active' => true,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function merchantConfirmedVariantLinkAttributes(
        Workspace $workspace,
        string $connectorAccountId,
        ProductVariant $variant,
        string $externalIdentifier,
        string $discriminator,
        ?WorkspaceUser $actor = null,
    ): array {
        $actor ??= $this->createWorkspaceActor($workspace);

        return [
            'workspace_id' => $workspace->id,
            'connector_account_id' => $connectorAccountId,
            'product_variant_id' => $variant->id,
            'external_identifier' => $externalIdentifier,
            'trust_origin' => ExternalRecordLinkTrustOrigin::MerchantConfirmed->value,
            'external_record_discriminator' => $discriminator,
            'established_by_workspace_user_id' => $actor->id,
            'established_at' => now(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function merchantConfirmedParentLinkAttributes(
        Workspace $workspace,
        string $connectorAccountId,
        Product $product,
        string $externalIdentifier,
        string $discriminator,
        ?WorkspaceUser $actor = null,
    ): array {
        $actor ??= $this->createWorkspaceActor($workspace);

        return [
            'workspace_id' => $workspace->id,
            'connector_account_id' => $connectorAccountId,
            'product_id' => $product->id,
            'external_identifier' => $externalIdentifier,
            'trust_origin' => ExternalRecordLinkTrustOrigin::MerchantConfirmed->value,
            'external_record_discriminator' => $discriminator,
            'established_by_workspace_user_id' => $actor->id,
            'established_at' => now(),
        ];
    }
}
