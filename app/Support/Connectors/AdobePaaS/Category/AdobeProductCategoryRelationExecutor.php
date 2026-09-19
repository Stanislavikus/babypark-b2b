<?php

namespace App\Support\Connectors\AdobePaaS\Category;

use App\Enums\AdobeProductCategoryAssignmentState;
use App\Enums\SyncLiveOutcome;
use App\Models\AdobeProductCategoryAssignment;
use App\Models\ExternalRecordLink;
use App\Services\Sync\ConnectorCategoryMappingSnapshotService;
use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContext;
use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContextFactory;
use App\Support\Connectors\AdobePaaS\AdobeProductExportLiveRunContext;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductExternalRecordLinkGuard;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductRemoteStateClient;
use App\Support\Connectors\AdobePaaS\Product\AdobeProductDocument;
use App\Support\Connectors\AdobePaaS\Product\AdobeProductDocumentReader;
use App\Support\Connectors\AdobePaaS\Product\AdobeProductDocumentReadException;
use App\Support\Connectors\AdobePaaS\Semantic\AdobeProductExportSemanticOperation;
use App\Support\Connectors\AdobePaaS\Semantic\AdobeProductExportSemanticResult;
use App\Support\Connectors\ConnectorAccountOperationLock;
use App\Support\Sync\Live\SyncLiveConsequentialWriteGate;
use App\Support\Sync\Live\SyncLiveFinding;
use App\Support\Sync\Live\SyncLiveProductExecutionResult;
use App\Support\Sync\Preview\ProductExecutionAggregate;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class AdobeProductCategoryRelationExecutor
{
    public function __construct(
        private readonly ConnectorCategoryMappingSnapshotService $mappingSnapshotService,
        private readonly AdobeProductExternalRecordLinkGuard $linkGuard,
        private readonly AdobePaaSRequestContextFactory $contextFactory,
        private readonly AdobeProductDocumentReader $documentReader,
        private readonly AdobeProductRemoteStateClient $remoteStateClient,
    ) {}

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function executeAfterProduct(
        ProductExecutionAggregate $aggregate,
        array $snapshot,
        AdobeProductExportSemanticResult $semanticResult,
        SyncLiveProductExecutionResult $currentResult,
        AdobeProductExportLiveRunContext $runContext,
        SyncLiveConsequentialWriteGate $writeGate,
        bool $isConfigurablePath,
    ): SyncLiveProductExecutionResult {
        if ($currentResult->outcome !== SyncLiveOutcome::Synchronized) {
            return $currentResult;
        }

        if (DB::transactionLevel() > 0 && ! app()->environment('testing')) {
            throw new \RuntimeException('Adobe category relation execution must not run inside a database transaction.');
        }

        $desiredExternalCategoryId = $this->mappingSnapshotService->externalCategoryIdFor(
            $aggregate->categoryId,
            $snapshot,
        );

        if ($aggregate->categoryId !== null && $desiredExternalCategoryId === null) {
            return $this->compose(
                $currentResult,
                SyncLiveOutcome::Partial,
                'category_mapping_missing',
                (string) $aggregate->categoryId,
            );
        }

        if ($desiredExternalCategoryId !== null
            && preg_match('/^[1-9][0-9]*$/', $desiredExternalCategoryId) !== 1
        ) {
            return $this->compose(
                $currentResult,
                SyncLiveOutcome::Partial,
                'category_mapping_invalid_external_id',
                (string) $aggregate->categoryId,
            );
        }

        $target = $this->resolveTrustedTarget(
            $runContext->workspaceId,
            $runContext->connectorAccountId,
            $aggregate,
            $semanticResult,
            $isConfigurablePath,
        );

        if ($target['outcome'] !== null) {
            return $this->compose(
                $currentResult,
                $target['outcome'],
                $target['reason'],
                $aggregate->productId,
            );
        }

        /** @var ExternalRecordLink $link */
        $link = $target['link'];
        $sku = trim((string) $link->external_identifier);
        $entityId = trim((string) $link->external_record_discriminator);

        if ($sku === '' || preg_match('/^[1-9][0-9]*$/', $entityId) !== 1) {
            return $this->compose(
                $currentResult,
                SyncLiveOutcome::Partial,
                'category_relation_trusted_identity_invalid',
                $aggregate->productId,
            );
        }

        if ($desiredExternalCategoryId === null
            && ! AdobeProductCategoryAssignment::withoutWorkspaceScope()
                ->where('workspace_id', $runContext->workspaceId)
                ->where('connector_account_id', $runContext->connectorAccountId)
                ->where('external_record_link_id', $link->id)
                ->exists()
        ) {
            return $currentResult;
        }

        try {
            return Cache::lock(
                ConnectorAccountOperationLock::cacheKey($runContext->connectorAccountId),
                $this->operationLockSeconds(),
            )->block(5, fn (): SyncLiveProductExecutionResult => $this->executeLocked(
                $aggregate,
                $desiredExternalCategoryId,
                $link,
                $sku,
                $entityId,
                $currentResult,
                $runContext,
                $writeGate,
            ));
        } catch (LockTimeoutException) {
            return $this->compose(
                $currentResult,
                SyncLiveOutcome::Ambiguous,
                'category_relation_account_lock_timeout',
                $sku,
            );
        }
    }

    /**
     * @return array{outcome: SyncLiveOutcome|null, reason: string, link: ExternalRecordLink|null}
     */
    private function resolveTrustedTarget(
        string $workspaceId,
        string $connectorAccountId,
        ProductExecutionAggregate $aggregate,
        AdobeProductExportSemanticResult $semanticResult,
        bool $isConfigurablePath,
    ): array {
        if ($isConfigurablePath) {
            if (! ctype_digit($aggregate->productId)) {
                return ['outcome' => SyncLiveOutcome::Partial, 'reason' => 'category_relation_product_id_invalid', 'link' => null];
            }

            $lookup = $this->linkGuard->resolveTrustedParentLinkBySubject(
                $workspaceId,
                $connectorAccountId,
                (int) $aggregate->productId,
            );
        } else {
            $variantId = $this->simpleVariantId($semanticResult);

            if ($variantId === null) {
                return ['outcome' => SyncLiveOutcome::Partial, 'reason' => 'category_relation_variant_id_missing', 'link' => null];
            }

            $lookup = $this->linkGuard->resolveTrustedVariantLinkBySubject(
                $workspaceId,
                $connectorAccountId,
                $variantId,
            );
        }

        if ($lookup->isAmbiguous()) {
            return ['outcome' => SyncLiveOutcome::Ambiguous, 'reason' => 'category_relation_trusted_link_ambiguous', 'link' => null];
        }

        if (! $lookup->isTrusted() || $lookup->link === null) {
            return ['outcome' => SyncLiveOutcome::Partial, 'reason' => 'category_relation_trusted_link_required', 'link' => null];
        }

        return ['outcome' => null, 'reason' => '', 'link' => $lookup->link];
    }

    private function simpleVariantId(AdobeProductExportSemanticResult $semanticResult): ?string
    {
        foreach ($semanticResult->operations as $operation) {
            if (! $operation instanceof AdobeProductExportSemanticOperation || $operation->operation !== 'simple_product') {
                continue;
            }

            $variantId = $operation->context['variant_id'] ?? null;

            if (is_int($variantId) && $variantId > 0) {
                return (string) $variantId;
            }

            if (is_string($variantId) && ctype_digit($variantId)) {
                return $variantId;
            }
        }

        return null;
    }

    private function executeLocked(
        ProductExecutionAggregate $aggregate,
        ?string $desiredExternalCategoryId,
        ExternalRecordLink $link,
        string $sku,
        string $entityId,
        SyncLiveProductExecutionResult $currentResult,
        AdobeProductExportLiveRunContext $runContext,
        SyncLiveConsequentialWriteGate $writeGate,
    ): SyncLiveProductExecutionResult {
        $productContext = $this->contextFactory->create(
            $runContext->workspaceId,
            $runContext->connectorAccountId,
        );
        $categoryContext = $this->categoryRelationContext($productContext);

        $document = $this->readTrustedDocument($productContext, $sku, $entityId);

        if ($document === null) {
            return $this->compose(
                $currentResult,
                SyncLiveOutcome::Ambiguous,
                'category_relation_pre_read_untrusted',
                $sku,
            );
        }

        $remoteCategoryIds = $document->categoryIds();
        $findings = [];

        /** @var list<AdobeProductCategoryAssignment> $assignments */
        $assignments = AdobeProductCategoryAssignment::withoutWorkspaceScope()
            ->where('workspace_id', $runContext->workspaceId)
            ->where('connector_account_id', $runContext->connectorAccountId)
            ->where('external_record_link_id', $link->id)
            ->orderBy('external_category_id')
            ->get()
            ->all();

        foreach ($assignments as $assignment) {
            $state = $assignment->state;

            if (in_array($state, [
                AdobeProductCategoryAssignmentState::Managed,
                AdobeProductCategoryAssignmentState::PendingRemove,
                AdobeProductCategoryAssignmentState::RemoveAmbiguous,
            ], true)
                && $assignment->anchor_entity_id !== $entityId
            ) {
                return $this->composeWithFindings(
                    $currentResult,
                    SyncLiveOutcome::Ambiguous,
                    $findings,
                    $this->finding(
                        'category_relation_anchor_entity_drift',
                        $sku,
                        ['external_category_id' => $assignment->external_category_id],
                    ),
                );
            }

            $present = in_array($assignment->external_category_id, $remoteCategoryIds, true);

            if ($state === AdobeProductCategoryAssignmentState::Managed && ! $present) {
                $this->deleteAssignmentLocally($assignment->id);
                $findings[] = $this->finding(
                    'category_relation_removed_remotely',
                    $sku,
                    ['external_category_id' => $assignment->external_category_id],
                );

                continue;
            }

            if ($state === AdobeProductCategoryAssignmentState::PendingAdd
                && $assignment->attempt_dispatched_at !== null
            ) {
                if ($present) {
                    $this->transitionState(
                        $assignment->id,
                        AdobeProductCategoryAssignmentState::AddAmbiguous,
                    );
                    $findings[] = $this->finding(
                        'category_relation_add_ownership_unproven',
                        $sku,
                        ['external_category_id' => $assignment->external_category_id],
                    );
                } else {
                    $this->deleteAssignmentLocally($assignment->id);
                }

                continue;
            }

            if ($state === AdobeProductCategoryAssignmentState::AddAmbiguous) {
                if (! $present) {
                    $this->deleteAssignmentLocally($assignment->id);
                } elseif ($assignment->external_category_id === $desiredExternalCategoryId) {
                    $findings[] = $this->finding(
                        'category_relation_add_ownership_unproven',
                        $sku,
                        ['external_category_id' => $assignment->external_category_id],
                    );
                }

                continue;
            }

            if (in_array($state, [
                AdobeProductCategoryAssignmentState::PendingRemove,
                AdobeProductCategoryAssignmentState::RemoveAmbiguous,
            ], true)) {
                if (! $present) {
                    $this->deleteAssignmentLocally($assignment->id);

                    continue;
                }

                if ($assignment->external_category_id === $desiredExternalCategoryId) {
                    $this->markManaged($assignment->id, $entityId);
                }
            }
        }

        $document = $this->readTrustedDocument($productContext, $sku, $entityId);

        if ($document === null) {
            return $this->composeWithFindings(
                $currentResult,
                SyncLiveOutcome::Ambiguous,
                $findings,
                $this->finding('category_relation_reconciliation_read_failed', $sku),
            );
        }

        $remoteCategoryIds = $document->categoryIds();
        $desiredPresent = $desiredExternalCategoryId === null
            || in_array($desiredExternalCategoryId, $remoteCategoryIds, true);

        if (! $desiredPresent && $desiredExternalCategoryId !== null) {
            if (! $writeGate->permitsConsequentialWrite() || ! $writeGate->permitsProductExecution()) {
                return $this->composeWithFindings(
                    $currentResult,
                    SyncLiveOutcome::Partial,
                    $findings,
                    $this->finding('category_relation_write_gate_closed', $sku),
                );
            }

            $addResult = $this->addDesiredRelation(
                $categoryContext,
                $productContext,
                $runContext,
                $link,
                $sku,
                $entityId,
                $desiredExternalCategoryId,
            );

            $findings = array_merge($findings, $addResult['findings']);

            if ($addResult['outcome'] !== SyncLiveOutcome::Synchronized) {
                return $this->composeWithFindings(
                    $currentResult,
                    $addResult['outcome'],
                    $findings,
                );
            }
        }

        $managed = AdobeProductCategoryAssignment::withoutWorkspaceScope()
            ->where('workspace_id', $runContext->workspaceId)
            ->where('connector_account_id', $runContext->connectorAccountId)
            ->where('external_record_link_id', $link->id)
            ->whereIn('state', [
                AdobeProductCategoryAssignmentState::Managed->value,
                AdobeProductCategoryAssignmentState::PendingRemove->value,
                AdobeProductCategoryAssignmentState::RemoveAmbiguous->value,
            ])
            ->orderBy('external_category_id')
            ->get();

        foreach ($managed as $assignment) {
            if ($assignment->external_category_id === $desiredExternalCategoryId) {
                continue;
            }

            if ($assignment->anchor_entity_id !== $entityId) {
                return $this->composeWithFindings(
                    $currentResult,
                    SyncLiveOutcome::Ambiguous,
                    $findings,
                    $this->finding(
                        'category_relation_anchor_entity_drift',
                        $sku,
                        ['external_category_id' => $assignment->external_category_id],
                    ),
                );
            }

            if (! $writeGate->permitsConsequentialWrite() || ! $writeGate->permitsProductExecution()) {
                return $this->composeWithFindings(
                    $currentResult,
                    SyncLiveOutcome::Partial,
                    $findings,
                    $this->finding('category_relation_write_gate_closed', $sku),
                );
            }

            $removeResult = $this->removeManagedRelation(
                $categoryContext,
                $productContext,
                $assignment,
                $sku,
                $entityId,
            );

            $findings = array_merge($findings, $removeResult['findings']);

            if ($removeResult['outcome'] !== SyncLiveOutcome::Synchronized) {
                return $this->composeWithFindings(
                    $currentResult,
                    $removeResult['outcome'],
                    $findings,
                );
            }
        }

        return $this->composeWithFindings(
            $currentResult,
            SyncLiveOutcome::Synchronized,
            $findings,
        );
    }

    /**
     * @return array{outcome: SyncLiveOutcome, findings: list<SyncLiveFinding>}
     */
    private function addDesiredRelation(
        AdobePaaSRequestContext $categoryContext,
        AdobePaaSRequestContext $productContext,
        AdobeProductExportLiveRunContext $runContext,
        ExternalRecordLink $link,
        string $sku,
        string $entityId,
        string $externalCategoryId,
    ): array {
        $assignment = DB::transaction(function () use ($runContext, $link, $externalCategoryId): AdobeProductCategoryAssignment {
            $existing = AdobeProductCategoryAssignment::withoutWorkspaceScope()
                ->where('workspace_id', $runContext->workspaceId)
                ->where('connector_account_id', $runContext->connectorAccountId)
                ->where('external_record_link_id', $link->id)
                ->where('external_category_id', $externalCategoryId)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if ($existing->state === AdobeProductCategoryAssignmentState::AddAmbiguous) {
                    $existing->delete();

                    return AdobeProductCategoryAssignment::withoutWorkspaceScope()->create([
                        'workspace_id' => $runContext->workspaceId,
                        'connector_account_id' => $runContext->connectorAccountId,
                        'external_record_link_id' => $link->id,
                        'external_category_id' => $externalCategoryId,
                        'state' => AdobeProductCategoryAssignmentState::PendingAdd,
                    ]);
                }

                return $existing;
            }

            return AdobeProductCategoryAssignment::withoutWorkspaceScope()->create([
                'workspace_id' => $runContext->workspaceId,
                'connector_account_id' => $runContext->connectorAccountId,
                'external_record_link_id' => $link->id,
                'external_category_id' => $externalCategoryId,
                'state' => AdobeProductCategoryAssignmentState::PendingAdd,
            ]);
        });

        if ($assignment->state !== AdobeProductCategoryAssignmentState::PendingAdd
            || $assignment->attempt_dispatched_at !== null
        ) {
            return [
                'outcome' => SyncLiveOutcome::Ambiguous,
                'findings' => [$this->finding('category_relation_add_intent_conflict', $sku)],
            ];
        }

        $this->markAttemptDispatched($assignment->id);

        [$httpResult, $transportException] = $this->remoteStateClient->postCategoryProductLink(
            $categoryContext,
            $externalCategoryId,
            $sku,
        );

        $document = $this->readTrustedDocument($productContext, $sku, $entityId);
        $present = $document !== null
            && in_array($externalCategoryId, $document->categoryIds(), true);

        if ($transportException !== null || $httpResult === null) {
            $this->transitionState($assignment->id, AdobeProductCategoryAssignmentState::AddAmbiguous);

            return [
                'outcome' => SyncLiveOutcome::Ambiguous,
                'findings' => [$this->finding(
                    'category_relation_add_transport_ambiguous',
                    $sku,
                    ['external_category_id' => $externalCategoryId, 'present_after_attempt' => $present],
                )],
            ];
        }

        if ($httpResult->statusCode >= 200 && $httpResult->statusCode < 300 && $present) {
            $this->markManaged($assignment->id, $entityId);

            return ['outcome' => SyncLiveOutcome::Synchronized, 'findings' => []];
        }

        if (! $present) {
            $this->deleteAssignmentLocally($assignment->id);

            return [
                'outcome' => $httpResult->statusCode >= 200 && $httpResult->statusCode < 300
                    ? SyncLiveOutcome::Ambiguous
                    : SyncLiveOutcome::Partial,
                'findings' => [$this->finding(
                    'category_relation_add_not_applied',
                    $sku,
                    ['external_category_id' => $externalCategoryId, 'status' => $httpResult->statusCode],
                )],
            ];
        }

        $this->transitionState($assignment->id, AdobeProductCategoryAssignmentState::AddAmbiguous);

        return [
            'outcome' => SyncLiveOutcome::Ambiguous,
            'findings' => [$this->finding(
                'category_relation_add_ownership_unproven',
                $sku,
                ['external_category_id' => $externalCategoryId, 'status' => $httpResult->statusCode],
            )],
        ];
    }

    /**
     * @return array{outcome: SyncLiveOutcome, findings: list<SyncLiveFinding>}
     */
    private function removeManagedRelation(
        AdobePaaSRequestContext $categoryContext,
        AdobePaaSRequestContext $productContext,
        AdobeProductCategoryAssignment $assignment,
        string $sku,
        string $entityId,
    ): array {
        $assignment = DB::transaction(function () use ($assignment, $entityId): AdobeProductCategoryAssignment {
            $locked = AdobeProductCategoryAssignment::withoutWorkspaceScope()
                ->whereKey($assignment->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->anchor_entity_id !== $entityId) {
                return $locked;
            }

            $locked->state = AdobeProductCategoryAssignmentState::PendingRemove;
            $locked->attempt_dispatched_at = now();
            $locked->save();

            return $locked->refresh();
        });

        if ($assignment->anchor_entity_id !== $entityId) {
            return [
                'outcome' => SyncLiveOutcome::Ambiguous,
                'findings' => [$this->finding(
                    'category_relation_anchor_entity_drift',
                    $sku,
                    ['external_category_id' => $assignment->external_category_id],
                )],
            ];
        }

        [$httpResult, $transportException] = $this->remoteStateClient->deleteCategoryProductLink(
            $categoryContext,
            $assignment->external_category_id,
            $sku,
        );

        $document = $this->readTrustedDocument($productContext, $sku, $entityId);
        $absent = $document !== null
            && ! in_array($assignment->external_category_id, $document->categoryIds(), true);

        if ($absent) {
            $this->deleteAssignmentLocally($assignment->id);

            if ($transportException !== null || $httpResult === null) {
                return [
                    'outcome' => SyncLiveOutcome::Ambiguous,
                    'findings' => [$this->finding(
                        'category_relation_remove_transport_ambiguous',
                        $sku,
                        ['external_category_id' => $assignment->external_category_id],
                    )],
                ];
            }

            if ($httpResult->statusCode >= 200 && $httpResult->statusCode < 300) {
                return ['outcome' => SyncLiveOutcome::Synchronized, 'findings' => []];
            }

            return [
                'outcome' => SyncLiveOutcome::Ambiguous,
                'findings' => [$this->finding(
                    'category_relation_remove_http_ambiguous',
                    $sku,
                    ['external_category_id' => $assignment->external_category_id, 'status' => $httpResult->statusCode],
                )],
            ];
        }

        $this->transitionState(
            $assignment->id,
            AdobeProductCategoryAssignmentState::RemoveAmbiguous,
        );

        return [
            'outcome' => SyncLiveOutcome::Ambiguous,
            'findings' => [$this->finding(
                'category_relation_remove_unverified',
                $sku,
                [
                    'external_category_id' => $assignment->external_category_id,
                    'status' => $httpResult?->statusCode,
                ],
            )],
        ];
    }

    private function operationLockSeconds(): int
    {
        return max(
            180,
            (int) config('sync_runtime.live_job_timeout_seconds')
                + (int) config('sync_runtime.max_inflight_external_request_seconds'),
        );
    }

    private function categoryRelationContext(AdobePaaSRequestContext $productContext): AdobePaaSRequestContext
    {
        return new AdobePaaSRequestContext(
            baseUrl: $productContext->baseUrl,
            storeCode: 'all',
            credentials: $productContext->credentials,
        );
    }

    private function readTrustedDocument(
        AdobePaaSRequestContext $context,
        string $sku,
        string $entityId,
    ): ?AdobeProductDocument {
        try {
            $document = $this->documentReader->readWithContext($context, $sku);
        } catch (AdobeProductDocumentReadException) {
            return null;
        }

        if ($document->sku !== $sku || (string) $document->logicalEntityId !== $entityId) {
            return null;
        }

        return $document;
    }

    private function markAttemptDispatched(string $assignmentId): void
    {
        DB::transaction(function () use ($assignmentId): void {
            $assignment = AdobeProductCategoryAssignment::withoutWorkspaceScope()
                ->whereKey($assignmentId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($assignment->state !== AdobeProductCategoryAssignmentState::PendingAdd
                || $assignment->attempt_dispatched_at !== null
            ) {
                throw new \RuntimeException('Category add intent is not dispatchable.');
            }

            $assignment->attempt_dispatched_at = now();
            $assignment->save();
        });
    }

    private function markManaged(string $assignmentId, string $entityId): void
    {
        DB::transaction(function () use ($assignmentId, $entityId): void {
            $assignment = AdobeProductCategoryAssignment::withoutWorkspaceScope()
                ->whereKey($assignmentId)
                ->lockForUpdate()
                ->firstOrFail();

            $assignment->state = AdobeProductCategoryAssignmentState::Managed;
            $assignment->anchor_entity_id = $entityId;
            $assignment->attempt_dispatched_at = null;
            $assignment->save();
        });
    }

    private function transitionState(
        string $assignmentId,
        AdobeProductCategoryAssignmentState $state,
    ): void {
        DB::transaction(function () use ($assignmentId, $state): void {
            $assignment = AdobeProductCategoryAssignment::withoutWorkspaceScope()
                ->whereKey($assignmentId)
                ->lockForUpdate()
                ->first();

            if ($assignment === null) {
                return;
            }

            $assignment->state = $state;
            $assignment->save();
        });
    }

    private function deleteAssignmentLocally(string $assignmentId): void
    {
        DB::transaction(function () use ($assignmentId): void {
            $assignment = AdobeProductCategoryAssignment::withoutWorkspaceScope()
                ->whereKey($assignmentId)
                ->lockForUpdate()
                ->first();

            $assignment?->delete();
        });
    }

    /**
     * @param  array<string, scalar|null>  $context
     */
    private function finding(string $code, ?string $subject, array $context = []): SyncLiveFinding
    {
        return new SyncLiveFinding($code, $subject, $context);
    }

    private function compose(
        SyncLiveProductExecutionResult $currentResult,
        SyncLiveOutcome $outcome,
        string $code,
        ?string $subject,
    ): SyncLiveProductExecutionResult {
        return $this->composeWithFindings(
            $currentResult,
            $outcome,
            [],
            $this->finding($code, $subject),
        );
    }

    /**
     * @param  list<SyncLiveFinding>  $findings
     */
    private function composeWithFindings(
        SyncLiveProductExecutionResult $currentResult,
        SyncLiveOutcome $outcome,
        array $findings,
        ?SyncLiveFinding $additional = null,
    ): SyncLiveProductExecutionResult {
        if ($additional !== null) {
            $findings[] = $additional;
        }

        return new SyncLiveProductExecutionResult(
            outcome: $outcome,
            findings: array_merge($currentResult->findings, $findings),
        );
    }
}
