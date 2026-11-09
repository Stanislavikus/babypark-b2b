<?php

namespace App\Support\Connectors\AdobePaaS\RemoteCatalog;

use App\Models\ConnectorAccount;
use Illuminate\Support\Facades\DB;

final class AdobeRemoteCatalogBrandProjectionResolver
{
    public function resolve(ConnectorAccount $account): ?AdobeRemoteCatalogBrandProjectionContext
    {
        $fieldKeys = DB::table('field_mappings as fm')
            ->join('sync_configurations as sc', 'sc.id', '=', 'fm.sync_configuration_id')
            ->join('field_bindings as fb', 'fb.id', '=', 'fm.field_binding_id')
            ->join('field_definitions as fd', 'fd.id', '=', 'fb.field_definition_id')
            ->where('fm.workspace_id', $account->workspace_id)
            ->where('sc.workspace_id', $account->workspace_id)
            ->where('sc.connector_account_id', $account->id)
            ->where('fd.code', 'brand')
            ->whereNotNull('fm.external_field_key')
            ->distinct()
            ->orderBy('fm.external_field_key')
            ->pluck('fm.external_field_key')
            ->map(static fn (mixed $value): string => trim((string) $value))
            ->filter()
            ->values();

        if ($fieldKeys->count() > 1) {
            return null;
        }

        $fieldKey = $fieldKeys->count() === 1
            ? $fieldKeys->sole()
            : $this->providerManufacturerFieldKey($account);

        if ($fieldKey === null) {
            return null;
        }
        $rows = DB::table('adobe_product_attribute_lineages as lineage')
            ->join(
                'adobe_product_attribute_option_lineages as option_lineage',
                'option_lineage.adobe_product_attribute_lineage_id',
                '=',
                'lineage.id',
            )
            ->where('lineage.workspace_id', $account->workspace_id)
            ->where('lineage.connector_account_id', $account->id)
            ->where('lineage.last_external_field_key', $fieldKey)
            ->whereNull('lineage.missing_since')
            ->whereNull('option_lineage.missing_since')
            ->get(['option_lineage.provider_option_id', 'option_lineage.default_label']);

        $optionLabels = [];
        $conflicts = [];

        foreach ($rows as $row) {
            $optionId = trim((string) $row->provider_option_id);
            $label = trim((string) ($row->default_label ?? ''));

            if ($optionId === '' || $label === '' || isset($conflicts[$optionId])) {
                continue;
            }

            if (isset($optionLabels[$optionId]) && $optionLabels[$optionId] !== $label) {
                unset($optionLabels[$optionId]);
                $conflicts[$optionId] = true;

                continue;
            }

            $optionLabels[$optionId] = $label;
        }

        return new AdobeRemoteCatalogBrandProjectionContext($fieldKey, $optionLabels);
    }

    private function providerManufacturerFieldKey(ConnectorAccount $account): ?string
    {
        $exists = DB::table('adobe_product_attribute_lineages')
            ->where('workspace_id', $account->workspace_id)
            ->where('connector_account_id', $account->id)
            ->where('current_external_field_key', 'manufacturer')
            ->exists();

        return $exists ? 'manufacturer' : null;
    }
}
