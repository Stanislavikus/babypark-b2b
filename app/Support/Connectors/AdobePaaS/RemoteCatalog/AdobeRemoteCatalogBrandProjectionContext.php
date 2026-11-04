<?php

namespace App\Support\Connectors\AdobePaaS\RemoteCatalog;

final readonly class AdobeRemoteCatalogBrandProjectionContext
{
    /**
     * @param  array<string, string>  $optionLabels
     */
    public function __construct(
        public string $fieldKey,
        public array $optionLabels = [],
    ) {}

    /**
     * @param  array<string, mixed>  $customAttributes
     * @return array{field_key:string,value:string,label:string}|null
     */
    public function project(array $customAttributes): ?array
    {
        if (! array_key_exists($this->fieldKey, $customAttributes)) {
            return null;
        }

        $raw = $customAttributes[$this->fieldKey];
        if (! is_scalar($raw) || is_bool($raw)) {
            return null;
        }

        $value = trim((string) $raw);
        if ($value === '') {
            return null;
        }

        return [
            'field_key' => $this->fieldKey,
            'value' => $value,
            'label' => $this->optionLabels[$value] ?? $value,
        ];
    }
}
