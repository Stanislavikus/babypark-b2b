<?php

namespace App\Support\Connectors\AdobePaaS\Media;

use App\Enums\SyncLiveOutcome;
use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContextFactory;
use App\Support\Connectors\AdobePaaS\AdobeProductExportLiveRunContext;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductAppliedStateKnowledge;
use App\Support\Connectors\AdobePaaS\Semantic\AdobeProductExportSemanticResult;
use App\Support\Sync\Live\SyncLiveConsequentialWriteGate;
use App\Support\Sync\Live\SyncLiveProductExecutionResult;
use App\Support\Sync\Preview\ProductExecutionAggregate;
use App\Support\Sync\Preview\ProductExecutionImageSourceEntry;
use App\Support\Sync\Preview\ProductExecutionImageStructuralState;

final class AdobeProductMediaLiveExecutor
{
    public function __construct(
        private readonly AdobePaaSRequestContextFactory $contextFactory,
        private readonly AdobeProductMediaTargetResolver $targetResolver,
        private readonly AdobeProductSourceImageFetcher $sourceImageFetcher,
        private readonly AdobeProductMediaDesiredStateBuilder $desiredStateBuilder,
        private readonly AdobeProductMediaRemoteStateClient $remoteStateClient,
        private readonly AdobeProductMediaEntryExecutor $entryExecutor,
        private readonly AdobeProductMediaOutcomeComposer $outcomeComposer,
    ) {}

    public function executeAfterCoreProduct(
        ProductExecutionAggregate $aggregate,
        AdobeProductExportSemanticResult $semanticResult,
        SyncLiveProductExecutionResult $coreResult,
        AdobeProductExportLiveRunContext $runContext,
        SyncLiveConsequentialWriteGate $writeGate,
        bool $isConfigurablePath,
    ): SyncLiveProductExecutionResult {
        if ($coreResult->outcome !== SyncLiveOutcome::Synchronized) {
            return $coreResult;
        }

        if ($aggregate->imageInput->structuralState === ProductExecutionImageStructuralState::Malformed) {
            return $this->outcomeComposer->compose($coreResult, [
                new AdobeProductMediaCommandEvidence(
                    declarationIndex: 0,
                    role: AdobeProductMediaRole::Primary,
                    appliedStateKnowledge: AdobeProductAppliedStateKnowledge::KnownNotApplied,
                    reasonCode: 'malformed_image_collection',
                ),
            ]);
        }

        if (! $aggregate->imageInput->hasEntries()) {
            return $coreResult;
        }

        $target = $this->targetResolver->resolve(
            $runContext->workspaceId,
            (string) $aggregate->productId,
            $semanticResult,
            $isConfigurablePath,
        );

        if ($target === null) {
            return $this->outcomeComposer->compose($coreResult, [
                new AdobeProductMediaCommandEvidence(
                    declarationIndex: 0,
                    role: AdobeProductMediaRole::Primary,
                    appliedStateKnowledge: AdobeProductAppliedStateKnowledge::UnknownOrAmbiguous,
                    reasonCode: 'media_target_unresolved',
                ),
            ]);
        }

        $context = $this->contextFactory->create(
            $runContext->workspaceId,
            $runContext->connectorAccountId,
        );

        /** @var list<AdobeProductMediaCommandEvidence> $evidence */
        $evidence = [];
        $seenContentHashes = [];
        $remoteIndex = null;
        $stopSourceFetch = false;

        foreach ($aggregate->imageInput->entries as $sourceEntry) {
            if ($stopSourceFetch) {
                break;
            }

            $role = $sourceEntry->isPrimary()
                ? AdobeProductMediaRole::Primary
                : AdobeProductMediaRole::Gallery;

            if ($sourceEntry->isMalformed || $sourceEntry->sourceReference === null) {
                $evidence[] = $this->localNotAppliedEvidence(
                    $sourceEntry,
                    'malformed_image_declaration',
                );

                continue;
            }

            $validation = $this->sourceImageFetcher->fetchAndValidate(
                $sourceEntry->sourceReference,
                $sourceEntry->declarationIndex,
                $role,
            );

            if (! $validation->accepted || $validation->verifiedImage === null) {
                $evidence[] = new AdobeProductMediaCommandEvidence(
                    declarationIndex: $sourceEntry->declarationIndex,
                    role: $role,
                    appliedStateKnowledge: AdobeProductAppliedStateKnowledge::KnownNotApplied,
                    reasonCode: $validation->reasonCode !== '' ? $validation->reasonCode : 'local_media_source_failed',
                );

                continue;
            }

            $verifiedImage = $validation->verifiedImage;

            if (isset($seenContentHashes[$verifiedImage->contentSha256])) {
                continue;
            }

            $seenContentHashes[$verifiedImage->contentSha256] = true;

            if ($remoteIndex === null) {
                $metadataIndex = $this->remoteStateClient->readMetadataIndexWithContext($context, $target['sku']);

                if (! $metadataIndex->isTrusted()) {
                    $evidence[] = new AdobeProductMediaCommandEvidence(
                        declarationIndex: $verifiedImage->declarationIndex,
                        role: $verifiedImage->role,
                        appliedStateKnowledge: AdobeProductAppliedStateKnowledge::UnknownOrAmbiguous,
                        reasonCode: $metadataIndex->reasonCode !== ''
                            ? $metadataIndex->reasonCode
                            : 'remote_media_metadata_untrusted',
                        mimeType: $verifiedImage->mimeType,
                        contentSha256Prefix: substr($verifiedImage->contentSha256, 0, 8),
                    );
                    $stopSourceFetch = true;

                    break;
                }

                $remoteIndex = $this->remoteStateClient->buildReconciliationIndex($context, $target['sku'], $metadataIndex);

                if ($remoteIndex === null) {
                    $evidence[] = new AdobeProductMediaCommandEvidence(
                        declarationIndex: $verifiedImage->declarationIndex,
                        role: $verifiedImage->role,
                        appliedStateKnowledge: AdobeProductAppliedStateKnowledge::UnknownOrAmbiguous,
                        reasonCode: 'remote_media_content_index_untrusted',
                        mimeType: $verifiedImage->mimeType,
                        contentSha256Prefix: substr($verifiedImage->contentSha256, 0, 8),
                    );
                    $stopSourceFetch = true;

                    break;
                }
            }

            $desired = $this->desiredStateBuilder->buildEntry($target['label'], $verifiedImage);

            $entryEvidence = $this->entryExecutor->execute(
                $context,
                $target['sku'],
                $desired,
                $remoteIndex,
                $writeGate,
            );

            $evidence[] = $entryEvidence;

            if ($entryEvidence->appliedStateKnowledge === AdobeProductAppliedStateKnowledge::UnknownOrAmbiguous) {
                $stopSourceFetch = true;
            }
        }

        return $this->outcomeComposer->compose($coreResult, $evidence);
    }

    private function localNotAppliedEvidence(
        ProductExecutionImageSourceEntry $sourceEntry,
        string $reasonCode,
    ): AdobeProductMediaCommandEvidence {
        return new AdobeProductMediaCommandEvidence(
            declarationIndex: $sourceEntry->declarationIndex,
            role: $sourceEntry->isPrimary() ? AdobeProductMediaRole::Primary : AdobeProductMediaRole::Gallery,
            appliedStateKnowledge: AdobeProductAppliedStateKnowledge::KnownNotApplied,
            reasonCode: $reasonCode,
        );
    }
}
