<?php

namespace App\Support\Connectors\AdobePaaS\Validation;

use App\Models\ConnectorAccount;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Str;

final class AdobeStage3EValidationGuard
{
    public function evaluate(
        ConnectorAccount $account,
        string $expectHost,
        bool $executeRealWritesRequested,
    ): AdobeStage3EValidationGuardResult {
        $failures = [];

        if (! AdobeStage3EValidationEnvironment::isActive()) {
            $failures[] = 'environment_not_stage3e_validation';
        }

        if ($account->trashed()) {
            $failures[] = 'connector_account_not_found';
        }

        $configuredAllowHost = (string) config('adobe_stage3e_validation.allow_host', '');
        if ($configuredAllowHost === '') {
            $failures[] = 'validation_allow_host_not_configured';
        }

        $accountHost = $this->extractHost((string) $account->base_url);
        if ($accountHost === null) {
            $failures[] = 'connector_account_base_url_invalid';
        }

        $normalizedExpectHost = $this->normalizeHost($expectHost);
        if ($normalizedExpectHost === '') {
            $failures[] = 'expect_host_missing';
        }

        if ($accountHost !== null && $normalizedExpectHost !== '' && $accountHost !== $normalizedExpectHost) {
            $failures[] = 'expect_host_mismatch';
        }

        if ($configuredAllowHost !== '' && $accountHost !== null
            && $this->normalizeHost($configuredAllowHost) !== $accountHost
        ) {
            $failures[] = 'allow_host_mismatch';
        }

        if (! Str::startsWith(strtolower((string) $account->base_url), 'https://')) {
            $failures[] = 'base_url_not_https';
        }

        if ($this->workspaceHasOrdinaryMerchantProducts($account->workspace_id)) {
            $failures[] = 'workspace_contains_ordinary_merchant_products';
        }

        if ($this->workspaceHasNonValidationSkus($account->workspace_id)) {
            $failures[] = 'workspace_contains_non_validation_skus';
        }

        if ($executeRealWritesRequested) {
            $failures[] = 'real_writes_not_authorized_in_this_release';
        }

        if ($failures !== []) {
            return AdobeStage3EValidationGuardResult::fail($failures);
        }

        return AdobeStage3EValidationGuardResult::pass();
    }

    private function workspaceHasOrdinaryMerchantProducts(string $workspaceId): bool
    {
        $prefix = (string) config('adobe_stage3e_validation.sku_prefix', 'B2BVAL-');

        return Product::query()
            ->where('workspace_id', $workspaceId)
            ->whereDoesntHave('variants', function ($query) use ($prefix): void {
                $query->where('sku', 'like', $prefix.'%');
            })
            ->exists();
    }

    private function workspaceHasNonValidationSkus(string $workspaceId): bool
    {
        $prefix = (string) config('adobe_stage3e_validation.sku_prefix', 'B2BVAL-');

        return ProductVariant::query()
            ->where('workspace_id', $workspaceId)
            ->where(function ($query) use ($prefix): void {
                $query->whereNull('sku')
                    ->orWhere('sku', 'not like', $prefix.'%');
            })
            ->exists();
    }

    private function extractHost(string $baseUrl): ?string
    {
        $host = parse_url($baseUrl, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return null;
        }

        return $this->normalizeHost($host);
    }

    private function normalizeHost(string $host): string
    {
        return strtolower(trim($host));
    }
}
