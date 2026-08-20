<?php

namespace App\Support\Connectors\AdobePaaS\Media;

use App\Enums\SyncLiveOutcome;
use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContext;
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
        if (! $aggregate->imageInput->hasEntries()) {
            return $coreResult;
        }

        if ($coreResult->outcome !== SyncLiveOutcome::Synchronized) {
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

        $metadataIndex = $this->remoteStateClient->readMetadataIndexWithContext($context, $target['sku']);

        if (! $metadataIndex->isTrusted()) {
            return $this->outcomeComposer->compose($coreResult, [
                new AdobeProductMediaCommandEvidence(
                    declarationIndex: 0,
                    role: AdobeProductMediaRole::Primary,
                    appliedStateKnowledge: AdobeProductAppliedStateKnowledge::UnknownOrAmbiguous,
                    reasonCode: $metadataIndex->reasonCode !== ''
                        ? $metadataIndex->reasonCode
                        : 'remote_media_metadata_untrusted',
                ),
            ]);
        }

        $remoteContentIndex = $this->buildRemoteContentIndex($context, $target['sku'], $metadataIndex);

        if ($remoteContentIndex === null) {
            return $this->outcomeComposer->compose($coreResult, [
                new AdobeProductMediaCommandEvidence(
                    declarationIndex: 0,
                    role: AdobeProductMediaRole::Primary,
                    appliedStateKnowledge: AdobeProductAppliedStateKnowledge::UnknownOrAmbiguous,
                    reasonCode: 'remote_media_content_index_untrusted',
                ),
            ]);
        }

        /** @var list<AdobeProductMediaCommandEvidence> $evidence */
        $evidence = [];
        $verifiedImages = [];
        $stopWrites = false;

        foreach ($aggregate->imageInput->entries as $sourceEntry) {
            $role = $sourceEntry->isPrimary()
                ? AdobeProductMediaRole::Primary
                : AdobeProductMediaRole::Gallery;

            if ($aggregate->imageInput->structuralState === ProductExecutionImageStructuralState::Malformed
                || $sourceEntry->isMalformed
                || $sourceEntry->sourceReference === null
            ) {
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

            $verifiedImages[] = $validation->verifiedImage;
        }

        $desiredEntries = $this->desiredStateBuilder->build($target['label'], $verifiedImages);

        foreach ($desiredEntries as $desired) {
            if ($stopWrites) {
                $evidence[] = new AdobeProductMediaCommandEvidence(
                    declarationIndex: $desired->declarationIndex,
                    role: $desired->role,
                    appliedStateKnowledge: AdobeProductAppliedStateKnowledge::KnownNotApplied,
                    reasonCode: 'prior_remote_media_ambiguity_blocked_writes',
                    mimeType: $desired->mimeType,
                    contentSha256Prefix: substr($desired->contentSha256, 0, 8),
                );

                continue;
            }

            $entryEvidence = $this->entryExecutor->execute(
                $context,
                $target['sku'],
                $desired,
                $remoteContentIndex['byHash'],
                $remoteContentIndex['byFilename'],
                $writeGate,
            );

            $evidence[] = $entryEvidence;

            if ($entryEvidence->appliedStateKnowledge === AdobeProductAppliedStateKnowledge::UnknownOrAmbiguous) {
                $stopWrites = true;
            }
        }

        return $this->outcomeComposer->compose($coreResult, $evidence);
    }

    /**
     * @return array{
     *     byHash: array<string, list<array{metadata: AdobeProductRemoteMediaMetadataEntry, content: AdobeProductRemoteMediaContentEntry}>>,
     *     byFilename: array<string, AdobeProductRemoteMediaContentEntry>
     * }|null
     */
    private function buildRemoteContentIndex(
        AdobePaaSRequestContext $context,
        string $sku,
        AdobeProductRemoteMediaMetadataIndex $metadataIndex,
    ): ?array {
        $byHash = [];
        $byFilename = [];

        foreach ($metadataIndex->entries as $metadata) {
            $content = $this->remoteStateClient->readMediaContent($context, $sku, $metadata->entryId);

            if (! $content->isTrusted()) {
                return null;
            }

            $byHash[$content->contentSha256] ??= [];
            $byHash[$content->contentSha256][] = [
                'metadata' => $metadata,
                'content' => $content,
            ];

            $basename = basename($metadata->file);

            if ($basename !== '') {
                $byFilename[$basename] = $content;
            }
        }

        return [
            'byHash' => $byHash,
            'byFilename' => $byFilename,
        ];
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
