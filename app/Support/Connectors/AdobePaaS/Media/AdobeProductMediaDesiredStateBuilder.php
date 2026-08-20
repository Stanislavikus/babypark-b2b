<?php

namespace App\Support\Connectors\AdobePaaS\Media;

final class AdobeProductMediaDesiredStateBuilder
{
    /**
     * @param  list<AdobeProductVerifiedSourceImage>  $verifiedImages
     * @return list<AdobeProductMediaDesiredEntry>
     */
    public function build(string $productLabel, array $verifiedImages): array
    {
        $seenHashes = [];
        $entries = [];

        foreach ($verifiedImages as $verifiedImage) {
            if (isset($seenHashes[$verifiedImage->contentSha256])) {
                continue;
            }

            $seenHashes[$verifiedImage->contentSha256] = true;

            $entries[] = new AdobeProductMediaDesiredEntry(
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

        return $entries;
    }
}
