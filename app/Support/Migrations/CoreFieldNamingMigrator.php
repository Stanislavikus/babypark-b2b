<?php

namespace App\Support\Migrations;

use App\Enums\AttributeScope;
use App\Enums\FieldObjectType;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * DEC-008 / DEC-009 — canonical core field naming correction.
 *
 * Renames product columns and FieldDefinition metadata:
 * product_url → url, weight_netto → net_weight, weight_brutto → gross_weight.
 * Merges product_name FieldDefinition into the shared global name definition.
 */
final class CoreFieldNamingMigrator
{
    /** @var array<string, string> */
    private const COLUMN_RENAMES = [
        'product_url' => 'url',
        'weight_netto' => 'net_weight',
        'weight_brutto' => 'gross_weight',
    ];

    /** @var array<string, string> */
    private const DEFINITION_RENAMES = [
        'product_url' => 'url',
        'weight_netto' => 'net_weight',
        'weight_brutto' => 'gross_weight',
    ];

    /** @var array<string, string> */
    private const STORAGE_PATH_RENAMES = [
        'products.product_url' => 'products.url',
        'products.weight_netto' => 'products.net_weight',
        'products.weight_brutto' => 'products.gross_weight',
    ];

    public function up(): void
    {
        $this->renameProductColumns();
        $this->mergeProductNameIntoName();
        $this->renameFieldDefinitionsInPlace();
        $this->renameBindingStoragePaths();
    }

    public function down(): void
    {
        $this->renameBindingStoragePaths(array_flip(self::STORAGE_PATH_RENAMES));
        $this->renameFieldDefinitionsInPlace(array_flip(self::DEFINITION_RENAMES));
        $this->splitProductNameFromName();
        $this->renameProductColumns(array_flip(self::COLUMN_RENAMES));
    }

    /**
     * @param  array<string, string>|null  $renames
     */
    private function renameProductColumns(?array $renames = null): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }

        foreach ($renames ?? self::COLUMN_RENAMES as $from => $to) {
            if (! Schema::hasColumn('products', $from)) {
                continue;
            }

            if (Schema::hasColumn('products', $to)) {
                throw new RuntimeException(
                    "Cannot rename products.{$from} to products.{$to}: target column already exists."
                );
            }

            Schema::table('products', function (Blueprint $table) use ($from, $to) {
                $table->renameColumn($from, $to);
            });
        }
    }

    private function mergeProductNameIntoName(): void
    {
        if (! Schema::hasTable('field_definitions') || ! Schema::hasTable('field_bindings')) {
            return;
        }

        $productNameDef = $this->findSystemDefinition('product_name');

        if ($productNameDef === null) {
            return;
        }

        $nameDef = $this->findSystemDefinition('name');

        if ($nameDef === null) {
            DB::table('field_definitions')
                ->where('id', $productNameDef->id)
                ->update(['code' => 'name', 'updated_at' => now()]);

            return;
        }

        $this->assertDefinitionsCompatible($productNameDef, $nameDef, 'product_name', 'name');

        $productNameBinding = DB::table('field_bindings')
            ->where('field_definition_id', $productNameDef->id)
            ->where('object_type', FieldObjectType::Product->value)
            ->first();

        $existingNameProductBinding = DB::table('field_bindings')
            ->where('field_definition_id', $nameDef->id)
            ->where('object_type', FieldObjectType::Product->value)
            ->exists();

        if ($existingNameProductBinding) {
            throw new RuntimeException(
                'Cannot merge product_name into name: target name already has a Product binding.'
            );
        }

        if ($productNameBinding !== null) {
            DB::table('field_bindings')
                ->where('id', $productNameBinding->id)
                ->update([
                    'field_definition_id' => $nameDef->id,
                    'updated_at' => now(),
                ]);
        }

        $unexpectedReferences = DB::table('field_bindings')
            ->where('field_definition_id', $productNameDef->id)
            ->count();

        if ($unexpectedReferences > 0) {
            throw new RuntimeException(
                "Cannot delete product_name definition: {$unexpectedReferences} unexpected binding reference(s) remain."
            );
        }

        DB::table('field_definitions')->where('id', $productNameDef->id)->delete();
    }

    private function splitProductNameFromName(): void
    {
        if (! Schema::hasTable('field_definitions') || ! Schema::hasTable('field_bindings')) {
            return;
        }

        $nameDef = $this->findSystemDefinition('name');

        if ($nameDef === null) {
            return;
        }

        $productBinding = DB::table('field_bindings')
            ->where('field_definition_id', $nameDef->id)
            ->where('object_type', FieldObjectType::Product->value)
            ->first();

        if ($productBinding === null) {
            return;
        }

        $productNameDefId = (string) Str::uuid();

        DB::table('field_definitions')->insert([
            'id' => $productNameDefId,
            'workspace_id' => null,
            'code' => 'product_name',
            'data_type' => $nameDef->data_type,
            'scope' => $nameDef->scope,
            'localized_labels' => $nameDef->localized_labels,
            'description' => $nameDef->description,
            'validation_rules' => $nameDef->validation_rules,
            'is_localizable' => $nameDef->is_localizable,
            'is_multi_value' => $nameDef->is_multi_value,
            'status' => $nameDef->status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('field_bindings')
            ->where('id', $productBinding->id)
            ->update([
                'field_definition_id' => $productNameDefId,
                'updated_at' => now(),
            ]);
    }

    /**
     * @param  array<string, string>|null  $renames
     */
    private function renameFieldDefinitionsInPlace(?array $renames = null): void
    {
        if (! Schema::hasTable('field_definitions')) {
            return;
        }

        foreach ($renames ?? self::DEFINITION_RENAMES as $from => $to) {
            $existing = $this->findSystemDefinition($from);

            if ($existing === null) {
                continue;
            }

            $collision = $this->findSystemDefinition($to);

            if ($collision !== null && $collision->id !== $existing->id) {
                throw new RuntimeException(
                    "Cannot rename FieldDefinition '{$from}' to '{$to}': target code already exists."
                );
            }

            DB::table('field_definitions')
                ->where('id', $existing->id)
                ->update(['code' => $to, 'updated_at' => now()]);
        }
    }

    /**
     * @param  array<string, string>|null  $pathRenames
     */
    private function renameBindingStoragePaths(?array $pathRenames = null): void
    {
        if (! Schema::hasTable('field_bindings')) {
            return;
        }

        foreach ($pathRenames ?? self::STORAGE_PATH_RENAMES as $from => $to) {
            DB::table('field_bindings')
                ->where('storage_path', $from)
                ->update([
                    'storage_path' => $to,
                    'updated_at' => now(),
                ]);
        }
    }

    private function findSystemDefinition(string $code): ?object
    {
        return DB::table('field_definitions')
            ->whereNull('workspace_id')
            ->where('scope', AttributeScope::System->value)
            ->where('code', $code)
            ->first();
    }

    private function assertDefinitionsCompatible(
        object $source,
        object $target,
        string $sourceCode,
        string $targetCode,
    ): void {
        foreach (['data_type', 'scope', 'is_localizable', 'is_multi_value', 'status'] as $field) {
            if ((string) $source->{$field} !== (string) $target->{$field}) {
                throw new RuntimeException(
                    "Incompatible FieldDefinition merge {$sourceCode} → {$targetCode}: {$field} mismatch."
                );
            }
        }

        $sourceRules = $this->normalizeJson($source->validation_rules);
        $targetRules = $this->normalizeJson($target->validation_rules);

        if ($sourceRules !== $targetRules) {
            throw new RuntimeException(
                "Incompatible FieldDefinition merge {$sourceCode} → {$targetCode}: validation_rules mismatch."
            );
        }
    }

    private function normalizeJson(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            return json_decode($value, true);
        }

        return $value;
    }
}
