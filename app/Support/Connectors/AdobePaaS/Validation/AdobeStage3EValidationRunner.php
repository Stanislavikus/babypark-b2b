<?php

namespace App\Support\Connectors\AdobePaaS\Validation;

use App\Models\ConnectorAccount;
use App\Support\Connectors\AdobePaaS\Command\AdobeConfigurableParentSkuGenerator;
use App\Support\Connectors\Transport\ConnectorHttpTransport;

/**
 * Validation-only orchestration around production Live components.
 * Not a second Live implementation — guards and harness composition only in Part 1.
 */
final class AdobeStage3EValidationRunner
{
    public function __construct(
        private readonly AdobeStage3EValidationGuard $guard,
        private readonly AdobeConfigurableParentSkuGenerator $parentSkuGenerator,
    ) {}

    public function evaluateGuards(
        ConnectorAccount $account,
        string $expectHost,
        bool $executeRealWritesRequested,
    ): AdobeStage3EValidationGuardResult {
        AdobeStage3EValidationEnvironment::assertActive();

        return $this->guard->evaluate($account, $expectHost, $executeRealWritesRequested);
    }

    public function createValidationTransportDecorator(
        ConnectorHttpTransport $delegate,
        AdobeStage3EValidationEvidenceSink $evidenceSink,
    ): AdobeStage3EValidationTransportDecorator {
        AdobeStage3EValidationEnvironment::assertActive();

        return new AdobeStage3EValidationTransportDecorator($delegate, $evidenceSink);
    }

    public function generateConfigurableParentSku(string $workspaceId, string $productId): string
    {
        return $this->parentSkuGenerator->generate($workspaceId, $productId);
    }
}
