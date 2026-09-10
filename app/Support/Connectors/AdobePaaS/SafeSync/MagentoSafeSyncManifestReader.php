<?php

namespace App\Support\Connectors\AdobePaaS\SafeSync;

use Illuminate\Support\Arr;
use RuntimeException;

final class MagentoSafeSyncManifestReader
{
    private const MANIFEST_PATH = 'integrations/magento-safe-sync/composer.json';

    public function requirements(): MagentoSafeSyncPackageRequirements
    {
        $manifest = $this->readManifest();
        $require = Arr::get($manifest, 'require', []);

        if (! is_array($require)) {
            throw new RuntimeException('Magento Safe Sync manifest `require` must be an object.');
        }

        return new MagentoSafeSyncPackageRequirements(
            phpConstraint: $this->optionalString($require['php'] ?? null),
            magentoFrameworkConstraint: $this->optionalString($require['magento/framework'] ?? null),
            magentoCatalogConstraint: $this->optionalString($require['magento/module-catalog'] ?? null),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function readManifest(): array
    {
        $path = $this->resolveManifestPath();
        $raw = @file_get_contents($path);

        if ($raw === false) {
            throw new RuntimeException(sprintf('Magento Safe Sync manifest is missing: %s', self::MANIFEST_PATH));
        }

        try {
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException('Magento Safe Sync manifest JSON is invalid.', 0, $exception);
        }

        if (! is_array($decoded)) {
            throw new RuntimeException('Magento Safe Sync manifest must be a JSON object.');
        }

        return $decoded;
    }

    private function resolveManifestPath(): string
    {
        if (function_exists('app') && method_exists(app(), 'basePath')) {
            return base_path(self::MANIFEST_PATH);
        }

        return dirname(__DIR__, 5).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, self::MANIFEST_PATH);
    }

    private function optionalString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
