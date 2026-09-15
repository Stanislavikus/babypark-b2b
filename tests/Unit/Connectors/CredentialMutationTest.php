<?php

namespace Tests\Unit\Connectors;

use App\Support\Connectors\ConnectorAccountMutationMode;
use App\Support\Connectors\CredentialMutation;
use App\Support\Connectors\Exceptions\IncompleteCredentialSetException;
use App\Support\Connectors\Exceptions\InvalidCredentialMutationException;
use App\Support\Connectors\OAuth1\OAuth1Credentials;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CredentialMutationTest extends TestCase
{
    #[Test]
    public function replace_rejects_empty_consumer_key_at_construction_time(): void
    {
        $this->expectException(IncompleteCredentialSetException::class);
        $this->expectExceptionMessage('consumer key must not be empty');

        CredentialMutation::replace(new OAuth1Credentials('', 'secret', 'token', 'token-secret'));
    }

    #[Test]
    public function replace_rejects_empty_access_token_secret_at_construction_time(): void
    {
        $this->expectException(IncompleteCredentialSetException::class);
        $this->expectExceptionMessage('access token secret must not be empty');

        CredentialMutation::replace(new OAuth1Credentials('ck', 'cs', 'at', ''));
    }

    #[Test]
    public function exception_messages_from_replace_do_not_echo_secret_values(): void
    {
        try {
            CredentialMutation::replace(new OAuth1Credentials('ck_value', '', 'at_value', 'ts_value'));
        } catch (IncompleteCredentialSetException $exception) {
            $this->assertStringNotContainsString('cs_value', $exception->getMessage());
            $this->assertStringNotContainsString('at_value', $exception->getMessage());
            $this->assertStringNotContainsString('ts_value', $exception->getMessage());

            return;
        }

        $this->fail('Expected IncompleteCredentialSetException was not thrown.');
    }

    #[Test]
    public function remove_on_create_mode_is_rejected_by_schema_contract_helper(): void
    {
        $this->expectException(InvalidCredentialMutationException::class);
        $this->expectExceptionMessage('Credential removal is not valid when creating a connector account.');

        CredentialMutation::remove()->assertAllowedForMode(ConnectorAccountMutationMode::Create);
    }
}
