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

    public function execute(
        AdobePaaSRequestContext $context,
        string $targetSku,
        AdobeProductMediaDesiredEntry $desired,
        AdobeProductRemoteMediaReconciliationIndex $remoteIndex,
        SyncLiveConsequentialWriteGate $writeGate,
    ): AdobeProductMediaCommandEvidence {
        $matches = $remoteIndex->entriesByContentHash[$desired->contentSha256] ?? [];

        if (count($matches) > 1) {
            return $this->ambiguous($desired, 'ambiguous_matching_remote_media');
        }

        if (count($matches) === 1) {
            $metadata = $matches[0];

            if ($this->metadataComparator->controlledMetadataMatches($desired, $metadata)) {
                return $this->applied($desired, 'content_and_metadata_match_no_op', $metadata->entryId);
            }

            if (! $writeGate->permitsConsequentialWrite()) {
                return $this->notApplied($desired, 'writer_lease_expired_before_media_put');
            }

            [$putResult, $putTransportException] = $this->remoteStateClient->putMedia(
                $context,
                $targetSku,
                $metadata->entryId,
                $desired,
                $metadata,
            );

            return $this->reconcileAfterMutation(
                $context,
                $targetSku,
                $desired,
                $metadata->entryId,
                consequentialWriteAttempts: 1,
                reasonPrefix: 'media_put',
                mutationResult: $putResult,
                mutationTransportException: $putTransportException,
            );
        }

        $filenameCollision = $remoteIndex->imageFilenameIndex[$desired->filename] ?? null;

        if ($filenameCollision !== null
            && $filenameCollision['contentSha256'] !== $desired->contentSha256
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
        $trustedEntryId = $this->parseTrustedPostResponseEntryId($postResult);

        if ($trustedEntryId !== null) {
            return $this->reconcileAfterMutation(
                $context,
                $targetSku,
                $desired,
                $trustedEntryId,
                consequentialWriteAttempts: 1,
                reasonPrefix: 'media_post',
                mutationResult: $postResult,
                mutationTransportException: $postTransportException,
            );
        }

        $reconciliationGetAttempts = 0;

        if ($postTransportException !== null
            || $postResult === null
            || $postResult->statusCode < 200
            || $postResult->statusCode >= 300
        ) {
            $reasonPrefix = 'media_post_inconclusive';
        } else {
            $reasonPrefix = 'media_post_inconclusive_body';
        }

        $metadataIndex = $this->remoteStateClient->readMetadataIndexWithContext($context, $targetSku);
        $reconciliationGetAttempts++;

        if (! $metadataIndex->isTrusted()) {
            return $this->ambiguous(
                $desired,
                $reasonPrefix.'_reconciliation_metadata_untrusted',
                1,
                $reconciliationGetAttempts,
            );
        }

        $candidateEntryId = $this->findMetadataEntryIdByFilename($metadataIndex, $desired->filename);

        if ($candidateEntryId === null) {
            return $this->ambiguous(
                $desired,
                $reasonPrefix.'_reconciliation_entry_unresolved',
                1,
                $reconciliationGetAttempts,
            );
        }

        return $this->reconcileAfterMutation(
            $context,
            $targetSku,
            $desired,
            $candidateEntryId,
            consequentialWriteAttempts: 1,
            reasonPrefix: $reasonPrefix,
            mutationResult: $postResult,
            mutationTransportException: $postTransportException,
            reconciliationGetAttemptsBeforeIndividualGet: $reconciliationGetAttempts,
        );
    }

    private function reconcileAfterMutation(
        AdobePaaSRequestContext $context,
        string $targetSku,
        AdobeProductMediaDesiredEntry $desired,
        int $entryId,
        int $consequentialWriteAttempts,
        string $reasonPrefix,
        ?ConnectorHttpResult $mutationResult,
        ?ConnectorTransportException $mutationTransportException,
        int $reconciliationGetAttemptsBeforeIndividualGet = 0,
    ): AdobeProductMediaCommandEvidence {
        if ($mutationTransportException !== null
            || $mutationResult === null
            || $mutationResult->statusCode < 200
            || $mutationResult->statusCode >= 300
        ) {
            $reasonPrefix .= '_inconclusive';
        }

        $snapshot = $this->remoteStateClient->readMediaEntrySnapshot($context, $targetSku, $entryId);
        $reconciliationGetAttempts = $reconciliationGetAttemptsBeforeIndividualGet + 1;

        if (! $snapshot->isTrusted()) {
            return $this->ambiguous(
                $desired,
                $reasonPrefix.'_reconciliation_content_untrusted',
                $consequentialWriteAttempts,
                $reconciliationGetAttempts,
                $entryId,
            );
        }

        if ($snapshot->contentSha256 !== $desired->contentSha256) {
            return $this->ambiguous(
                $desired,
                $reasonPrefix.'_reconciliation_content_mismatch',
                $consequentialWriteAttempts,
                $reconciliationGetAttempts,
                $entryId,
            );
        }

        if (! $this->metadataComparator->controlledMetadataMatches($desired, $snapshot->metadata)) {
            return $this->ambiguous(
                $desired,
                $reasonPrefix.'_reconciliation_metadata_mismatch',
                $consequentialWriteAttempts,
                $reconciliationGetAttempts,
                $entryId,
            );
        }

        return $this->applied(
            $desired,
            $reasonPrefix.'_reconciled',
            $entryId,
            $consequentialWriteAttempts,
            $reconciliationGetAttempts,
        );
    }

    private function parseTrustedPostResponseEntryId(?ConnectorHttpResult $postResult): ?int
    {
        if ($postResult === null
            || $postResult->statusCode < 200
            || $postResult->statusCode >= 300
        ) {
            return null;
        }

        $decoded = json_decode($postResult->body, true);

        if (is_int($decoded) && $decoded >= 0) {
            return $decoded;
        }

        if (is_string($decoded) && ctype_digit($decoded) && $decoded === (string) (int) $decoded) {
            return (int) $decoded;
        }

        if (is_array($decoded)) {
            $entry = $decoded['entry'] ?? $decoded;
            $entryId = is_array($entry) ? ($entry['id'] ?? null) : null;

            if (is_int($entryId) && $entryId >= 0) {
                return $entryId;
            }

            if (is_string($entryId) && ctype_digit($entryId) && $entryId === (string) (int) $entryId) {
                return (int) $entryId;
            }
        }

        return null;
    }

    private function findMetadataEntryIdByFilename(
        AdobeProductRemoteMediaMetadataIndex $metadataIndex,
        string $filename,
    ): ?int {
        foreach ($metadataIndex->entries as $entry) {
            if ($entry->file === '/'.$filename || str_ends_with($entry->file, '/'.$filename)) {
                return $entry->entryId;
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
