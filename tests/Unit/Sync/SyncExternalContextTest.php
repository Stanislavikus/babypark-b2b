<?php

namespace Tests\Unit\Sync;

use App\Support\Sync\Exceptions\SyncExternalContextValidationException;
use App\Support\Sync\SyncExternalContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SyncExternalContextTest extends TestCase
{
    #[Test]
    public function default_context_is_stable_and_non_nullable(): void
    {
        $default = SyncExternalContext::default();

        $this->assertTrue($default->isDefault());
        $this->assertSame([], $default->payload());
        $this->assertSame(
            $default->uniquenessKey(),
            SyncExternalContext::default()->uniquenessKey(),
        );
    }

    #[Test]
    public function equivalent_payloads_produce_the_same_uniqueness_key(): void
    {
        $left = SyncExternalContext::fromPayload(['scope' => 'eu', 'channel' => 'retail']);
        $right = SyncExternalContext::fromPayload(['channel' => 'retail', 'scope' => 'eu']);

        $this->assertTrue($left->equals($right));
        $this->assertSame($left->uniquenessKey(), $right->uniquenessKey());
    }

    #[Test]
    public function list_payloads_are_rejected(): void
    {
        $this->expectException(SyncExternalContextValidationException::class);

        SyncExternalContext::fromPayload(['a']);
    }
}
