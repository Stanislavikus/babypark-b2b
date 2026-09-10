<?php

namespace App\Support\Connectors\OAuth1;

final readonly class OAuth1ParameterPair
{
    public function __construct(
        public string $name,
        public string $value,
    ) {}
}
