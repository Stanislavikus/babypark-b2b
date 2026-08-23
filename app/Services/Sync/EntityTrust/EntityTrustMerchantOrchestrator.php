<?php

namespace App\Services\Sync\EntityTrust;

use App\Enums\EntityTrust\EntityTrustConfirmationMode;
use App\Enums\EntityTrust\EntityTrustFailureReason;
use App\Models\ConnectorAccount;
use App\Models\Product;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\WorkspaceAuthorization;
use App\Support\Sync\EntityTrust\EntityTrustMerchantFieldComparison;
use App\Support\Sync\EntityTrust\EntityTrustMerchantOutcome;
use App\Support\Sync\EntityTrust\EntityTrustMerchantSubjectRow;
use App\Support\Sync\EntityTrust\EntityTrustReviewFlowPayload;
use App\Support\Sync\EntityTrust\EntityTrustReviewResult;
use App\Support\Sync\EntityTrust\EntityTrustSubjectReview;
use App\Support\Sync\EntityTrust\Exceptions\EntityTrustException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Orchestrates R2b-1 review/confirm services and turns every outcome (success
 * or every EntityTrustFailureReason) into a single safe EntityTrustMerchantOutcome.
 *
 * This is the only service the R2b-2 Livewire UI calls. It is the boundary
 * where:
 *   - raw reviewTokens become opaque flow IDs
 *   - raw backend exceptions become safe merchant copy
 *   - remote fingerprints / logical entity ids are stripped from UI payloads
 *   - fresh authorization is asserted before any backend work
 */
final class EntityTrustMerchantOrchestrator
{
    public function __construct(
        private readonly AdobeProductEntityTrustAuthorizationService $authorization,
        private readonly WorkspaceAuthorization $workspaceAuthorization,
        private readonly AdobeProductEntityTrustReviewService $reviewService,
        private readonly AdobeProductEntityTrustConfirmationService $confirmationService,
        private readonly EntityTrustReviewFlowStore $flowStore,
        private readonly EntityTrustFailureReasonPresenter $failurePresenter,
    ) {}

    public function requestReview(
        User $actor,
        Workspace $workspace,
        ConnectorAccount $account,
        Product $product,
        bool $explicitRelink = false,
        ?string $existingParentSkuHint = null,
    ): EntityTrustMerchantOutcome {
        $productName = (string) $product->name;
        $primarySku = $product->variants[0]?->sku ?? null;
        $isConfigurable = $this->guessIsConfigurable($product);

        try {
            $this->authorization->assertReviewOrConfirm($actor, $workspace);
            $this->assertFreshDualPermission($actor, $workspace);

            $result = $this->reviewService->review(
                $actor,
                $workspace,
                $account->id,
                (string) $product->id,
                $existingParentSkuHint,
                $explicitRelink,
            );

            return $this->outcomeFromReview(
                $actor,
                $workspace,
                $account,
                $product,
                $productName,
                $primarySku,
                $isConfigurable,
                $result,
                $explicitRelink,
            );
        } catch (EntityTrustException $exception) {
            return $this->outcomeFromFailure(
                $actor,
                $workspace,
                $account,
                $product,
                $productName,
                $primarySku,
                $isConfigurable,
                $exception->reason,
            );
        } catch (\Illuminate\Auth\Access\AuthorizationException $exception) {
            return $this->outcomeFromFailure(
                $actor,
                $workspace,
                $account,
                $product,
                $productName,
                $primarySku,
                $isConfigurable,
                EntityTrustFailureReason::Unauthorized,
            );
        } catch (Throwable $exception) {
            Log::warning('entity_trust.merchant.review_unexpected_failure', [
                'workspace_id' => $workspace->id,
                'connector_account_id' => $account->id,
                'product_id' => $product->id,
            ]);

            return $this->outcomeFromFailure(
                $actor,
                $workspace,
                $account,
                $product,
                $productName,
                $primarySku,
                $isConfigurable,
                EntityTrustFailureReason::SafeSyncFailure,
            );
        }
    }

    public function requestRelink(
        User $actor,
        Workspace $workspace,
        ConnectorAccount $account,
        Product $product,
        string $newMagentoParentSku,
    ): EntityTrustMerchantOutcome {
        return $this->requestReview(
            $actor,
            $workspace,
            $account,
            $product,
            explicitRelink: true,
            existingParentSkuHint: $newMagentoParentSku,
        );
    }

    public function confirm(
        User $actor,
        Workspace $workspace,
        ConnectorAccount $account,
        Product $product,
        string $reviewFlowId,
    ): EntityTrustMerchantOutcome {
        $productName = (string) $product->name;
        $primarySku = $product->variants[0]?->sku ?? null;
        $isConfigurable = $this->guessIsConfigurable($product);

        try {
            $this->authorization->assertReviewOrConfirm($actor, $workspace);
            $this->assertFreshDualPermission($actor, $workspace);

            $payload = $this->flowStore->consume(
                $actor,
                $workspace,
                $account->id,
                (string) $product->id,
                $reviewFlowId,
            );

            if ($payload === null) {
                return $this->outcomeFromFailure(
                    $actor,
                    $workspace,
                    $account,
                    $product,
                    $productName,
                    $primarySku,
                    $isConfigurable,
                    EntityTrustFailureReason::ConfirmationExpiredOrInvalid,
                    reviewFlowId: null,
                );
            }

            $result = $this->confirmationService->confirm(
                $actor,
                $workspace,
                $account->id,
                (string) $product->id,
                $payload->reviewToken,
                $payload->existingParentSkuHint,
                $payload->explicitRelink,
            );

            return $this->outcomeFromConfirmation(
                $actor,
                $workspace,
                $account,
                $product,
                $productName,
                $primarySku,
                $isConfigurable,
                $result->status,
            );
        } catch (EntityTrustException $exception) {
            return $this->outcomeFromFailure(
                $actor,
                $workspace,
                $account,
                $product,
                $productName,
                $primarySku,
                $isConfigurable,
                $exception->reason,
                reviewFlowId: null,
            );
        } catch (\Illuminate\Auth\Access\AuthorizationException $exception) {
            return $this->outcomeFromFailure(
                $actor,
                $workspace,
                $account,
                $product,
                $productName,
                $primarySku,
                $isConfigurable,
                EntityTrustFailureReason::Unauthorized,
                reviewFlowId: null,
            );
        } catch (Throwable $exception) {
            Log::warning('entity_trust.merchant.confirm_unexpected_failure', [
                'workspace_id' => $workspace->id,
                'connector_account_id' => $account->id,
                'product_id' => $product->id,
            ]);

            return $this->outcomeFromFailure(
                $actor,
                $workspace,
                $account,
                $product,
                $productName,
                $primarySku,
                $isConfigurable,
                EntityTrustFailureReason::SafeSyncFailure,
                reviewFlowId: null,
            );
        }
    }

    public function cancelFlow(string $reviewFlowId): void
    {
        $this->flowStore->discard($reviewFlowId);
    }

    private function outcomeFromReview(
        User $actor,
        Workspace $workspace,
        ConnectorAccount $account,
        Product $product,
        string $productName,
        ?string $primarySku,
        bool $isConfigurable,
        EntityTrustReviewResult $result,
        bool $explicitRelink,
    ): EntityTrustMerchantOutcome {
        $presenter = $this->failurePresenter;
        $presentation = $presenter->present($result->status);

        $flowId = null;

        if ($result->isReadyForConfirmation() && $result->reviewToken !== null) {
            $flowId = $this->flowStore->issue(
                $actor,
                $workspace,
                $account->id,
                (string) $product->id,
                $result->reviewToken,
                $result->mode,
                existingParentSkuHint: $this->extractParentSkuHint($result),
                explicitRelink: $explicitRelink,
            );
        }

        $subjects = $this->presentSubjects($result->subjects, $isConfigurable);

        return new EntityTrustMerchantOutcome(
            product_id: (string) $product->id,
            product_name: $productName,
            primary_sku: $primarySku,
            is_configurable_family: $isConfigurable,
            reason: $result->status,
            category: $presentation['category'],
            label_key: $presentation['label_key'],
            explanation_key: $presentation['explanation_key'],
            available_action: $presentation['available_action'],
            review_flow_id: $flowId,
            subjects: $subjects,
            extra_remote_child_skus: $result->extraRemoteChildSkus,
            extra_remote_children_available: $result->extraRemoteChildrenAvailable,
        );
    }

    private function outcomeFromConfirmation(
        User $actor,
        Workspace $workspace,
        ConnectorAccount $account,
        Product $product,
        string $productName,
        ?string $primarySku,
        bool $isConfigurable,
        EntityTrustFailureReason $reason,
    ): EntityTrustMerchantOutcome {
        $presenter = $this->failurePresenter;
        $presentation = $presenter->present($reason);

        return new EntityTrustMerchantOutcome(
            product_id: (string) $product->id,
            product_name: $productName,
            primary_sku: $primarySku,
            is_configurable_family: $isConfigurable,
            reason: $reason,
            category: $presentation['category'],
            label_key: $presentation['label_key'],
            explanation_key: $presentation['explanation_key'],
            available_action: $presentation['available_action'],
            review_flow_id: null,
            subjects: [],
            extra_remote_child_skus: [],
            extra_remote_children_available: false,
        );
    }

    private function outcomeFromFailure(
        User $actor,
        Workspace $workspace,
        ConnectorAccount $account,
        Product $product,
        string $productName,
        ?string $primarySku,
        bool $isConfigurable,
        EntityTrustFailureReason $reason,
        ?string $reviewFlowId = null,
    ): EntityTrustMerchantOutcome {
        $presenter = $this->failurePresenter;
        $presentation = $presenter->present($reason);

        return new EntityTrustMerchantOutcome(
            product_id: (string) $product->id,
            product_name: $productName,
            primary_sku: $primarySku,
            is_configurable_family: $isConfigurable,
            reason: $reason,
            category: $presentation['category'],
            label_key: $presentation['label_key'],
            explanation_key: $presentation['explanation_key'],
            available_action: $presentation['available_action'],
            review_flow_id: $reviewFlowId,
            subjects: [],
            extra_remote_child_skus: [],
            extra_remote_children_available: false,
        );
    }

    private function assertFreshDualPermission(User $actor, Workspace $workspace): void
    {
        if (! $this->workspaceAuthorization->allows($actor, $workspace, 'manage_sync_configurations')
            || ! $this->workspaceAuthorization->allows($actor, $workspace, 'run_sync_live')) {
            throw EntityTrustException::unauthorized();
        }
    }

    private function guessIsConfigurable(Product $product): bool
    {
        $variants = $product->variants ?? null;

        if (! is_iterable($variants)) {
            return false;
        }

        $count = 0;

        foreach ($variants as $variant) {
            $count++;

            if ($count > 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<EntityTrustMerchantSubjectRow>
     */
    private function presentSubjects(array $subjects, bool $isConfigurable): array
    {
        $rows = [];

        foreach ($subjects as $subject) {
            $rows[] = $this->presentSubject($subject, $isConfigurable);
        }

        return $rows;
    }

    private function presentSubject(EntityTrustSubjectReview $subject, bool $isConfigurable): EntityTrustMerchantSubjectRow
    {
        $role = str_starts_with($subject->subjectKey, 'parent:') ? 'parent' : 'variant';
        $magentoTypeLabel = $subject->expectedMagentoType === 'configurable'
            ? 'entity_trust.types.configurable'
            : 'entity_trust.types.simple';

        $comparisons = [];

        foreach ($subject->fieldComparisons as $comparison) {
            $comparisons[] = new EntityTrustMerchantFieldComparison(
                field_key: $comparison->fieldKey,
                label: $comparison->label,
                platform_value: $comparison->platformValue,
                remote_value: $comparison->remoteValue,
            );
        }

        return new EntityTrustMerchantSubjectRow(
            role: $role,
            subject_key: $subject->subjectKey,
            expected_sku: $subject->expectedSku,
            magento_type_label: $magentoTypeLabel,
            platform_name: $subject->platformName,
            declared_image_count: $subject->mediaSummary?->declaredImageCount,
            declared_roles_summary: $subject->mediaSummary?->declaredRolesSummary,
            field_comparisons: $comparisons,
        );
    }

    private function extractParentSkuHint(EntityTrustReviewResult $result): ?string
    {
        if ($result->mode !== EntityTrustConfirmationMode::ConfigurableExistingParent) {
            return null;
        }

        return $result->subjects[0]?->expectedSku;
    }
}
