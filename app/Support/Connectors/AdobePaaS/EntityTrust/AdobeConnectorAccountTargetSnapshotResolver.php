<?php

namespace App\Support\Connectors\AdobePaaS\EntityTrust;

use App\Models\ConnectorAccount;

final class AdobeConnectorAccountTargetSnapshotResolver
{
    public function resolve(ConnectorAccount $account): AdobeConnectorAccountTargetSnapshot
    {
        return new AdobeConnectorAccountTargetSnapshot(
            baseUrl: (string) $account->base_url,
            storeCode: (string) $account->store_code,
        );
    }

    public function wouldChangeTarget(
        ConnectorAccount $account,
        string $baseUrl,
        string $storeCode,
    ): bool {
        $current = $this->resolve($account);

        return ! $current->equals(new AdobeConnectorAccountTargetSnapshot($baseUrl, $storeCode));
    }
}
