<?php

namespace App\Support\Connectors\AdobePaaS;

use App\Support\Connectors\AdobePaaS\Exceptions\InvalidAdobePaaSRequestContextException;
use App\Support\Connectors\OAuth1\Exceptions\OAuth1StructuralException;
use App\Support\Connectors\OAuth1\OAuth1RequestUrl;

final readonly class AdobePaaSBaseUrl
{
    public function __construct(public string $value) {}

    public static function parse(string $raw): self
    {
        if ($raw === '') {
            throw new InvalidAdobePaaSRequestContextException('Adobe PaaS base URL must be an absolute URL.');
        }

        if (str_contains($raw, '?') || str_contains($raw, '#')) {
            throw new InvalidAdobePaaSRequestContextException(
                'Adobe PaaS base URL must not contain a query string or fragment.',
            );
        }

        try {
            OAuth1RequestUrl::parse($raw);
        } catch (OAuth1StructuralException $exception) {
            throw new InvalidAdobePaaSRequestContextException(
                'Adobe PaaS base URL must be an absolute URL.',
                previous: $exception,
            );
        }

        return new self($raw);
    }
}
