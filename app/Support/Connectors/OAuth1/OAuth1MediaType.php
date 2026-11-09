<?php

namespace App\Support\Connectors\OAuth1;

use App\Support\Connectors\OAuth1\Exceptions\OAuth1StructuralException;

final class OAuth1MediaType
{
    public static function isFormUrlEncoded(?string $contentType): bool
    {
        if ($contentType === null) {
            return false;
        }

        $contentType = trim($contentType);

        if ($contentType === '') {
            return false;
        }

        $segments = explode(';', $contentType, 2);
        $mediaType = trim($segments[0]);

        if (! str_contains($mediaType, '/')) {
            throw new OAuth1StructuralException('Malformed Content-Type media type.');
        }

        [$type, $subtype] = explode('/', $mediaType, 2);

        if ($type === '' || $subtype === '') {
            throw new OAuth1StructuralException('Malformed Content-Type media type.');
        }

        return strtolower($type) === 'application'
            && strtolower($subtype) === 'x-www-form-urlencoded';
    }
}
