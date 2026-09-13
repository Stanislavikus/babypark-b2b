<?php

namespace App\Services\Connectors;

use App\Enums\ExternalRecordLinkTrustOrigin;
use App\Enums\FieldObjectType;
use App\Models\ConnectorAccount;
use App\Models\ExternalRecordLink;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductRemoteGetClassification;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductRemoteStateClient;

final class AdobeProductAttributeEntityEvidenceResolver
{
    public function __construct(
        private readonly AdobeProductRemoteStateClient $remoteStateClient,
    ) {}

    /** @return array<string, list<FieldObjectType>> */
    public function resolve(ConnectorAccount $account): array
    {
        $evidence = [];
        $links = ExternalRecordLink::withoutWorkspaceScope()
            ->where('workspace_id', $account->workspace_id)
            ->where('connector_account_id', $account->id)
            ->where('trust_origin', ExternalRecordLinkTrustOrigin::MerchantConfirmed->value)
            ->get();

        foreach ($links as $link) {
            if (! $link->hasMerchantConfirmedTrust()) {
                continue;
            }

            $sku = $link->external_identifier;
            if (! is_string($sku) || $sku === '' || trim($sku) !== $sku) {
                throw new \RuntimeException('Trusted Adobe product link has an invalid external identifier.');
            }

            $result = $this->remoteStateClient->getProduct($account->workspace_id, $account->id, $sku);
            if ($result->classification !== AdobeProductRemoteGetClassification::Found || $result->observedState === null) {
                throw new \RuntimeException('Trusted Adobe product evidence could not be read.');
            }

            $objectType = $link->product_variant_id !== null
                ? FieldObjectType::ProductVariant
                : FieldObjectType::Product;

            foreach (array_keys($result->observedState->customAttributes) as $externalFieldKey) {
                if (! is_string($externalFieldKey) || $externalFieldKey === '') {
                    continue;
                }

                $evidence[$externalFieldKey][$objectType->value] = $objectType;
            }
        }

        ksort($evidence, SORT_STRING);

        return array_map(static function (array $types): array {
            ksort($types, SORT_STRING);

            return array_values($types);
        }, $evidence);
    }
}
