<?php

namespace App\Services\Sync;

use App\Models\ConnectorAccount;
use App\Models\Product;
use App\Models\SyncConfiguration;
use App\Support\Sync\Exceptions\AdobeProductClassificationException;

final class ProductChannelReadinessReadService
{
    private const REVIEW_BLOCKERS = [
        'product_attribute_set_override_unavailable',
        'product_type_attribute_set_default_unavailable',
        'trusted_remote_attribute_set_conflict',
        'trusted_remote_attribute_set_unresolved',
        'trusted_remote_subject_ambiguous',
    ];

    public function __construct(
        private readonly ProductChannelSelectionService $channelSelectionService,
        private readonly AdobeProductClassificationReadService $classificationReadService,
    ) {}

    /**
     * @return list<array{
     *   label:string,
     *   platform:string,
     *   status_label:string,
     *   details:list<string>,
     *   classification_ready:?bool
     * }>
     */
    public function rows(Product $product): array
    {
        $product->loadMissing(
            'syncChannelSelections.syncConfiguration.connectorAccount.connectorDefinition',
        );

        $rows = [];

        foreach ($product->syncChannelSelections as $selection) {
            $configuration = $selection->syncConfiguration;

            if (! $configuration instanceof SyncConfiguration) {
                continue;
            }

            $account = $configuration->connectorAccount;

            if (! $account instanceof ConnectorAccount) {
                continue;
            }

            $definitionCode = $account->connectorDefinition?->code;
            $label = $this->channelSelectionService->channelLabel($configuration);

            if ($definitionCode !== 'adobe_commerce') {
                $rows[] = [
                    'label' => $label,
                    'platform' => (string) ($definitionCode ?? 'unknown'),
                    'status_label' => 'Додано до каналу',
                    'details' => [],
                    'classification_ready' => null,
                ];

                continue;
            }

            $rows[] = $this->magentoRow($account, $product, $label);
        }

        return $rows;
    }

    /**
     * @return array{
     *   label:string,
     *   platform:string,
     *   status_label:string,
     *   details:list<string>,
     *   classification_ready:bool
     * }
     */
    private function magentoRow(
        ConnectorAccount $account,
        Product $product,
        string $label,
    ): array {
        try {
            $classification = $this->classificationReadService->resolve($account, $product);
        } catch (AdobeProductClassificationException) {
            return [
                'label' => $label,
                'platform' => 'adobe_commerce',
                'status_label' => 'Потрібна перевірка',
                'details' => ['Не вдалося перевірити класифікацію Magento для цього товару.'],
                'classification_ready' => false,
            ];
        }

        if ($classification->isReady()) {
            return [
                'label' => $label,
                'platform' => 'adobe_commerce',
                'status_label' => 'Класифікація готова',
                'details' => ['Категорія та набір атрибутів Magento визначені.'],
                'classification_ready' => true,
            ];
        }

        $needsReview = array_intersect(self::REVIEW_BLOCKERS, $classification->blockers) !== [];
        $details = collect($classification->blockers)
            ->map(fn (string $blocker): string => $this->blockerMessage($blocker))
            ->unique()
            ->values()
            ->all();

        return [
            'label' => $label,
            'platform' => 'adobe_commerce',
            'status_label' => $needsReview ? 'Потрібна перевірка' : 'Потрібне налаштування',
            'details' => $details !== []
                ? $details
                : ['Потрібно завершити класифікацію Magento для цього товару.'],
            'classification_ready' => false,
        ];
    }

    private function blockerMessage(string $blocker): string
    {
        return match ($blocker) {
            'category_unresolved' => 'Не вибрано категорію Magento.',
            'attribute_set_unresolved' => 'Не вибрано набір атрибутів Magento.',
            'product_attribute_set_override_unavailable' => 'Вибраний набір атрибутів Magento більше недоступний.',
            'product_type_attribute_set_default_unavailable' => 'Набір атрибутів Magento для цього типу товару більше недоступний.',
            'trusted_remote_attribute_set_unresolved' => 'Не вдалося підтвердити набір атрибутів пов’язаного товару Magento.',
            'trusted_remote_attribute_set_conflict' => 'Пов’язані варіанти Magento мають різні набори атрибутів.',
            'trusted_remote_subject_ambiguous' => 'Потрібно перевірити зв’язок із товаром Magento.',
            default => 'Потрібна перевірка налаштувань Magento.',
        };
    }
}
