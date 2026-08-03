<?php

namespace App\Support\Connectors;

final class ConnectorSchemaSourceEndpointPathValidator
{
    private const int MAX_DECODE_ITERATIONS = 4;

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

            if (! $this->segmentIsSafeAfterBoundedDecoding($segment)) {
                return false;
            }
        }

        return true;
    }

    private function segmentIsSafeAfterBoundedDecoding(string $segment): bool
    {
        $current = $segment;

        for ($iteration = 0; $iteration < self::MAX_DECODE_ITERATIONS; $iteration++) {
            if (! $this->decodedSegmentValueIsSafe($current)) {
                return false;
            }

            if ($this->containsEncodedTraversalOrSeparator($current)) {
                $decoded = rawurldecode($current);

                if ($decoded === $current) {
                    return false;
                }

                $current = $decoded;

                continue;
            }

            return true;
        }

        if ($this->containsEncodedTraversalOrSeparator($current)) {
            return false;
        }

        return $this->decodedSegmentValueIsSafe($current);
    }

    private function decodedSegmentValueIsSafe(string $value): bool
    {
        if ($value === '.' || $value === '..') {
            return false;
        }

        if (str_contains($value, '/') || str_contains($value, '\\')) {
            return false;
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            return false;
        }

        return true;
    }

    private function containsEncodedTraversalOrSeparator(string $value): bool
    {
        return preg_match('/%(?:2f|2F|5c|5C|2e|2E)/', $value) === 1;
    }
}
