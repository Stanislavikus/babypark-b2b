<?php

namespace Database\Seeders;

use Illuminate\Support\Facades\DB;

/**
 * Materialize the approved active canonical subset on existing installations.
 */
class CanonicalActiveFieldSeeder extends FieldDefinitionSeeder
{
    public function run(): void
    {
        $visibility = fn (bool $admin, bool $b2b): array => [
            'admin' => $admin,
            'b2b' => $b2b,
            'channels' => [],
        ];

        DB::transaction(fn () => $this->seedDefinitions(array_merge(
            $this->canonicalProductColumnAttributes($visibility),
            $this->canonicalPlatformLibraryAttributes(),
        )));
    }
}
