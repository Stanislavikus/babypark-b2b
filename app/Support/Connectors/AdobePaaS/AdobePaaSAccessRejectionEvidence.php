<?php

namespace App\Support\Connectors\AdobePaaS;

final class AdobePaaSAccessRejectionEvidence
{
    public static function hasStructuredResourceDenial(#[\SensitiveParameter] string $body): bool
    {
        try {
            $decoded = json_decode($body, associative: false, depth: 32, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }

        if (! $decoded instanceof \stdClass || ! ($decoded->parameters ?? null) instanceof \stdClass) {
            return false;
        }

        $resources = $decoded->parameters->resources ?? null;

        return is_string($resources) && trim($resources) !== '';
    }
}
