<?php

namespace App\Support\Connectors\AdobePaaS\Media;

final class AdobeProductMediaDesiredStateBuilder
{
    public function buildEntry(
        string $productLabel,
        AdobeProductVerifiedSourceImage $verifiedImage,
    ): AdobeProductMediaDesiredEntry {
        return new AdobeProductMediaDesiredEntry(
            declarationIndex: $verifiedImage->declarationIndex,
            role: $verifiedImage->role,
            label: $productLabel,
            position: $verifiedImage->position(),
            contentSha256: $verifiedImage->contentSha256,
            mimeType: $verifiedImage->mimeType,
            filename: $verifiedImage->filename,
            rawBytes: $verifiedImage->rawBytes,
        );
    }
}
