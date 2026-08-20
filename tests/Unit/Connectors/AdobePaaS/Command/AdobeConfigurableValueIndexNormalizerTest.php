<?php

namespace Tests\Unit\Connectors\AdobePaaS\Command;

use App\Support\Connectors\AdobePaaS\Command\AdobeConfigurableValueIndexNormalizer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdobeConfigurableValueIndexNormalizerTest extends TestCase
{
    #[Test]
    public function accepts_zero_as_integer_and_string(): void
    {
        $this->assertSame(0, AdobeConfigurableValueIndexNormalizer::normalize(0));
        $this->assertSame(0, AdobeConfigurableValueIndexNormalizer::normalize('0'));
    }

    #[Test]
    public function accepts_positive_integer_and_canonical_decimal_string(): void
    {
        $this->assertSame(93, AdobeConfigurableValueIndexNormalizer::normalize(93));
        $this->assertSame(93, AdobeConfigurableValueIndexNormalizer::normalize('93'));
    }

    #[Test]
    public function rejects_negative_decimal_scientific_and_non_numeric_forms(): void
    {
        $this->assertNull(AdobeConfigurableValueIndexNormalizer::normalize(-1));
        $this->assertNull(AdobeConfigurableValueIndexNormalizer::normalize('-1'));
        $this->assertNull(AdobeConfigurableValueIndexNormalizer::normalize('1.5'));
        $this->assertNull(AdobeConfigurableValueIndexNormalizer::normalize(1.5));
        $this->assertNull(AdobeConfigurableValueIndexNormalizer::normalize('1e3'));
        $this->assertNull(AdobeConfigurableValueIndexNormalizer::normalize('abc'));
        $this->assertNull(AdobeConfigurableValueIndexNormalizer::normalize(''));
    }

    #[Test]
    public function rejects_integer_overflow_strings(): void
    {
        $overflow = (string) (PHP_INT_MAX + 1);

        $this->assertNull(AdobeConfigurableValueIndexNormalizer::normalize($overflow));
    }
}
