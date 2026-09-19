<?php

namespace App\Support\Connectors\Transport;

final readonly class ConnectorHttpResult
{
    /**
     * @param  array<string, list<string>>  $headers
     */
    public function __construct(
        public int $statusCode,
        public array $headers,
        public string $body,
    ) {}
}
