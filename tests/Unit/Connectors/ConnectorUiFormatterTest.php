<?php

namespace Tests\Unit\Connectors;

use App\Support\Connectors\ConnectorUiFormatter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ConnectorUiFormatterTest extends TestCase
{
    #[Test]
    public function format_duration_uses_locale_decimal_separator(): void
    {
        app()->setLocale('en');
        $this->assertSame(
            __('connectors.ui.duration.seconds', ['value' => '1.5']),
            ConnectorUiFormatter::formatDuration(1500),
        );

        app()->setLocale('uk');
        $this->assertSame(
            __('connectors.ui.duration.seconds', ['value' => '1,5']),
            ConnectorUiFormatter::formatDuration(1500),
        );
    }

    #[Test]
    public function format_date_time_uses_locale_aware_output(): void
    {
        app()->setLocale('en');
        $english = ConnectorUiFormatter::formatDateTime('2026-07-27 14:30:00');
        $this->assertStringContainsString('2026', (string) $english);
        $this->assertStringNotContainsString('d.m.Y', (string) $english);

        app()->setLocale('uk');
        $ukrainian = ConnectorUiFormatter::formatDateTime('2026-07-27 14:30:00');
        $this->assertNotSame($english, $ukrainian);
    }
}
