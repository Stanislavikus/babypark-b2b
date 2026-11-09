<?php

namespace App\Support\Connectors\AdobePaaS\EntityTrust;

final readonly class AdobeConnectorAccountTargetSnapshot
{
    public function __construct(
        public string $baseUrl,
        public string $storeCode,
    ) {}

    public function equals(self $other): bool
    {
        return $this->baseUrl === $other->baseUrl
            && $this->storeCode === $other->storeCode;
    }

    /**
     * @return array{base_url: string, store_code: string}
     */
    public function toEnvelopeArray(): array
    {
        return [
            'base_url' => $this->baseUrl,
            'store_code' => $this->storeCode,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromEnvelopeArray(array $data): ?self
    {
        $baseUrl = $data['base_url'] ?? null;
        $storeCode = $data['store_code'] ?? null;

        if (! is_string($baseUrl) || $baseUrl === '' || ! is_string($storeCode) || $storeCode === '') {
            return null;
        }

        return new self($baseUrl, $storeCode);
    }
}
