<?php

namespace Tests\Unit;

use App\Services\Pricing\MoneyFormatter;
use Tests\TestCase;

class MoneyFormatterTest extends TestCase
{
    private MoneyFormatter $formatter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->formatter = app(MoneyFormatter::class);
    }

    public function test_formats_uah_with_hryvnia_symbol(): void
    {
        $this->assertSame('90,00 ₴', $this->formatter->format(90.0, 'UAH'));
        $this->assertSame('1 249,50 ₴', $this->formatter->format(1249.5, 'UAH'));
    }

    public function test_formats_non_uah_with_currency_code(): void
    {
        $this->assertSame('100,00 USD', $this->formatter->format(100.0, 'USD'));
        $this->assertSame('50,00 EUR', $this->formatter->format(50.0, 'EUR'));
    }
}
