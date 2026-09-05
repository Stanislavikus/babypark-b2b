<?php

namespace App\Support\Connectors;

use App\Support\Connectors\Exceptions\IncompleteCredentialSetException;
use App\Support\Connectors\Exceptions\InvalidCredentialMutationException;
use App\Support\Connectors\OAuth1\OAuth1Credentials;

final readonly class CredentialMutation
{
    public const ACTION_KEEP = 'keep';

    public const ACTION_REPLACE = 'replace';

    public const ACTION_REMOVE = 'remove';

    private function __construct(
        public string $action,
        public ?OAuth1Credentials $credentials = null,
    ) {}

    public static function keep(): self
    {
        return new self(self::ACTION_KEEP);
    }

    public static function replace(OAuth1Credentials $credentials): self
    {
        foreach ([
            'consumer key' => $credentials->consumerKey,
            'consumer secret' => $credentials->consumerSecret,
            'access token' => $credentials->accessToken,
            'access token secret' => $credentials->accessTokenSecret,
        ] as $label => $value) {
            if ($value === '') {
                throw new IncompleteCredentialSetException(
                    sprintf('Credential mutation requires a complete credential set; %s must not be empty.', $label),
                );
            }
        }

        return new self(self::ACTION_REPLACE, $credentials);
    }

    public static function remove(): self
    {
        return new self(self::ACTION_REMOVE);
    }

    public function isKeep(): bool
    {
        return $this->action === self::ACTION_KEEP;
    }

    public function isReplace(): bool
    {
        return $this->action === self::ACTION_REPLACE;
    }

    public function isRemove(): bool
    {
        return $this->action === self::ACTION_REMOVE;
    }

    public function assertAllowedForMode(ConnectorAccountMutationMode $mode): void
    {
        if ($mode === ConnectorAccountMutationMode::Create && $this->isRemove()) {
            throw new InvalidCredentialMutationException(
                'Credential removal is not valid when creating a connector account.',
            );
        }
    }
}
