<?php

namespace App\Support\Connectors\AdobePaaS\Media;

final class AdobeProductMediaMetadataComparator
{
    public function controlledMetadataMatches(
        AdobeProductMediaDesiredEntry $desired,
        AdobeProductRemoteMediaMetadataEntry $remote,
    ): bool {
        if ($remote->mediaType !== 'image') {
            return false;
        }

        if ($remote->label !== $desired->label) {
            return false;
        }

        if ($remote->position !== $desired->position) {
            return false;
        }

        if ($remote->disabled !== false) {
            return false;
        }

        $desiredTypes = $desired->magentoTypes();
        sort($desiredTypes);

        return $remote->types === $desiredTypes;
    }
}
