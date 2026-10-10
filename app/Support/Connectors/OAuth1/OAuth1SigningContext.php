<?php

namespace App\Support\Connectors\OAuth1;

final readonly class OAuth1SigningContext
{
    public function __construct(
        public string $nonce,
        public int $timestamp,
    ) {}
}
