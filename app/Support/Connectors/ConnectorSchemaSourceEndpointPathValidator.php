<?php

namespace App\Support\Connectors;

final class ConnectorSchemaSourceEndpointPathValidator
{
    public function isValid(?string $endpointPath): bool
    {
        if ($endpointPath === null || $endpointPath === '') {
            return false;
        }

        if (! mb_check_encoding($endpointPath, 'UTF-8')) {
            return false;
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $endpointPath) === 1) {
            return false;
        }

        if (str_contains($endpointPath, '\\')) {
            return false;
        }

        if (str_starts_with($endpointPath, '//')) {
            return false;
        }

        if (str_contains($endpointPath, '://')) {
            return false;
        }

        if (str_contains($endpointPath, '@')) {
            return false;
        }

        if (str_contains($endpointPath, '?') || str_contains($endpointPath, '#')) {
            return false;
        }

        if (preg_match('/:\d+/', $endpointPath) === 1) {
            return false;
        }

        if (! str_starts_with($endpointPath, '/')) {
            return false;
        }

        if (preg_match('/%(?:2f|2F|5c|5C|2e|2E)/', $endpointPath) === 1) {
            if ($this->containsEncodedTraversalOrSeparator($endpointPath)) {
                return false;
            }
        }

        return $this->segmentsAreValid($endpointPath);
    }

    public function normalize(string $endpointPath): string
    {
        if (! $this->isValid($endpointPath)) {
            throw new \InvalidArgumentException('Invalid endpoint path.');
        }

        return '/'.ltrim($endpointPath, '/');
    }

    private function segmentsAreValid(string $endpointPath): bool
    {
        $segments = explode('/', ltrim($endpointPath, '/'));

        foreach ($segments as $segment) {
            if ($segment === '') {
                return false;
            }

            if ($segment === '.' || $segment === '..') {
                return false;
            }

            if (preg_match('/%(?:2f|2F|5c|5C)/', $segment) === 1) {
                return false;
            }

            $decoded = rawurldecode($segment);

            if ($decoded === '.' || $decoded === '..') {
                return false;
            }

            if (str_contains($decoded, '/') || str_contains($decoded, '\\')) {
                return false;
            }

            if (preg_match('/[\x00-\x1F\x7F]/', $decoded) === 1) {
                return false;
            }
        }

        return true;
    }

    private function containsEncodedTraversalOrSeparator(string $endpointPath): bool
    {
        $segments = explode('/', ltrim($endpointPath, '/'));

        foreach ($segments as $segment) {
            if (preg_match('/%(?:2f|2F|5c|5C)/', $segment) === 1) {
                return true;
            }

            $decoded = rawurldecode($segment);

            if ($decoded === '.' || $decoded === '..') {
                return true;
            }
        }

        return false;
    }
}
