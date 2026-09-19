<?php

namespace App\Support\Connectors\AdobePaaS\Validation;

use App\Models\ConnectorAccount;
use App\Models\ExternalRecordLink;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\Connectors\AdobePaaS\AdobePaaSBaseUrl;
use App\Support\Workspace\WorkspaceScope;

final class AdobeStage3EValidationGuard
{
    public function evaluate(AdobeStage3EValidationRunInput $input): AdobeStage3EValidationGuardResult
    {
        $failures = [];

        if (! AdobeStage3EValidationEnvironment::isActive()) {
            $failures[] = 'environment_not_stage3e_validation';
        }

        $account = ConnectorAccount::withoutWorkspaceScope()->find($input->connectorAccountId);
        if (! $account instanceof ConnectorAccount) {
            return AdobeStage3EValidationGuardResult::fail(['connector_account_not_found']);
        }

        if (! $account->is_enabled) {
            $failures[] = 'connector_account_disabled';
        }

        if ($account->auth_profile !== 'adobe_commerce_paas_oauth1_integration') {
            $failures[] = 'connector_account_auth_profile_unsupported';
        }

        $normalizedAllowHost = $this->normalizeHost((string) config('adobe_stage3e_validation.allow_host', ''));
        if ($normalizedAllowHost === '') {
            $failures[] = 'validation_allow_host_not_configured';
        }

        $normalizedHost = $this->extractNormalizedHost((string) $account->base_url);
        if ($normalizedHost === null) {
            $failures[] = 'connector_account_base_url_invalid';
        }

        if (! is_string($account->base_url) || ! str_starts_with(strtolower($account->base_url), 'https://')) {
            $failures[] = 'connector_account_base_url_not_https';
        }

        $normalizedExpectHost = $this->normalizeHost($input->expectHost);
        if ($normalizedExpectHost === '') {
            $failures[] = 'expect_host_missing';
        }

        if ($normalizedHost !== null && $normalizedExpectHost !== '' && $normalizedHost !== $normalizedExpectHost) {
            $failures[] = 'expect_host_mismatch';
        }

        if ($normalizedHost !== null && $normalizedAllowHost !== '' && $normalizedHost !== $normalizedAllowHost) {
            $failures[] = 'allow_host_mismatch';
        }

        $storeCode = trim((string) $account->store_code);
        if ($storeCode === '') {
            $failures[] = 'store_code_missing';
        }

        if (strtolower($storeCode) === 'all') {
            $failures[] = 'store_code_all_forbidden';
        }

        $variant = ProductVariant::withoutWorkspaceScope()->find($input->productVariantId);
        if (! $variant instanceof ProductVariant) {
            return AdobeStage3EValidationGuardResult::fail(array_merge($failures, ['product_variant_not_found']));
        }

        if ($variant->workspace_id !== $account->workspace_id) {
            $failures[] = 'product_variant_workspace_mismatch';
        }

        if (! is_string($variant->sku) || $variant->sku === '') {
            $failures[] = 'product_variant_sku_missing';
        }

        $skuPrefix = (string) config('adobe_stage3e_validation.sku_prefix', 'B2BVAL-');
        if (! is_string($variant->sku) || ! str_starts_with($variant->sku, $skuPrefix)) {
            $failures[] = 'product_variant_sku_not_validation_prefixed';
        }

        if ($this->workspaceHasNonValidationVariants($account->workspace_id, $skuPrefix)) {
            $failures[] = 'workspace_contains_non_validation_variants';
        }

        if ($this->workspaceHasOrdinaryProducts($account->workspace_id, $skuPrefix)) {
            $failures[] = 'workspace_contains_ordinary_products';
        }

        $linksForVariant = ExternalRecordLink::withoutWorkspaceScope()
            ->where('product_variant_id', $variant->id)
            ->get();

        $matchingLinks = $linksForVariant
            ->where('workspace_id', $account->workspace_id)
            ->where('connector_account_id', $account->id)
            ->values();

        if ($matchingLinks->count() === 0) {
            $failures[] = $linksForVariant->isNotEmpty()
                ? 'trusted_external_record_link_workspace_or_account_mismatch'
                : 'trusted_external_record_link_missing';
        }

        if ($matchingLinks->count() > 1) {
            $failures[] = 'trusted_external_record_link_ambiguous';
        }

        $link = $matchingLinks->count() === 1 ? $matchingLinks->first() : null;

        if ($link instanceof ExternalRecordLink) {
            if (! $link->hasMerchantConfirmedTrust()) {
                $failures[] = 'trusted_external_record_link_untrusted_or_incomplete';
            }

            if ($link->external_identifier !== $variant->sku) {
                $failures[] = 'external_record_link_identifier_sku_mismatch';
            }
        }

        if ($input->executeRealWrites === false) {
            $failures[] = 'execute_real_writes_acknowledgement_missing';
        }

        if ($input->ackWriteSku === '') {
            $failures[] = 'ack_write_sku_missing';
        } elseif ($variant->sku !== null && $input->ackWriteSku !== $variant->sku) {
            $failures[] = 'ack_write_sku_mismatch';
        }

        $logicalEntityId = null;
        if ($link instanceof ExternalRecordLink) {
            $logicalEntityId = $this->parseLogicalEntityId((string) $link->external_record_discriminator);
            if ($logicalEntityId === null) {
                $failures[] = 'external_record_discriminator_invalid';
            }
        }

        if (
            $failures !== []
            || ! $link instanceof ExternalRecordLink
            || $normalizedHost === null
            || ! is_int($logicalEntityId)
        ) {
            return AdobeStage3EValidationGuardResult::fail($failures);
        }

        return AdobeStage3EValidationGuardResult::pass(new AdobeStage3EValidationResolvedSubject(
            account: $account,
            variant: $variant,
            trustedLink: $link,
            workspaceId: $account->workspace_id,
            normalizedHost: $normalizedHost,
            storeCode: $storeCode,
            sku: (string) $variant->sku,
            logicalEntityId: $logicalEntityId,
        ));
    }

    private function extractNormalizedHost(string $baseUrl): ?string
    {
        try {
            $parsed = AdobePaaSBaseUrl::parse($baseUrl);
        } catch (\Throwable) {
            return null;
        }

        $host = parse_url($parsed->value, PHP_URL_HOST);

        return is_string($host) && $host !== ''
            ? $this->normalizeHost($host)
            : null;
    }

    private function normalizeHost(string $host): string
    {
        return strtolower(trim($host));
    }

    private function parseLogicalEntityId(string $discriminator): ?int
    {
        if ($discriminator === '' || preg_match('/^[1-9][0-9]*$/', $discriminator) !== 1) {
            return null;
        }

        return (int) $discriminator;
    }

    private function workspaceHasNonValidationVariants(string $workspaceId, string $skuPrefix): bool
    {
        return ProductVariant::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->where(function ($query) use ($skuPrefix): void {
                $query->whereNull('sku')
                    ->orWhere('sku', 'not like', $skuPrefix.'%');
            })
            ->exists();
    }

    private function workspaceHasOrdinaryProducts(string $workspaceId, string $skuPrefix): bool
    {
        return Product::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->whereDoesntHave('variants', function ($query) use ($workspaceId, $skuPrefix): void {
                $query->withoutGlobalScope(WorkspaceScope::class)
                    ->where('workspace_id', $workspaceId)
                    ->whereNotNull('sku')
                    ->where('sku', 'like', $skuPrefix.'%');
            })
            ->exists();
    }
}
