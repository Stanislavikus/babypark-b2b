<?php

namespace Tests\Feature\Components;

use Illuminate\Support\Facades\Blade;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ClipboardCopyButtonRenderTest extends TestCase
{
    #[Test]
    public function it_renders_the_copy_payload_into_the_click_handler(): void
    {
        $html = Blade::render(<<<'BLADE'
<x-filament.clipboard-copy-button
    text="Magento specialist packet"
    label="Copy technical requirements"
    copied-label="Copied"
    failed-label="Copy failed"
    unavailable-label="Copy unavailable"
/>
BLADE);

        $this->assertStringNotContainsString('@js($text)', $html);
        $this->assertStringContainsString('Magento specialist packet', $html);
        $this->assertStringContainsString('setStatus(await copy(', $html);
    }
}
