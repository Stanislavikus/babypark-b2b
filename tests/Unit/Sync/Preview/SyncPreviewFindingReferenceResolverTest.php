<?php

namespace Tests\Unit\Sync\Preview;

use App\Enums\SyncPreviewFindingCode;
use App\Support\Sync\Preview\Presentation\SyncPreviewFindingReferenceResolver;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SyncPreviewFindingReferenceResolverTest extends TestCase
{
    private SyncPreviewFindingReferenceResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new SyncPreviewFindingReferenceResolver;
    }

    #[Test]
    public function missing_mapped_product_value_uses_subject_as_field_binding_id(): void
    {
        $reference = $this->resolver->resolve([
            'code' => SyncPreviewFindingCode::MissingMappedProductValue->value,
            'subject' => 'binding-123',
        ]);

        $this->assertSame('binding-123', $reference->fieldBindingId);
        $this->assertNull($reference->variantId);
        $this->assertFalse($reference->showsVariantContext);
    }

    #[Test]
    public function missing_option_mapping_uses_subject_binding_and_context_option_key(): void
    {
        $reference = $this->resolver->resolve([
            'code' => SyncPreviewFindingCode::MissingOptionMapping->value,
            'subject' => 'binding-color',
            'context' => ['internal_option_key' => 'red'],
        ]);

        $this->assertSame('binding-color', $reference->fieldBindingId);
        $this->assertSame('red', $reference->internalOptionKey);
        $this->assertFalse($reference->showsVariantContext);
    }

    #[Test]
    public function missing_mapped_variant_value_uses_subject_variant_and_context_binding(): void
    {
        $reference = $this->resolver->resolve([
            'code' => SyncPreviewFindingCode::MissingMappedVariantValue->value,
            'subject' => 'variant-1',
            'context' => ['field_binding_id' => 'binding-size'],
        ]);

        $this->assertSame('variant-1', $reference->variantId);
        $this->assertSame('binding-size', $reference->fieldBindingId);
        $this->assertTrue($reference->showsVariantContext);
    }

    #[Test]
    public function missing_name_does_not_treat_product_subject_as_variant(): void
    {
        $reference = $this->resolver->resolve([
            'code' => SyncPreviewFindingCode::MissingName->value,
            'subject' => 'product-uuid',
        ]);

        $this->assertSame('product-uuid', $reference->productId);
        $this->assertNull($reference->variantId);
        $this->assertFalse($reference->showsVariantContext);
    }

    #[Test]
    public function attribute_set_findings_do_not_identify_variants(): void
    {
        $reference = $this->resolver->resolve([
            'code' => SyncPreviewFindingCode::AttributeSetInvalid->value,
            'subject' => '4',
        ]);

        $this->assertNull($reference->variantId);
        $this->assertFalse($reference->showsVariantContext);
    }
}
