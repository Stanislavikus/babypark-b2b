<?php

namespace App\Support\Connectors\AdobePaaS\Media;

final class AdobeProductSourceImageValidator
{
    /**
     * @param  list<string>  $responseContentTypes
     */
    public function validate(
        string $rawBytes,
        int $declarationIndex,
        AdobeProductMediaRole $role,
        array $responseContentTypes = [],
    ): AdobeProductSourceImageValidationResult {
        if ($rawBytes === '') {
            return AdobeProductSourceImageValidationResult::rejected('empty_source_bytes');
        }

        if (strlen($rawBytes) > AdobeProductSourceImageFetchLimits::MAX_SOURCE_RESPONSE_BYTES) {
            return AdobeProductSourceImageValidationResult::rejected('oversized_source_bytes');
        }

        $imageInfo = @getimagesizefromstring($rawBytes);

        if ($imageInfo === false || ! isset($imageInfo['mime'])) {
            return AdobeProductSourceImageValidationResult::rejected('invalid_image_bytes');
        }

        $mimeType = strtolower((string) $imageInfo['mime']);

        $extension = match ($mimeType) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            default => null,
        };

        if ($extension === null) {
            return AdobeProductSourceImageValidationResult::rejected('unsupported_image_mime');
        }

        $sha256 = hash('sha256', $rawBytes);

        return AdobeProductSourceImageValidationResult::accepted(new AdobeProductVerifiedSourceImage(
            declarationIndex: $declarationIndex,
            role: $role,
            contentSha256: $sha256,
            mimeType: $mimeType,
            filename: 'b2b-'.$sha256.'.'.$extension,
            rawBytes: $rawBytes,
        ));
    }
}
