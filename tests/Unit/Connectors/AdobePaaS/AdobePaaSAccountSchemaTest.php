<?php

namespace Tests\Unit\Connectors\AdobePaaS;

use App\Support\Connectors\AdobePaaS\AdobePaaSAccountSchema;
use App\Support\Connectors\AdobePaaS\AdobePaaSConnectionCheckRequestFactory;
use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContext;
use App\Support\Connectors\AdobePaaS\AdobePaaSSettingsInput;
use App\Support\Connectors\AdobePaaS\Exceptions\InvalidAdobePaaSRequestContextException;
use App\Support\Connectors\ConnectorAccountMutationMode;
use App\Support\Connectors\ConnectorAccountSettingsInput;
use App\Support\Connectors\CredentialMutation;
use App\Support\Connectors\Exceptions\ConnectorAccountProfileInputMismatchException;
use App\Support\Connectors\Exceptions\ConnectorAccountSettingsValidationException;
use App\Support\Connectors\Exceptions\InvalidCredentialMutationException;
use App\Support\Connectors\OAuth1\OAuth1Credentials;
use App\Support\Connectors\OAuth1\OAuth1RequestSigner;
use App\Support\Connectors\OAuth1\OAuth1SigningContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Unit\Connectors\OAuth1\AssertsOAuth1SecretsSafely;

class AdobePaaSAccountSchemaTest extends TestCase
{
    use AssertsOAuth1SecretsSafely;

    private AdobePaaSAccountSchema $schema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->schema = new AdobePaaSAccountSchema;
    }

    #[Test]
    public function accepts_valid_adobe_paas_settings(): void
    {
        $state = $this->schema->validate(
            new AdobePaaSSettingsInput('https://shop.example.com', 'default_store', 'tenant-a'),
            CredentialMutation::keep(),
            ConnectorAccountMutationMode::Update,
        );

        $this->assertSame('https://shop.example.com', $state->baseUrl);
        $this->assertSame('default_store', $state->storeCode);
        $this->assertSame('tenant-a', $state->tenantContext);
        $this->assertSame([], $state->settings);
    }

    #[Test]
    public function rejects_store_code_with_disallowed_characters(): void
    {
        $this->expectException(ConnectorAccountSettingsValidationException::class);

        $this->schema->validate(
            new AdobePaaSSettingsInput('https://shop.example.com', '1invalid', null),
            CredentialMutation::keep(),
            ConnectorAccountMutationMode::Update,
        );
    }

    #[Test]
    public function accepts_store_code_with_allowed_character_set(): void
    {
        $state = $this->schema->validate(
            new AdobePaaSSettingsInput('https://shop.example.com', 'Store_01', null),
            CredentialMutation::keep(),
            ConnectorAccountMutationMode::Update,
        );

        $this->assertSame('Store_01', $state->storeCode);
    }

    #[Test]
    public function rejects_mismatched_settings_input_type(): void
    {
        $this->expectException(ConnectorAccountProfileInputMismatchException::class);

        $this->schema->validate(
            new MismatchedSettingsInput,
            CredentialMutation::keep(),
            ConnectorAccountMutationMode::Update,
        );
    }

    #[Test]
    public function rejects_remove_mutation_in_create_mode(): void
    {
        $this->expectException(InvalidCredentialMutationException::class);

        $this->schema->validate(
            new AdobePaaSSettingsInput('https://shop.example.com', 'default', null),
            CredentialMutation::remove(),
            ConnectorAccountMutationMode::Create,
        );
    }

    #[Test]
    public function factory_and_schema_reject_the_same_malformed_base_url(): void
    {
        $malformedUrl = 'https://shop.example.com?foo=bar';
        $credentials = new OAuth1Credentials('ck', 'cs', 'at', 'ts');
        $factory = new AdobePaaSConnectionCheckRequestFactory(new OAuth1RequestSigner);

        try {
            $this->schema->validate(
                new AdobePaaSSettingsInput($malformedUrl, 'default', null),
                CredentialMutation::keep(),
                ConnectorAccountMutationMode::Update,
            );
            $this->fail('Schema validation should reject malformed base URL.');
        } catch (InvalidAdobePaaSRequestContextException $schemaException) {
            try {
                $factory->build(
                    new AdobePaaSRequestContext($malformedUrl, 'default', $credentials),
                    new OAuth1SigningContext('nonce', 1_700_000_000),
                );
                $this->fail('Request factory should reject malformed base URL.');
            } catch (InvalidAdobePaaSRequestContextException $factoryException) {
                $this->assertSame($schemaException->getMessage(), $factoryException->getMessage());
            }
        }
    }
}

final class MismatchedSettingsInput implements ConnectorAccountSettingsInput {}
