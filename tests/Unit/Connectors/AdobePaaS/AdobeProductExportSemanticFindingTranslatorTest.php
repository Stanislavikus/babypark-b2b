<?php

namespace Tests\Unit\Connectors\AdobePaaS;

use App\Enums\SyncPreviewFindingCode;
use App\Support\Connectors\AdobePaaS\AdobeProductExportSemanticFindingTranslator;
use App\Support\Connectors\AdobePaaS\Semantic\AdobeProductExportSemanticFinding;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdobeProductExportSemanticFindingTranslatorTest extends TestCase
{
    #[Test]
    public function translates_known_semantic_codes_to_preview_codes(): void
    {
        $translator = new AdobeProductExportSemanticFindingTranslator;

        $finding = $translator->translate(new AdobeProductExportSemanticFinding(
            code: 'missing_name',
            subject: 'product-1',
        ));

        $this->assertSame(SyncPreviewFindingCode::MissingName, $finding->code);
        $this->assertSame('product-1', $finding->subject);
    }

    #[Test]
    public function unknown_semantic_code_fails_closed(): void
    {
        $translator = new AdobeProductExportSemanticFindingTranslator;

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('has no SyncPreviewFindingCode counterpart');

        $translator->translate(new AdobeProductExportSemanticFinding(
            code: 'unmapped_semantic_code',
            subject: 'product-1',
        ));
    }
}
