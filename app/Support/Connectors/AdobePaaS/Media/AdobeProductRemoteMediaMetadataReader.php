<?php

namespace App\Support\Connectors\AdobePaaS\Media;

final class AdobeProductRemoteMediaMetadataReader
{
    /**
     * @param  array<string, mixed>  $productPayload
     */
    public function read(array $productPayload): AdobeProductRemoteMediaMetadataIndex
    {
        $gallery = $productPayload['media_gallery_entries'] ?? null;

        if ($gallery === null) {
            return AdobeProductRemoteMediaMetadataIndex::trusted([]);
        }

        if (! is_array($gallery)) {
            return AdobeProductRemoteMediaMetadataIndex::untrusted('malformed_media_gallery_entries');
        }

        $entries = [];
        $imageEntryCount = 0;

        foreach ($gallery as $entry) {
            if (! is_array($entry)) {
                return AdobeProductRemoteMediaMetadataIndex::untrusted('malformed_media_gallery_entry');
            }

            $mediaType = $entry['media_type'] ?? null;

            if (! is_string($mediaType) || $mediaType === '') {
                return AdobeProductRemoteMediaMetadataIndex::untrusted('malformed_media_gallery_entry_identity');
            }

            if ($mediaType !== 'image') {
                continue;
            }

            $imageEntryCount++;

            if ($imageEntryCount > AdobeProductMediaApiLimits::MAX_IMAGE_METADATA_ENTRIES) {
                return AdobeProductRemoteMediaMetadataIndex::untrusted('remote_media_metadata_exceeds_bounded_scan');
            }

            $parsed = $this->parseImageEntry($entry);

            if ($parsed === null) {
                return AdobeProductRemoteMediaMetadataIndex::untrusted('malformed_media_gallery_entry_identity');
            }

            $entries[] = $parsed;
        }

        return AdobeProductRemoteMediaMetadataIndex::trusted($entries);
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    public function parseImageEntryFromIndividualGet(array $entry): ?AdobeProductRemoteMediaMetadataEntry
    {
        return $this->parseImageEntry($entry);
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function parseImageEntry(array $entry): ?AdobeProductRemoteMediaMetadataEntry
    {
        $entryId = $entry['id'] ?? null;
        $mediaType = $entry['media_type'] ?? null;
        $file = $entry['file'] ?? null;
        $label = $entry['label'] ?? null;
        $position = $entry['position'] ?? null;
        $disabled = $entry['disabled'] ?? null;
        $types = $entry['types'] ?? null;

        if (! is_int($entryId) && ! (is_string($entryId) && ctype_digit($entryId))) {
            return null;
        }

        if ($mediaType !== 'image') {
            return null;
        }

        if (! is_string($file) || $file === '') {
            return null;
        }

        if (! is_string($label)) {
            return null;
        }

        if (! is_int($position) && ! (is_string($position) && ctype_digit($position))) {
            return null;
        }

        if (! is_bool($disabled)) {
            return null;
        }

        if (! is_array($types)) {
            return null;
        }

        $normalizedTypes = [];

        foreach ($types as $type) {
            if (! is_string($type)) {
                return null;
            }

            $normalizedTypes[] = $type;
        }

        sort($normalizedTypes);

        return new AdobeProductRemoteMediaMetadataEntry(
            entryId: (int) $entryId,
            mediaType: $mediaType,
            file: $file,
            label: $label,
            position: (int) $position,
            disabled: $disabled,
            types: $normalizedTypes,
        );
    }
}
