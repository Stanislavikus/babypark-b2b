<?php

namespace App\Services\Sync;

use App\Models\ConnectorAccount;
use App\Models\FieldMapping;
use App\Support\Connectors\AdobePaaS\AdobeFieldOptionMappingOptionValidator;
use App\Support\Connectors\ConnectorProfileRegistry;
use App\Support\Sync\FieldOptionMappingOptionValidator;
use Illuminate\Contracts\Container\Container;

final class FieldOptionMappingOptionValidatorResolver
{
    public function __construct(
        private readonly ConnectorProfileRegistry $profileRegistry,
        private readonly Container $container,
        private readonly FieldDefinitionInternalOptionValidator $internalOptionValidator,
    ) {}

    public function resolve(ConnectorAccount $account): FieldOptionMappingOptionValidator
    {
        $definition = $this->profileRegistry->profileDefinition($account->auth_profile);

        if (
            $definition->connectorDefinitionCode === 'adobe_commerce'
            && $definition->profileCode !== 'test_sync_support'
        ) {
            return $this->container->make(AdobeFieldOptionMappingOptionValidator::class);
        }

        return new FieldDefinitionOnlyOptionValidator($this->internalOptionValidator);
    }
}

final class FieldDefinitionOnlyOptionValidator implements FieldOptionMappingOptionValidator
{
    public function __construct(
        private readonly FieldDefinitionInternalOptionValidator $internalOptionValidator,
    ) {}

    public function validate(
        ConnectorAccount $account,
        FieldMapping $mapping,
        string $internalOptionKey,
        string $externalOptionValue,
    ): void {
        $this->internalOptionValidator->validate($mapping, $internalOptionKey);
    }
}
