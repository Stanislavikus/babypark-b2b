<?php

namespace Tests\Feature\Sync;

use Illuminate\Contracts\Console\Kernel;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class Stage3ES3DisposableValidationHarnessCommandAvailabilityTest extends TestCase
{
    #[Test]
    public function command_is_absent_outside_stage3e_validation_environment(): void
    {
        $commands = app(Kernel::class)->all();

        $this->assertArrayNotHasKey('adobe:stage3e-validate', $commands);
        $this->assertFalse(app()->environment('stage3e-validation'));
    }
}
