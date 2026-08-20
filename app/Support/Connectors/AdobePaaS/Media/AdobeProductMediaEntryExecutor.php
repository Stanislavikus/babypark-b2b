<?php

namespace App\Support\Connectors\AdobePaaS\Media;

use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContext;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductAppliedStateKnowledge;
use App\Support\Connectors\Transport\ConnectorHttpResult;
use App\Support\Connectors\Transport\ConnectorTransportException;
use App\Support\Sync\Live\SyncLiveConsequentialWriteGate;

final class AdobeProductMediaEntryExecutor
{
    public function __construct(
        private readonly AdobeProductMediaRemoteStateClient $remoteStateClient,
        private readonly AdobeProductMediaMetadataComparator $metadataComparator,
    ) {}

    /**
     * @param  array<string, list<array{metadata: AdobeProductRemoteMediaMetadataEntry, content: AdobeProductRemoteMediaContentEntry}>>  $remoteContentIndexByHash
     * @param  array<string, AdobeProductRemoteMediaContentEntry>  $remoteContentByFilename
     */
    public function execute(
        AdobePaaSRequestContext $context,
        string $targetSku,
        AdobeProductMediaDesiredEntry $desired,
        array $remoteContentIndexByHash,
        array $remoteContentByFilename,
        SyncLiveConsequentialWriteGate $writeGate,
    ): AdobeProductMediaCommandEvidence {
        $matches = $remoteContentIndexByHash[$desired->contentSha256] ?? [];

        if (count($matches) > 1) {
            return $this->ambiguous($desired, 'ambiguous_matching_remote_media');
        }

        if (count($matches) === 1) {
            $match = $matches[0];

            if ($this->metadataComparator->controlledMetadataMatches($desired, $match['metadata'])) {
                return $this->applied($desired, 'content_and_metadata_match_no_op', $match['metadata']->entryId);
            }

            if (! $writeGate->permitsConsequentialWrite()) {
                return $this->notApplied($desired, 'writer_lease_expired_before_media_put');
            }

            [$putResult, $putTransportException] = $this->remoteStateClient->putMedia(
                $context,
                $targetSku,
                $match['metadata']->entryId,
                $desired,
                $match['metadata'],
            );

            return $this->reconcileAfterMutation(
                $context,
                $targetSku,
                $desired,
                $match['metadata']->entryId,
                consequentialWriteAttempts: 1,
                reasonPrefix: 'media_put',
                putResult: $putResult,
                putTransportException: $putTransportException,
            );
        }

        $filenameCollision = $remoteContentByFilename[$desired->filename] ?? null;

        if ($filenameCollision !== null
            && $filenameCollision->contentSha256 !== $desired->contentSha256
        ) {
            return $this->ambiguous($desired, 'filename_content_collision');
        }

        if (! $writeGate->permitsConsequentialWrite()) {
            return $this->notApplied($desired, 'writer_lease_expired_before_media_post');
        }

        [$postResult, $postTransportException] = $this->remoteStateClient->postMedia(
            $context,
            $targetSku,
            $desired,
        );

        return $this->reconcileAfterCreate(
            $context,
            $targetSku,
            $desired,
            postResult: $postResult,
            postTransportException: $postTransportException,
        );
    }

    private function reconcileAfterCreate(
        AdobePaaSRequestContext $context,
        string $targetSku,
        AdobeProductMediaDesiredEntry $desired,
        ?ConnectorHttpResult $postResult,
        ?ConnectorTransportException $postTransportException,
    ): AdobeProductMediaCommandEvidence {
        $metadataIndex = $this->remoteStateClient->readMetadataIndexWithContext($context, $targetSku);

        if (! $metadataIndex->isTrusted()) {
            return $this->ambiguous($desired, 'media_post_reconciliation_metadata_untrusted', 1);
        }

        $candidateEntryId = $this->resolveCreatedEntryId($postResult, $metadataIndex, $desired);

        if ($candidateEntryId === null) {
            return $this->ambiguous($desired, 'media_post_reconciliation_entry_unresolved', 1, 1);
        }

        return $this->reconcileAfterMutation(
            $context,
            $targetSku,
            $desired,
            $candidateEntryId,
            consequentialWriteAttempts: 1,
            reasonPrefix: 'media_post',
            putResult: $postResult,
            putTransportException: $postTransportException,
        );
    }

    private function reconcileAfterMutation(
        AdobePaaSRequestContext $context,
        string $targetSku,
        AdobeProductMediaDesiredEntry $desired,
        int $entryId,
        int $consequentialWriteAttempts,
        string $reasonPrefix,
        ?ConnectorHttpResult $putResult,
        ?ConnectorTransportException $putTransportException,
    ): AdobeProductMediaCommandEvidence {
        if ($putTransportException !== null
            || $putResult === null
            || $putResult->statusCode < 200
            || $putResult->statusCode >= 300
        ) {
            $reasonPrefix .= '_inconclusive';
        }

        $content = $this->remoteStateClient->readMediaContent($context, $targetSku, $entryId);

        if (! $content->isTrusted()) {
            return $this->ambiguous(
                $desired,
                $reasonPrefix.'_reconciliation_content_untrusted',
                $consequentialWriteAttempts,
                1,
            );
        }

        if ($content->contentSha256 !== $desired->contentSha256) {
            return $this->ambiguous(
                $desired,
                $reasonPrefix.'_reconciliation_content_mismatch',
                $consequentialWriteAttempts,
                1,
            );
        }

        $metadataIndex = $this->remoteStateClient->readMetadataIndexWithContext($context, $targetSku);

        if (! $metadataIndex->isTrusted()) {
            return $this->ambiguous(
                $desired,
                $reasonPrefix.'_reconciliation_metadata_untrusted',
                $consequentialWriteAttempts,
                1,
            );
        }

        $metadata = $this->findMetadataEntry($metadataIndex, $entryId);

        if ($metadata === null) {
            return $this->ambiguous(
                $desired,
                $reasonPrefix.'_reconciliation_metadata_missing',
                $consequentialWriteAttempts,
                1,
            );
        }

        if (! $this->metadataComparator->controlledMetadataMatches($desired, $metadata)) {
            return $this->ambiguous(
                $desired,
                $reasonPrefix.'_reconciliation_metadata_mismatch',
                $consequentialWriteAttempts,
                1,
                $entryId,
            );
        }

        return $this->applied(
            $desired,
            $reasonPrefix.'_reconciled',
            $entryId,
            $consequentialWriteAttempts,
            1,
        );
    }

    /**
     * @param  list<AdobeProductRemoteMediaMetadataEntry>  $entries
     */
    private function resolveCreatedEntryId(
        ?ConnectorHttpResult $postResult,
        AdobeProductRemoteMediaMetadataIndex $metadataIndex,
        AdobeProductMediaDesiredEntry $desired,
    ): ?int {
        if ($postResult !== null) {
            $payload = json_decode($postResult->body, true);

            if (is_array($payload)) {
                $entry = $payload['entry'] ?? $payload;

                if (is_array($entry)) {
                    $entryId = $entry['id'] ?? null;

                    if (is_int($entryId) || (is_string($entryId) && ctype_digit($entryId))) {
                        return (int) $entryId;
                    }
                }
            }
        }

        foreach ($metadataIndex->entries as $entry) {
            if ($entry->file === '/'.$desired->filename || str_ends_with($entry->file, '/'.$desired->filename)) {
                return $entry->entryId;
            }
        }

        return null;
    }

    private function findMetadataEntry(
        AdobeProductRemoteMediaMetadataIndex $metadataIndex,
        int $entryId,
    ): ?AdobeProductRemoteMediaMetadataEntry {
        foreach ($metadataIndex->entries as $entry) {
            if ($entry->entryId === $entryId) {
                return $entry;
            }
        }

        return null;
    }

    private function applied(
        AdobeProductMediaDesiredEntry $desired,
        string $reasonCode,
        ?int $entryId = null,
        int $consequentialWriteAttempts = 0,
        int $reconciliationGetAttempts = 0,
    ): AdobeProductMediaCommandEvidence {
        return new AdobeProductMediaCommandEvidence(
            declarationIndex: $desired->declarationIndex,
            role: $desired->role,
            appliedStateKnowledge: AdobeProductAppliedStateKnowledge::KnownApplied,
            reasonCode: $reasonCode,
            mimeType: $desired->mimeType,
            mediaEntryId: $entryId,
            consequentialWriteAttempts: $consequentialWriteAttempts,
            reconciliationGetAttempts: $reconciliationGetAttempts,
            contentSha256Prefix: substr($desired->contentSha256, 0, 8),
        );
    }

    private function notApplied(
        AdobeProductMediaDesiredEntry $desired,
        string $reasonCode,
    ): AdobeProductMediaCommandEvidence {
        return new AdobeProductMediaCommandEvidence(
            declarationIndex: $desired->declarationIndex,
            role: $desired->role,
            appliedStateKnowledge: AdobeProductAppliedStateKnowledge::KnownNotApplied,
            reasonCode: $reasonCode,
            mimeType: $desired->mimeType,
            contentSha256Prefix: substr($desired->contentSha256, 0, 8),
        );
    }

    private function ambiguous(
        AdobeProductMediaDesiredEntry $desired,
        string $reasonCode,
        int $consequentialWriteAttempts = 0,
        int $reconciliationGetAttempts = 0,
        ?int $entryId = null,
    ): AdobeProductMediaCommandEvidence {
        return new AdobeProductMediaCommandEvidence(
            declarationIndex: $desired->declarationIndex,
            role: $desired->role,
            appliedStateKnowledge: AdobeProductAppliedStateKnowledge::UnknownOrAmbiguous,
            reasonCode: $reasonCode,
            mimeType: $desired->mimeType,
            mediaEntryId: $entryId,
            consequentialWriteAttempts: $consequentialWriteAttempts,
            reconciliationGetAttempts: $reconciliationGetAttempts,
            contentSha256Prefix: substr($desired->contentSha256, 0, 8),
        );
    }
}
