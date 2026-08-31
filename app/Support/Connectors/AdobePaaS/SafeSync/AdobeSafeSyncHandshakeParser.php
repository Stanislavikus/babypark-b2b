<?php

namespace App\Support\Connectors\AdobePaaS\SafeSync;

use JsonException;

final class AdobeSafeSyncHandshakeParser
{
    public function parse(string $body): AdobeSafeSyncHandshake
    {
        try {
            $payload = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new AdobeSafeSyncClientException('Safe Sync returned malformed JSON.', 0, $exception);
        }

        if (! is_array($payload) || array_is_list($payload)) {
            throw new AdobeSafeSyncClientException('Safe Sync response must be a JSON object.');
        }

        $contractVersion = $this->requireString($payload, 'contract_version');
        $moduleVersion = $this->requireString($payload, 'module_version');
        $families = $this->requireStringList($payload, 'supported_operation_families');

        if (
            $moduleVersion === '0.0.0'
            || trim($moduleVersion) !== $moduleVersion
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9.+_-]*$/', $moduleVersion) !== 1
        ) {
            throw new AdobeSafeSyncClientException('Safe Sync module version is invalid.');
        }

        return new AdobeSafeSyncHandshake($contractVersion, $moduleVersion, $families);
    }

    /** @param array<string, mixed> $payload */
    private function requireString(array $payload, string $key): string
    {
        if (! array_key_exists($key, $payload) || ! is_string($payload[$key]) || $payload[$key] === '') {
            throw new AdobeSafeSyncClientException(sprintf('Safe Sync response field `%s` is invalid.', $key));
        }

        return $payload[$key];
    }

    /** @param array<string, mixed> $payload @return list<string> */
    private function requireStringList(array $payload, string $key): array
    {
        if (! array_key_exists($key, $payload) || ! is_array($payload[$key]) || ! array_is_list($payload[$key])) {
            throw new AdobeSafeSyncClientException(sprintf('Safe Sync response field `%s` is invalid.', $key));
        }

        foreach ($payload[$key] as $value) {
            if (! is_string($value) || $value === '') {
                throw new AdobeSafeSyncClientException(sprintf('Safe Sync response field `%s` is invalid.', $key));
            }
        }

        return array_values($payload[$key]);
    }
}
