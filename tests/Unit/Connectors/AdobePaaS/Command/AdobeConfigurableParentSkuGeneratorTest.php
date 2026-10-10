<?php

namespace Tests\Unit\Connectors\AdobePaaS\Command;

use App\Support\Connectors\AdobePaaS\Command\AdobeConfigurableParentSkuGenerator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdobeConfigurableParentSkuGeneratorTest extends TestCase
{
    private AdobeConfigurableParentSkuGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->generator = new AdobeConfigurableParentSkuGenerator;
    }

    #[Test]
    public function fixed_vector_workspace_and_product_produce_expected_parent_sku(): void
    {
        $workspaceId = '11111111-1111-1111-1111-111111111111';
        $productId = 42;

        $identityInput = 'adobe-configurable-parent:v1|'.$workspaceId.'|'.$productId;
        $expectedDigest = hash('sha256', $identityInput);
        $expectedSku = 'cfg-'.substr($expectedDigest, 0, 60);

        $this->assertSame($expectedSku, $this->generator->generate($workspaceId, $productId));
    }

    #[Test]
    public function same_workspace_and_product_are_stable(): void
    {
        $workspaceId = '22222222-2222-2222-2222-222222222222';
        $productId = 7;

        $first = $this->generator->generate($workspaceId, $productId);
        $second = $this->generator->generate($workspaceId, $productId);

        $this->assertSame($first, $second);
    }

    #[Test]
    public function different_products_produce_different_parent_skus(): void
    {
        $workspaceId = '33333333-3333-3333-3333-333333333333';

        $this->assertNotSame(
            $this->generator->generate($workspaceId, 1),
            $this->generator->generate($workspaceId, 2),
        );
    }

    #[Test]
    public function different_workspaces_produce_different_parent_skus(): void
    {
        $productId = 99;

        $this->assertNotSame(
            $this->generator->generate('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', $productId),
            $this->generator->generate('bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb', $productId),
        );
    }

    #[Test]
    public function parent_sku_is_at_most_64_characters_and_safe_charset(): void
    {
        $sku = $this->generator->generate('cccccccc-cccc-cccc-cccc-cccccccccccc', 123456789);

        $this->assertLessThanOrEqual(64, strlen($sku));
        $this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', $sku);
        $this->assertStringStartsWith('cfg-', $sku);
    }

    #[Test]
    public function product_id_is_treated_as_integer_identity_not_uuid(): void
    {
        $workspaceId = 'dddddddd-dddd-dddd-dddd-dddddddddddd';

        $this->assertSame(
            $this->generator->generate($workspaceId, 5),
            $this->generator->generate($workspaceId, 5),
        );

        $this->assertNotSame(
            $this->generator->generate($workspaceId, 5),
            $this->generator->generate($workspaceId, 6),
        );
    }
}
