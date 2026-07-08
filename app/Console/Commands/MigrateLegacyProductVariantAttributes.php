<?php

namespace App\Console\Commands;

use App\Models\AttributeDefinition;
use App\Models\ProductVariant;
use App\Models\VariantAttributeValue;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class MigrateLegacyProductVariantAttributes extends Command
{
    protected $signature = 'product-fields:migrate-legacy-attributes {--dry-run : Inspect data without writing}';

    protected $description = 'Migrate legacy product_variants.attributes JSON into variant_attribute_values';

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

        $colorDefinition = AttributeDefinition::withoutWorkspaceScope()
            ->where('code', 'color')
            ->whereNull('workspace_id')
            ->first();

        $sizeDefinition = AttributeDefinition::withoutWorkspaceScope()
            ->where('code', 'size')
            ->whereNull('workspace_id')
            ->first();

        if ($colorDefinition === null || $sizeDefinition === null) {
            $this->error('Required attribute definitions (color, size) are missing. Run AttributeDefinitionSeeder first.');

            return self::FAILURE;
        }

        $created = 0;

        foreach ($variants as $variant) {
            $attributes = $variant->attributes ?? [];

            foreach ($attributes as $key => $value) {
                if ($value === null || $value === '') {
                    continue;
                }

                [$definition, $storedValue] = match ($key) {
                    'Колір' => [$colorDefinition, self::COLOR_VALUE_MAP[$value] ?? null],
                    'Розмір' => [$sizeDefinition, self::SIZE_VALUE_MAP[$value] ?? null],
                    default => [null, null],
                };

                if ($definition === null || $storedValue === null) {
                    continue;
                }

                if ($dryRun) {
                    $this->line(sprintf(
                        '[dry-run] variant #%d (%s): %s => %s = %s',
                        $variant->id,
                        $variant->sku,
                        $key,
                        $definition->code,
                        $storedValue,
                    ));
                    $created++;

                    continue;
                }

                VariantAttributeValue::withoutWorkspaceScope()->updateOrCreate(
                    [
                        'workspace_id' => $variant->workspace_id,
                        'variant_id' => $variant->id,
                        'attribute_definition_id' => $definition->id,
                    ],
                    [
                        'value_text' => $storedValue,
                    ],
                );

                $created++;
            }
        }

        $totalValues = VariantAttributeValue::withoutWorkspaceScope()->count();

        if ($dryRun) {
            $this->info("Dry-run complete. Would create/update {$created} variant attribute value row(s).");
        } else {
            $this->info("Migration complete. Processed {$created} attribute value mapping(s).");
            $this->info('Total variant_attribute_values rows: '.$totalValues);
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
