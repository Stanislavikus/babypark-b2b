<?php

namespace App\Support\Connectors\AdobePaaS;

use App\Enums\SyncPreviewFindingCode;
use App\Support\Connectors\AdobePaaS\Semantic\AdobeProductExportSemanticFinding;
use App\Support\Sync\Preview\SyncPreviewFinding;

final class AdobeProductExportSemanticFindingTranslator
{
    public function translate(AdobeProductExportSemanticFinding $finding): SyncPreviewFinding
    {
        $previewCode = SyncPreviewFindingCode::tryFrom($finding->code);

        if ($previewCode === null) {
            throw new \LogicException(
                "Adobe semantic finding code [{$finding->code}] has no SyncPreviewFindingCode counterpart.",
            );
        }

        return new SyncPreviewFinding(
            code: $previewCode,
            subject: $finding->subject !== '' ? $finding->subject : null,
            context: $finding->context,
        );
    }
}
