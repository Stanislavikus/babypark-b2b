{{--
  Stage 3E-R2b-2: Merchant-Confirmed Magento ENTITY TRUST UI
  Per-item Live Linking inside the Live area of ManageAdobeProductsExportPreview.

  Truthfully shows the per-Product Entity Trust readiness and lets the merchant:
    1. Start a fresh review (simple variant, or configurable with parent SKU hint)
    2. Start an explicit relink to a different Magento parent
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

        @if ($entityTrustActiveExtraChildrenAvailable && count($entityTrustActiveExtraChildSkus) > 0)
          <div
            class="rounded border border-warning-300 bg-warning-50 p-2 text-xs text-warning-800 dark:border-warning-700 dark:bg-warning-950/30 dark:text-warning-200"
            data-testid="sync-live-entity-trust-extra-children"
          >
            {{ __('entity_trust.extra_children.notice', ['count' => count($entityTrustActiveExtraChildSkus)]) }}
            <ul class="mt-1 list-disc pl-4">
              @foreach ($entityTrustActiveExtraChildSkus as $extraChildSku)
                <li>{{ $extraChildSku }}</li>
              @endforeach
            </ul>
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
            <tr
              class="border-b border-gray-100 align-top dark:border-gray-800"
              data-testid="sync-live-entity-trust-row"
              data-product-id="{{ $row['product_id'] }}"
              data-readiness="{{ $row['readiness_value'] }}"
              data-configurable="{{ $row['is_configurable_family'] ? '1' : '0' }}"
            >
              <td class="px-3 py-3" data-testid="sync-live-entity-trust-row-product">
                <div class="space-y-1">
                  <p class="font-medium text-gray-900 dark:text-gray-100">{{ $row['product_name'] }}</p>
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
                  @if ($row['available_action'] === 'review' && $entityTrustCanReviewOrConfirm)
                    <x-filament::button
                      size="xs"
                      wire:click="requestEntityTrustReview('{{ $row['product_id'] }}')"
                      color="primary"
                      data-testid="sync-live-entity-trust-action-review"
                    >
                      {{ __('entity_trust.actions.review') }}
                    </x-filament::button>
                  @elseif ($row['available_action'] === 'relink' && $entityTrustCanReviewOrConfirm)
                    <form
                      class="flex flex-col gap-1"
                      data-testid="sync-live-entity-trust-relink-form"
                      wire:submit.prevent="requestEntityTrustRelink('{{ $row['product_id'] }}', $event.target.querySelector('[data-relink-sku]').value)"
                    >
                      <label class="text-xs text-gray-500 dark:text-gray-400">
                        {{ __('entity_trust.actions.relink_label') }}
                      </label>
                      <input
                        type="text"
                        name="newMagentoParentSku"
                        data-relink-sku
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
