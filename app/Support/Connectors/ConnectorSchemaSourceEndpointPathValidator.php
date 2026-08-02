<?php

namespace App\Support\Connectors;

final class ConnectorSchemaSourceEndpointPathValidator
{
    public function isValid(?string $endpointPath): bool
    {
        if ($endpointPath === null || $endpointPath === '') {
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

        $segments = explode('/', ltrim($endpointPath, '/'));

        foreach ($segments as $segment) {
            if ($segment === '.' || $segment === '..') {
                return false;
            }
        }

        return str_starts_with($endpointPath, '/');
    }

    public function normalize(string $endpointPath): string
    {
        if (! $this->isValid($endpointPath)) {
            throw new \InvalidArgumentException('Invalid endpoint path.');
        }

        return '/'.ltrim($endpointPath, '/');
    }
}
