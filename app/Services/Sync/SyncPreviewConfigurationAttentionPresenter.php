<?php

namespace App\Services\Sync;

use App\Enums\SyncPreviewFindingCode;
use App\Enums\SyncPreviewRemediationArea;
use App\Models\SyncConfiguration;
use App\Models\SyncRun;
use App\Models\SyncRunItem;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Sync\Preview\Presentation\SyncPreviewFindingPresenter;
use App\Support\Sync\Preview\Presentation\SyncPreviewRemediationDestinationPresentation;
use Illuminate\Support\Collection;

final class SyncPreviewConfigurationAttentionPresenter
{
    public function __construct(
        private readonly SyncPreviewPresentationContextLoader $contextLoader,
        private readonly SyncPreviewFindingPresenter $findingPresenter,
    ) {}

    /**
     * @param  Collection<int, SyncRunItem>  $items
     * @return list<array<string, mixed>>
     */
    public function presentRows(
        SyncRun $run,
        ?SyncConfiguration $configuration,
        string $accountId,
        User $actor,
        Workspace $workspace,
        Collection $items,
    ): array {
        if ($items->isEmpty()) {
            return [];
        }

        $context = $this->contextLoader->loadForRun(
            $actor,
            $workspace,
            $accountId,
            $configuration,
            $run,
            $items,
        );

        /** @var array<string, array{finding: array<string, mixed>, product_ids: array<string, true>}> $groups */
        $groups = [];

        foreach ($items as $item) {
            foreach ($item->findings ?? [] as $finding) {
                if (! is_array($finding)) {
                    continue;
                }

                $groupKey = $this->groupKey($finding);

                if ($groupKey === null) {
                    continue;
                }

                $groups[$groupKey] ??= [
                    'finding' => $finding,
                    'product_ids' => [],
                ];
                $groups[$groupKey]['product_ids'][(string) $item->product_id] = true;
            }
        }

        $rows = [];

        foreach ($groups as $group) {
            $productIds = array_keys($group['product_ids']);
            $productId = $productIds[0] ?? '';
            $presentation = $this->findingPresenter->present($group['finding'], $context, $productId);

            $rows[] = [
                'summary' => $presentation->summary,
                'field_context' => $presentation->fieldContext,
                'affected_products_count' => count($productIds),
                'destinations' => array_values(array_map(
                    static fn (SyncPreviewRemediationDestinationPresentation $destination): array => $destination->toArray(),
                    array_filter(
                        $presentation->destinations,
                        static fn (SyncPreviewRemediationDestinationPresentation $destination): bool => in_array(
                            $destination->area,
                            [
                                SyncPreviewRemediationArea::FieldMapping,
                                SyncPreviewRemediationArea::OptionMapping,
                                SyncPreviewRemediationArea::ConnectorSetup,
                            ],
                            true,
                        ),
                    ),
                )),
            ];
        }

        usort($rows, static fn (array $left, array $right): int => strcmp(
            (string) ($left['summary'] ?? ''),
            (string) ($right['summary'] ?? ''),
        ));

        return $rows;
    }

    /**
     * @param  Collection<int, SyncRunItem>  $items
     */
    public function affectedProductCount(Collection $items): int
    {
        $productIds = [];

        foreach ($items as $item) {
            foreach ($item->findings ?? [] as $finding) {
                if (is_array($finding) && $this->isConfigurationOwned($finding)) {
                    $productIds[(string) $item->product_id] = true;
                    break;
                }
            }
        }

        return count($productIds);
    }

    /**
     * @param  array<string, mixed>  $finding
     */
    public function isConfigurationOwned(array $finding): bool
    {
        return $this->groupKey($finding) !== null;
    }

    /**
     * @param  array<string, mixed>  $finding
     */
    private function groupKey(array $finding): ?string
    {
        $codeValue = $finding['code'] ?? null;
        $code = is_string($codeValue) ? SyncPreviewFindingCode::tryFrom($codeValue) : null;

        if ($code === null) {
            return null;
        }

        $subject = $this->scalarIdentity($finding['subject'] ?? null);
        $context = is_array($finding['context'] ?? null) ? $finding['context'] : [];
        $identity = match ($code) {
            SyncPreviewFindingCode::MissingRequiredFieldMapping => [
                'code' => $code->value,
                'subject' => $subject,
            ],
            SyncPreviewFindingCode::MissingOptionMapping => [
                'code' => $code->value,
                'field_binding_id' => $subject,
                'internal_option_key' => $this->scalarIdentity($context['internal_option_key'] ?? null),
            ],
            SyncPreviewFindingCode::ExternalOptionMissingOrStale => [
                'code' => $code->value,
                'field_binding_id' => $subject,
                'external_field_key' => $this->scalarIdentity($context['external_field_key'] ?? null),
                'external_option_value' => $this->scalarIdentity($context['external_option_value'] ?? null),
            ],
            SyncPreviewFindingCode::AttributeSetUnconfigured => [
                'code' => $code->value,
            ],
            SyncPreviewFindingCode::AttributeSetInvalid => [
                'code' => $code->value,
                'subject' => $subject,
            ],
            SyncPreviewFindingCode::MappedFieldAbsentFromSelectedSet,
            SyncPreviewFindingCode::RoutingFieldMappingNotSupported,
            SyncPreviewFindingCode::InvalidConfigurableAttribute => [
                'code' => $code->value,
                'external_field_key' => $subject,
                'field_binding_id' => $this->scalarIdentity($context['field_binding_id'] ?? null),
            ],
            default => null,
        };

        if ($identity === null || ! $this->hasCompleteIdentity($code, $identity)) {
            return null;
        }

        return json_encode($identity, JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, ?string>  $identity
     */
    private function hasCompleteIdentity(SyncPreviewFindingCode $code, array $identity): bool
    {
        return match ($code) {
            SyncPreviewFindingCode::MissingRequiredFieldMapping => $identity['subject'] !== null,
            SyncPreviewFindingCode::MissingOptionMapping => $identity['field_binding_id'] !== null
                && $identity['internal_option_key'] !== null,
            SyncPreviewFindingCode::ExternalOptionMissingOrStale => $identity['field_binding_id'] !== null
                && $identity['external_field_key'] !== null
                && $identity['external_option_value'] !== null,
            SyncPreviewFindingCode::MappedFieldAbsentFromSelectedSet,
            SyncPreviewFindingCode::RoutingFieldMappingNotSupported,
            SyncPreviewFindingCode::InvalidConfigurableAttribute => $identity['external_field_key'] !== null
                && $identity['field_binding_id'] !== null,
            SyncPreviewFindingCode::AttributeSetUnconfigured,
            SyncPreviewFindingCode::AttributeSetInvalid => true,
            default => false,
        };
    }

    private function scalarIdentity(mixed $value): ?string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }

        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }

        return null;
    }
}
