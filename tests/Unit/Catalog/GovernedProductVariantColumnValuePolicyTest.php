<?php

namespace Tests\Unit\Catalog;

use App\Exceptions\Catalog\ColumnFieldNotAllowlistedException;
use App\Services\Catalog\GovernedProductVariantColumnValuePolicy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class GovernedProductVariantColumnValuePolicyTest extends TestCase
{
    #[Test]
    public function unknown_field_code_fails_closed(): void
    {
        $policy = new GovernedProductVariantColumnValuePolicy;

        $this->expectException(ColumnFieldNotAllowlistedException::class);

        $policy->normalizeSetValue('sku', 'ABC-123');
    }
}
