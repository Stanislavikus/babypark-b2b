<?php

namespace App\Support\Sync;

use App\Enums\SyncConfigurationOperationalState;

final class SyncConfigurationRevisionHasher
{
    private const PREFIX = 'babypark.sync-configuration-revision.v1';

    public function hash(
        SyncOperationSet $enabledOperations,
        SyncConfigurationOperationalState $operationalState,
    ): string {
        $payload = new \stdClass;
        $payload->enabled_operations = $enabledOperations->values();
        $payload->operational_state = $operationalState->value;

        $json = $this->encodeCanonicalJson($this->sortObjectKeysRecursively($payload));

        return hash('sha256', self::PREFIX."\n".$json);
    }

    private function encodeCanonicalJson(mixed $value): string
    {
        try {
            return json_encode(
                $value,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
        } catch (\JsonException $exception) {
            throw new \RuntimeException(
                'Sync configuration revision payload could not be encoded as canonical JSON.',
                previous: $exception,
            );
        }
    }

    private function sortObjectKeysRecursively(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $array = (array) $value;
            ksort($array, SORT_STRING);

            $result = new \stdClass;

            foreach ($array as $key => $nested) {
                $result->{$key} = $this->sortObjectKeysRecursively($nested);
            }

            return $result;
        }

        if (is_array($value)) {
            return array_map(
                fn (mixed $item): mixed => $this->sortObjectKeysRecursively($item),
                $value,
            );
        }

        return $value;
    }
}
