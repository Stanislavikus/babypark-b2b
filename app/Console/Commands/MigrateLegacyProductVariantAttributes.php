<?php

namespace App\Console\Commands;

use App\Enums\FieldObjectType;
use App\Models\FieldBinding;
use App\Models\ProductVariant;
use App\Models\VariantFieldValue;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Legacy one-off importer from product_variants.attributes JSON.
 *
 * Deletion deferred until verified on production-representative data (GAP-016 §L).
 */
class MigrateLegacyProductVariantAttributes extends Command
{
    protected $signature = 'product-fields:migrate-legacy-attributes {--dry-run : Inspect data without writing}';

    protected $description = 'Migrate legacy product_variants.attributes JSON into variant_field_values';

    private const ALLOWED_KEYS = ['Колір', 'Розмір'];

    private const COLOR_VALUE_MAP = [
        'Синій' => 'blue',
        'Рожевий' => 'pink',
    ];

    private const SIZE_VALUE_MAP = [
        'M' => 'm',
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $variants = ProductVariant::withoutWorkspaceScope()
            ->whereNotNull('attributes')
            ->get();

        $this->info('Variants with non-null attributes: '.$variants->count());

        $keysFound = $this->collectAttributeKeys($variants);
        $this->reportKeys($keysFound);

        $unexpectedKeys = $keysFound->keys()->diff(self::ALLOWED_KEYS);
        if ($unexpectedKeys->isNotEmpty()) {
            $this->error('Unexpected attribute keys found: '.$unexpectedKeys->implode(', '));

            return self::FAILURE;
        }

        $colorValues = $keysFound->get('Колір', collect());
        $sizeValues = $keysFound->get('Розмір', collect());

        $unexpectedColorValues = $colorValues->diff(array_keys(self::COLOR_VALUE_MAP));
        if ($unexpectedColorValues->isNotEmpty()) {
            $this->error('Unexpected Колір values found: '.$unexpectedColorValues->implode(', '));

            return self::FAILURE;
        }

        $unexpectedSizeValues = $sizeValues->diff(array_keys(self::SIZE_VALUE_MAP));
        if ($unexpectedSizeValues->isNotEmpty()) {
            $this->error('Unexpected Розмір values found: '.$unexpectedSizeValues->implode(', '));

            return self::FAILURE;
        }

        $colorBinding = FieldBinding::withoutWorkspaceScope()
            ->where('object_type', FieldObjectType::ProductVariant)
            ->whereHas('fieldDefinition', fn ($q) => $q->where('code', 'color')->whereNull('workspace_id'))
            ->first();

        $sizeBinding = FieldBinding::withoutWorkspaceScope()
            ->where('object_type', FieldObjectType::ProductVariant)
            ->whereHas('fieldDefinition', fn ($q) => $q->where('code', 'size')->whereNull('workspace_id'))
            ->first();

        if ($colorBinding === null || $sizeBinding === null) {
            $this->error('Required field bindings (color, size) are missing. Run FieldDefinitionSeeder first.');

            return self::FAILURE;
        }

        $created = 0;

        foreach ($variants as $variant) {
            $attributes = $variant->attributes ?? [];

            foreach ($attributes as $key => $value) {
                if ($value === null || $value === '') {
                    continue;
                }

                [$binding, $storedValue] = match ($key) {
                    'Колір' => [$colorBinding, self::COLOR_VALUE_MAP[$value] ?? null],
                    'Розмір' => [$sizeBinding, self::SIZE_VALUE_MAP[$value] ?? null],
                    default => [null, null],
                };

                if ($binding === null || $storedValue === null) {
                    continue;
                }

                if ($dryRun) {
                    $this->line(sprintf(
                        '[dry-run] variant #%d (%s): %s => %s = %s',
                        $variant->id,
                        $variant->sku,
                        $key,
                        $binding->fieldDefinition->code,
                        $storedValue,
                    ));
                    $created++;

                    continue;
                }

                VariantFieldValue::withoutWorkspaceScope()->updateOrCreate(
                    [
                        'workspace_id' => $variant->workspace_id,
                        'variant_id' => $variant->id,
                        'field_binding_id' => $binding->id,
                    ],
                    [
                        'value_text' => $storedValue,
                    ],
                );

                $created++;
            }
        }

        $totalValues = VariantFieldValue::withoutWorkspaceScope()->count();

        if ($dryRun) {
            $this->info("Dry-run complete. Would create/update {$created} variant field value row(s).");
        } else {
            $this->info("Migration complete. Processed {$created} attribute value mapping(s).");
            $this->info('Total variant_field_values rows: '.$totalValues);
        }

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, ProductVariant>  $variants
     * @return Collection<string, Collection<int, string>>
     */
    private function collectAttributeKeys(Collection $variants): Collection
    {
        return $variants
            ->flatMap(fn (ProductVariant $variant) => collect($variant->attributes ?? []))
            ->groupBy(fn ($value, $key) => $key)
            ->map(fn (Collection $pairs) => $pairs->values()->unique()->sort()->values());
    }

    /**
     * @param  Collection<string, Collection<int, string>>  $keysFound
     */
    private function reportKeys(Collection $keysFound): void
    {
        if ($keysFound->isEmpty()) {
            $this->line('No attribute keys found.');

            return;
        }

        foreach ($keysFound as $key => $values) {
            $this->line(sprintf('%s => %s', $key, $values->implode(', ')));
        }
    }
}
