<?php

namespace Tests\Feature\Cabinet;

use Tests\TestCase;

class LegacyGuardRemovalTest extends TestCase
{
    public function test_legacy_contractor_guard_is_fully_removed(): void
    {
        // Legacy guard removal check — intentional literal 'contractor' below.
        $this->assertArrayNotHasKey('contractor', config('auth.guards'));
        $this->assertArrayNotHasKey('contractors', config('auth.providers'));
    }
}
