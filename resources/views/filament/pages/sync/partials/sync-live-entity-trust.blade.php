{{--
  Stage 3E-R2b-2: Merchant-Confirmed Magento ENTITY TRUST UI
  Per-item Live Linking inside the Live area of ManageAdobeProductsExportPreview.

  Truthfully shows the per-Product Entity Trust readiness and lets the merchant:
    1. Start a fresh review (simple variant, or configurable with optional parent
       SKU hint when a trusted parent is not yet known)
    2. Start an explicit relink to a different Magento parent (configurable
       with parent SKU input; simple as a separate action with no SKU input)
    3. Confirm a successful review (opaque flow id; no real token ever in DOM)
    4. Cancel a stale review

  No live/safe Magento write is ever performed here. The Confirm action only
  updates the ExternalRecordLink provenance (R2a) after a successful review.
--}}
@if ($entityTrustSectionVisible)
  <x-filament::section
    data-testid="sync-live-entity-trust-section"
    data-section-visible="1"
    data-can-review-or-confirm="{{ $entityTrustCanReviewOrConfirm ? '1' : '0' }}"
  >
    <x-slot name="heading">
      {{ __('entity_trust.section.title') }}
    </x-slot>
    <x-slot name="description">
      {{ __('entity_trust.section.subtitle') }}
    </x-slot>

    @if (! $entityTrustCanReviewOrConfirm)
      <p
        class="mb-3 text-sm text-gray-700 dark:text-gray-200"
        data-testid="sync-live-entity-trust-no-permission"
      >
        {{ __('entity_trust.section.no_permission') }}
      </p>
    @endif

    @if ($entityTrustErrorTitle)
      <p
        class="mb-3 text-sm text-danger-600 dark:text-danger-400"
        data-testid="sync-live-entity-trust-error"
      >
        {{ $entityTrustErrorTitle }}
      </p>
    @endif

    @if ($entityTrustActiveReviewFlowId !== null && $entityTrustActiveReviewProductId !== null)
      <div
        class="mb-4 space-y-3 rounded-md border border-primary-200 bg-primary-50 p-4 dark:border-primary-800 dark:bg-primary-950/30"
        data-testid="sync-live-entity-trust-active-review"
        data-product-id="{{ $entityTrustActiveReviewProductId }}"
        data-flow-id-present="1"
      >
        <p
          class="text-sm font-medium text-gray-900 dark:text-gray-100"
          data-testid="sync-live-entity-trust-active-product"
        >
          {{ $entityTrustOutcomeProductName }}
          @if ($entityTrustOutcomePrimarySku)
            <span class="text-xs text-gray-500 dark:text-gray-400">({{ $entityTrustOutcomePrimarySku }})</span>
          @endif
        </p>

        <p
          class="text-sm text-gray-800 dark:text-gray-200"
          data-testid="sync-live-entity-trust-active-mode"
        >
          {{ $entityTrustActiveMode }}
        </p>

        <p
          class="text-sm text-gray-800 dark:text-gray-200"
          data-testid="sync-live-entity-trust-active-summary"
        >
          {{ $entityTrustOutcomeLabel }}
        </p>
        <p
          class="text-xs text-gray-600 dark:text-gray-300"
          data-testid="sync-live-entity-trust-active-explanation"
        >
          {{ $entityTrustOutcomeExplanation }}
        </p>

        @if (count($entityTrustActiveSubjects) > 0)
          <div
            class="overflow-x-auto"
            data-testid="sync-live-entity-trust-subjects"
          >
            <table class="w-full min-w-full text-xs">
              <thead>
                <tr class="border-b border-gray-200 text-left dark:border-gray-700">
                  <th class="px-2 py-1">{{ __('entity_trust.subjects.columns.role') }}</th>
                  <th class="px-2 py-1">{{ __('entity_trust.subjects.columns.expected_sku') }}</th>
                  <th class="px-2 py-1">{{ __('entity_trust.subjects.columns.type') }}</th>
                  <th class="px-2 py-1">{{ __('entity_trust.subjects.columns.field') }}</th>
                  <th class="px-2 py-1">{{ __('entity_trust.subjects.columns.platform_value') }}</th>
                  <th class="px-2 py-1">{{ __('entity_trust.subjects.columns.remote_value') }}</th>
                </tr>
              </thead>
              <tbody>
                @foreach ($entityTrustActiveSubjects as $subject)
                  <tr
                    class="border-b border-gray-100 align-top dark:border-gray-800"
                    data-testid="sync-live-entity-trust-subject"
                    data-role="{{ $subject['role'] }}"
                  >
                    <td
                      class="px-2 py-1"
                      data-testid="sync-live-entity-trust-subject-role"
                    >
                      {{ $subject['role'] === 'parent'
                          ? __('entity_trust.subjects.role.parent')
                          : __('entity_trust.subjects.role.variant') }}
                    </td>
                    <td
                      class="px-2 py-1"
                      data-testid="sync-live-entity-trust-subject-sku"
                    >
                      {{ $subject['expected_sku'] }}
                    </td>
                    <td
                      class="px-2 py-1"
                      data-testid="sync-live-entity-trust-subject-type"
                    >
                      {{ $subject['magento_type_label'] }}
                    </td>
                    <td colspan="3" class="px-2 py-1">
                      @if (! is_null($subject['declared_image_count']) || ! is_null($subject['declared_roles_summary']))
                        <p
                          class="mb-1 text-xs text-gray-500 dark:text-gray-400"
                          data-testid="sync-live-entity-trust-subject-media-summary"
                        >
                          @if ($subject['declared_image_count'] === 0 && empty($subject['declared_roles_summary']))
                            {{ __('entity_trust.subjects.platform_media_summary.empty') }}
                          @else
                            {{ __('entity_trust.subjects.platform_media_summary.label', [
                                'count' => (int) ($subject['declared_image_count'] ?? 0),
                                'roles' => (string) ($subject['declared_roles_summary'] ?? '—'),
                            ]) }}
                          @endif
                        </p>
                      @endif
                      @if (count($subject['field_comparisons']) > 0)
                        <table class="w-full">
                          <tbody>
                            @foreach ($subject['field_comparisons'] as $comparison)
                              <tr
                                class="border-t border-gray-100 dark:border-gray-800"
                                data-testid="sync-live-entity-trust-comparison"
                              >
                                <td class="pr-2 align-top text-gray-500 dark:text-gray-400">
                                  {{ $comparison['label'] }}
                                </td>
                                <td class="pr-2 align-top">
                                  {{ $comparison['platform_value'] ?? '—' }}
                                </td>
                                <td class="align-top">
                                  {{ $comparison['remote_value'] ?? '—' }}
                                </td>
                              </tr>
                            @endforeach
                          </tbody>
                        </table>
                      @else
                        <span class="text-xs text-gray-500 dark:text-gray-400">
                          {{ __('entity_trust.subjects.no_field_diffs') }}
                        </span>
                      @endif
                    </td>
                  </tr>
                @endforeach
              </tbody>
            </table>
          </div>
        @endif

        @if ($entityTrustActiveExtraChildrenAvailable)
          @if (count($entityTrustActiveExtraChildSkus) > 0)
            <div
              class="rounded border border-warning-300 bg-warning-50 p-2 text-xs text-warning-800 dark:border-warning-700 dark:bg-warning-950/30 dark:text-warning-200"
              data-testid="sync-live-entity-trust-extra-children"
              data-extra-children-state="available-non-empty"
            >
              {{ __('entity_trust.extra_children.notice', ['count' => count($entityTrustActiveExtraChildSkus)]) }}
              <ul class="mt-1 list-disc pl-4">
                @foreach ($entityTrustActiveExtraChildSkus as $extraChildSku)
                  <li>{{ $extraChildSku }}</li>
                @endforeach
              </ul>
            </div>
          @else
            <div
              class="rounded border border-gray-200 bg-gray-50 p-2 text-xs text-gray-700 dark:border-gray-700 dark:bg-gray-900/30 dark:text-gray-200"
              data-testid="sync-live-entity-trust-extra-children"
              data-extra-children-state="available-empty"
            >
              {{ __('entity_trust.extra_children.empty_notice') }}
            </div>
          @endif
        @else
          <div
            class="rounded border border-gray-200 bg-gray-50 p-2 text-xs text-gray-500 dark:border-gray-700 dark:bg-gray-900/30 dark:text-gray-400"
            data-testid="sync-live-entity-trust-extra-children"
            data-extra-children-state="unavailable"
          >
            {{ __('entity_trust.extra_children.unavailable_notice') }}
          </div>
        @endif

        <div class="flex flex-wrap gap-2">
          @if ($entityTrustCanReviewOrConfirm && $entityTrustOutcomeReadyForConfirmation)
            <x-filament::button
              wire:click="confirmEntityTrust"
              color="primary"
              data-testid="sync-live-entity-trust-confirm"
            >
              {{ __('entity_trust.actions.confirm') }}
            </x-filament::button>
          @endif

          <x-filament::button
            wire:click="cancelEntityTrustFlow"
            color="gray"
            data-testid="sync-live-entity-trust-cancel"
          >
            {{ __('entity_trust.actions.cancel') }}
          </x-filament::button>
        </div>
      </div>
    @elseif ($entityTrustOutcomeCategory !== null)
      <div
        class="mb-4 space-y-2 rounded-md border border-gray-200 bg-gray-50 p-3 dark:border-gray-700 dark:bg-gray-900/30"
        data-testid="sync-live-entity-trust-last-outcome"
        data-category="{{ $entityTrustOutcomeCategory }}"
      >
        @if ($entityTrustOutcomeProductName)
          <p
            class="text-sm font-medium text-gray-900 dark:text-gray-100"
            data-testid="sync-live-entity-trust-last-product"
          >
            {{ $entityTrustOutcomeProductName }}
          </p>
        @endif
        <p
          class="text-sm text-gray-800 dark:text-gray-200"
          data-testid="sync-live-entity-trust-last-label"
        >
          {{ $entityTrustOutcomeLabel }}
        </p>
        <p
          class="text-xs text-gray-600 dark:text-gray-300"
          data-testid="sync-live-entity-trust-last-explanation"
        >
          {{ $entityTrustOutcomeExplanation }}
        </p>
      </div>
    @endif

    <div class="overflow-x-auto" data-testid="sync-live-entity-trust-working-set">
      <table class="w-full min-w-full text-sm">
        <thead>
          <tr class="border-b border-gray-200 text-left dark:border-gray-700">
            <th class="px-3 py-2">{{ __('entity_trust.working_set.columns.product') }}</th>
            <th class="px-3 py-2">{{ __('entity_trust.working_set.columns.readiness') }}</th>
            <th class="px-3 py-2">{{ __('entity_trust.working_set.columns.explanation') }}</th>
            <th class="px-3 py-2">{{ __('entity_trust.working_set.columns.actions') }}</th>
          </tr>
        </thead>
        <tbody>
          @forelse ($entityTrustWorkingSet as $row)
            @php
              $rowIsConfigurable = (bool) ($row['is_configurable_family'] ?? false);
              $rowReadiness = (string) ($row['readiness_value'] ?? '');
              $rowAvailableAction = (string) ($row['available_action'] ?? 'none');
              $showInitialParentInput = $rowIsConfigurable
                  && $rowReadiness === \App\Enums\EntityTrust\EntityTrustReadinessStatus::InitialLinkRequired->value
                  && $rowAvailableAction === 'review';
            @endphp
            <tr
              class="border-b border-gray-100 align-top dark:border-gray-800"
              data-testid="sync-live-entity-trust-row"
              data-product-id="{{ $row['product_id'] }}"
              data-readiness="{{ $row['readiness_value'] }}"
              data-configurable="{{ $rowIsConfigurable ? '1' : '0' }}"
            >
              <td class="px-3 py-3" data-testid="sync-live-entity-trust-row-product">
                <div class="space-y-1">
                  <p class="font-medium text-gray-900 dark:text-gray-100">{{ $row['productName'] }}</p>
                  @if (! empty($row['primary_sku']))
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ $row['primary_sku'] }}</p>
                  @endif
                </div>
              </td>
              <td class="px-3 py-3" data-testid="sync-live-entity-trust-row-readiness">
                {{ __($row['ready_label']) }}
              </td>
              <td class="px-3 py-3" data-testid="sync-live-entity-trust-row-explanation">
                {{ __($row['ready_explanation']) }}
              </td>
              <td class="px-3 py-3" data-testid="sync-live-entity-trust-row-actions">
                <div class="flex flex-col gap-2">
                  @if ($rowAvailableAction === 'review' && $entityTrustCanReviewOrConfirm)
                    <form
                      novalidate
                      class="flex flex-col gap-1"
                      data-testid="sync-live-entity-trust-review-form"
                      data-family="{{ $rowIsConfigurable ? 'configurable' : 'simple' }}"
                      wire:submit.prevent="requestEntityTrustReview('{{ $row['product_id'] }}')"
                    >
                      @if ($showInitialParentInput)
                        <label
                          for="entity-trust-initial-parent-sku-{{ $row['product_id'] }}"
                          class="text-xs text-gray-500 dark:text-gray-400"
                          data-testid="sync-live-entity-trust-initial-parent-label"
                        >
                          {{ __('entity_trust.actions.initial_parent_label') }}
                        </label>
                        <input
                          id="entity-trust-initial-parent-sku-{{ $row['product_id'] }}"
                          type="text"
                          wire:model.live="entityTrustInitialLinkParentSkuByProduct.{{ $row['product_id'] }}"
                          data-testid="sync-live-entity-trust-initial-parent-input"
                          class="rounded border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-900"
                          placeholder="{{ __('entity_trust.actions.initial_parent_placeholder') }}"
                        />
                        <p
                          class="text-xs text-gray-500 dark:text-gray-400"
                          data-testid="sync-live-entity-trust-initial-parent-help"
                        >
                          {{ __('entity_trust.actions.initial_parent_help') }}
                        </p>
                      @endif
                      <x-filament::button
                        size="xs"
                        type="submit"
                        color="primary"
                        data-testid="sync-live-entity-trust-action-review"
                      >
                        {{ __('entity_trust.actions.review') }}
                      </x-filament::button>
                    </form>
                  @elseif ($rowAvailableAction === 'relink' && $entityTrustCanReviewOrConfirm)
                    @if ($rowIsConfigurable)
                      <form
                        novalidate
                        class="flex flex-col gap-1"
                        data-testid="sync-live-entity-trust-relink-form"
                        data-family="configurable"
                        wire:submit.prevent="requestEntityTrustRelink('{{ $row['product_id'] }}')"
                      >
                        <label
                          for="entity-trust-relink-sku-{{ $row['product_id'] }}"
                          class="text-xs text-gray-500 dark:text-gray-400"
                        >
                          {{ __('entity_trust.actions.relink_label') }}
                        </label>
                        <input
                          id="entity-trust-relink-sku-{{ $row['product_id'] }}"
                          type="text"
                          wire:model.live="entityTrustRelinkParentSkuByProduct.{{ $row['product_id'] }}"
                          data-testid="sync-live-entity-trust-relink-input"
                          class="rounded border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-900"
                          placeholder="{{ __('entity_trust.actions.relink_placeholder') }}"
                          required
                        />
                        <x-filament::button
                          size="xs"
                          type="submit"
                          color="warning"
                          data-testid="sync-live-entity-trust-action-relink"
                        >
                          {{ __('entity_trust.actions.relink') }}
                        </x-filament::button>
                      </form>
                    @else
                      <div
                        class="flex flex-col gap-1"
                        data-testid="sync-live-entity-trust-relink-simple"
                        data-family="simple"
                      >
                        <p
                          class="text-xs text-gray-500 dark:text-gray-400"
                          data-testid="sync-live-entity-trust-relink-simple-help"
                        >
                          {{ __('entity_trust.actions.relink_simple_help') }}
                        </p>
                        <x-filament::button
                          size="xs"
                          wire:click="requestEntityTrustRelink('{{ $row['product_id'] }}')"
                          color="warning"
                          data-testid="sync-live-entity-trust-action-relink"
                        >
                          {{ __('entity_trust.actions.relink_simple') }}
                        </x-filament::button>
                      </div>
                    @endif
                  @else
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                      {{ __('entity_trust.working_set.no_action') }}
                    </p>
                  @endif
                </div>
              </td>
            </tr>
          @empty
            <tr>
              <td
                colspan="4"
                class="px-3 py-6 text-center text-gray-500 dark:text-gray-400"
                data-testid="sync-live-entity-trust-empty"
              >
                {{ __('entity_trust.working_set.empty') }}
              </td>
            </tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </x-filament::section>
@endif
