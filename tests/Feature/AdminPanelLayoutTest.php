<?php

namespace Tests\Feature;

use Filament\Facades\Filament;
use Filament\Support\Enums\Width;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AdminPanelLayoutTest extends TestCase
{
    #[Test]
    public function admin_panel_uses_full_available_content_width(): void
    {
        $panel = Filament::getPanel('admin');

        $this->assertSame(Width::Full, $panel->getMaxContentWidth());
    }
}
