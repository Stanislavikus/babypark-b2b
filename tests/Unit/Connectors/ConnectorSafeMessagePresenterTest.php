<?php

namespace Tests\Unit\Connectors;

use App\Support\Connectors\ConnectorSafeMessagePresenter;
use Illuminate\Support\Facades\Lang;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ConnectorSafeMessagePresenterTest extends TestCase
{
    private ConnectorSafeMessagePresenter $presenter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->presenter = new ConnectorSafeMessagePresenter;
    }

    #[Test]
    public function known_key_renders_translated_text(): void
    {
        $this->assertSame(
            __('connectors.errors.invalid_credentials'),
            $this->presenter->present('connectors.errors.invalid_credentials'),
        );
    }

    #[Test]
    public function malformed_key_renders_generic_fallback(): void
    {
        $result = $this->presenter->present('connectors.errors.totally_unknown_key');

        $this->assertSame(__('connectors.errors.connection_check_failed'), $result);
        $this->assertStringNotContainsString('totally_unknown_key', $result);
    }

    #[Test]
    public function non_connector_prefix_renders_generic_fallback(): void
    {
        $result = $this->presenter->present('evil.vendor.payload');

        $this->assertSame(__('connectors.errors.connection_check_failed'), $result);
        $this->assertStringNotContainsString('evil.vendor.payload', $result);
    }

    #[Test]
    public function technical_summary_is_never_interpolated(): void
    {
        $result = $this->presenter->present(
            'connectors.errors.invalid_credentials',
            ['technical_summary' => 'SECRET_TECH_SUMMARY'],
        );

        $this->assertStringNotContainsString('SECRET_TECH_SUMMARY', $result);
    }

    #[Test]
    public function safe_parameters_interpolate_when_present_in_translation(): void
    {
        app()->setLocale('en');

        Lang::addLines([
            'connectors.errors.test_param' => 'Safe :name value',
        ], 'en');

        $result = $this->presenter->present('connectors.errors.test_param', ['name' => 'Alice']);

        $this->assertSame('Safe Alice value', $result);
    }

    #[Test]
    public function empty_key_renders_generic_fallback(): void
    {
        $this->assertSame(
            __('connectors.errors.connection_check_failed'),
            $this->presenter->present(''),
        );
    }

    #[Test]
    public function is_allowed_key_uses_lang_has(): void
    {
        $this->assertTrue($this->presenter->isAllowedKey('connectors.errors.invalid_credentials'));
        $this->assertFalse($this->presenter->isAllowedKey('connectors.errors.missing_key_xyz'));
    }
}
