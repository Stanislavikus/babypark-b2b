<?php

namespace App\Support\Migrations;

use App\Enums\AttributeScope;
use App\Enums\FieldObjectType;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

final class FieldFoundationMigrator
{
    private ?bool $aliasDefinitionIdWasNotNull = null;

    public function up(): void
    {
        $this->assertUpPreflight();
        $this->assertCustomerCollisionPreflight();
        $this->assertAliasBothPreflight();

        $this->createNewTables();
        $this->copyDefinitions();
        $this->createBindingsFromDefinitions();
        $this->copyProductValues();
        $this->copyVariantValues();
        $this->assertCheckpoint1();
        $this->migrateAliases();
        $this->seedCustomerFields();
        $this->assertCheckpoint2();
        $this->dropOldSchema();
        $this->assertFinalSchemaClean();
    }

    public function down(): void
    {
        $reasons = $this->collectDownPreflightFailures();

        if ($reasons !== []) {
            throw new RuntimeException(
                "Field Foundation down() preflight failed:\n- ".implode("\n- ", $reasons)
            );
        }

        $fieldBindingIdWasNotNull = $this->aliasDefinitionIdWasNotNull
          ?? ! $this->columnIsNullable('workspace_import_aliases', 'field_binding_id');

        $this->recreateAttributeDefinitionsTable();
        $this->recreateAttributeValueTables();
        $this->reconstructAttributeDefinitions();
        $this->copyFieldValuesBack();
        $this->restoreAliasDefinitionColumn($fieldBindingIdWasNotNull);
        $this->assertDownInvariants();
        $this->dropNewSchema();
    }

    private function assertUpPreflight(): void
    {
        $expectedOld = ['attribute_definitions', 'product_attribute_values', 'variant_attribute_values'];

        foreach ($expectedOld as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException(
                    "{$table} not found — either already migrated, or unexpected partial schema state. Inspect manually before proceeding, do not re-run blindly."
                );
            }
        }

        if (! Schema::hasColumn('workspace_import_aliases', 'attribute_definition_id')) {
            throw new RuntimeException(
                'workspace_import_aliases.attribute_definition_id not found — unexpected partial state.'
            );
        }

        $expectedNewAbsent = [
            'field_definitions',
            'field_bindings',
            'product_field_values',
            'variant_field_values',
            'customer_field_values',
        ];

        foreach ($expectedNewAbsent as $table) {
            if (Schema::hasTable($table)) {
                throw new RuntimeException(
                    "{$table} already exists — this migration may have partially run before and failed. Inspect manually before retrying."
                );
            }
        }

        if (Schema::hasColumn('workspace_import_aliases', 'field_binding_id')) {
            throw new RuntimeException(
                'workspace_import_aliases.field_binding_id already exists — unexpected partial state, inspect manually.'
            );
        }
    }

    private function assertCustomerCollisionPreflight(): void
    {
        $codes = FieldFoundationCustomerSeed::CODES;
        $placeholders = implode(',', array_fill(0, count($codes), '?'));

        $existing = DB::table('attribute_definitions')
            ->whereNull('workspace_id')
            ->whereIn('code', $codes)
            ->get();

        foreach ($existing as $row) {
            $code = (string) $row->code;
            $expected = FieldFoundationCustomerSeed::definitions()[$code] ?? null;

            if ($expected === null) {
                continue;
            }

            $conflicts = $this->definitionConflicts($row, $expected);

            if ($conflicts !== []) {
                throw new RuntimeException(
                    "Customer field reuse conflict for code '{$code}':\n- ".implode("\n- ", $conflicts)
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $expected
     * @return list<string>
     */
    private function definitionConflicts(object $row, array $expected): array
    {
        $conflicts = [];

        if ((string) $row->scope !== AttributeScope::System->value) {
            $conflicts[] = "scope expected system, got {$row->scope}";
        }

        foreach (['data_type', 'is_localizable', 'is_multi_value', 'status'] as $field) {
            $actual = $row->{$field};
            $exp = $expected[$field];
            $expValue = $exp instanceof \BackedEnum ? $exp->value : $exp;
            $actualValue = $actual instanceof \BackedEnum ? $actual->value : $actual;

            if (in_array($field, ['is_localizable', 'is_multi_value'], true)) {
                if ((bool) $actualValue !== (bool) $expValue) {
                    $conflicts[] = "{$field} expected ".json_encode((bool) $expValue).', got '.json_encode((bool) $actualValue);
                }

                continue;
            }

            if ((string) $actualValue !== (string) $expValue) {
                $conflicts[] = "{$field} expected ".json_encode($expValue).', got '.json_encode($actualValue);
            }
        }

        if (! $this->jsonSemanticallyEqual($row->localized_labels ?? null, $expected['localized_labels'])) {
            $conflicts[] = 'localized_labels mismatch';
        }

        if (! $this->jsonSemanticallyEqual($row->validation_rules ?? null, $expected['validation_rules'])) {
            $conflicts[] = 'validation_rules mismatch';
        }

        if (($row->description ?? null) !== ($expected['description'] ?? null)) {
            $conflicts[] = 'description expected '.json_encode($expected['description'] ?? null).', got '.json_encode($row->description ?? null);
        }

        return $conflicts;
    }

    private function assertAliasBothPreflight(): void
    {
        $bothAliases = DB::select("
      SELECT wia.id, wia.source, ad.id AS definition_id, ad.code, ad.value_level
      FROM workspace_import_aliases wia
      JOIN attribute_definitions ad ON ad.id = wia.attribute_definition_id
      WHERE ad.value_level = 'both'
    ");

        if ($bothAliases !== []) {
            throw new RuntimeException(
                'workspace_import_aliases reference attribute_definitions with value_level=both — manual resolution required before migration.'
            );
        }
    }

    private function createNewTables(): void
    {
        Schema::create('field_definitions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->nullable()->constrained('workspaces')->nullOnDelete();
            $table->string('code');
            $table->string('data_type');
            $table->string('scope');
            $table->json('localized_labels');
            $table->text('description')->nullable();
            $table->json('validation_rules')->nullable();
            $table->boolean('is_localizable')->default(false);
            $table->boolean('is_multi_value')->default(false);
            $table->string('status')->default('active');
            $table->timestamps();
        });

        $this->addWorkspaceUniquenessKey('field_definitions', 'field_definitions_workspace_code_unique');

        Schema::create('field_bindings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->nullable()->constrained('workspaces')->nullOnDelete();
            $table->foreignUuid('field_definition_id')->constrained('field_definitions')->cascadeOnDelete();
            $table->string('object_type');
            $table->string('storage_type');
            $table->string('storage_path')->nullable();
            $table->string('field_group');
            $table->boolean('is_required')->default(false);
            $table->boolean('is_filterable')->default(false);
            $table->boolean('is_sortable')->default(false);
            $table->json('visibility_settings');
            $table->integer('sort_order')->default(0);
            $table->string('status')->default('active');
            $table->timestamps();

            $table->unique(
                ['field_definition_id', 'object_type'],
                'field_bindings_definition_object_type_unique'
            );
        });

        Schema::create('product_field_values', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('workspace_id')->constrained('workspaces');
            $table->unsignedBigInteger('product_id');
            $table->foreignUuid('field_binding_id')->constrained('field_bindings')->cascadeOnDelete();
            $table->text('value_text')->nullable();
            $table->decimal('value_num', 20, 6)->nullable();
            $table->json('value_jsonb')->nullable();
            $table->timestamps();

            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->unique(
                ['workspace_id', 'product_id', 'field_binding_id'],
                'product_field_values_ws_product_binding_unique'
            );
        });

        Schema::create('variant_field_values', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('workspace_id')->constrained('workspaces');
            $table->unsignedBigInteger('variant_id');
            $table->foreignUuid('field_binding_id')->constrained('field_bindings')->cascadeOnDelete();
            $table->text('value_text')->nullable();
            $table->decimal('value_num', 20, 6)->nullable();
            $table->json('value_jsonb')->nullable();
            $table->timestamps();

            $table->foreign('variant_id')->references('id')->on('product_variants')->cascadeOnDelete();
            $table->unique(
                ['workspace_id', 'variant_id', 'field_binding_id'],
                'variant_field_values_ws_variant_binding_unique'
            );
        });

        Schema::create('customer_field_values', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces');
            $table->unsignedBigInteger('customer_id');
            $table->foreignUuid('field_binding_id')->constrained('field_bindings')->cascadeOnDelete();
            $table->text('value_text')->nullable();
            $table->decimal('value_num', 20, 6)->nullable();
            $table->json('value_jsonb')->nullable();
            $table->timestamps();

            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
            $table->unique(
                ['workspace_id', 'customer_id', 'field_binding_id'],
                'customer_field_values_ws_customer_binding_unique'
            );
        });
    }

    private function addWorkspaceUniquenessKey(string $table, string $indexName): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("
        ALTER TABLE {$table}
        ADD COLUMN workspace_uniqueness_key CHAR(36)
        AS (COALESCE(workspace_id, '00000000-0000-0000-0000-000000000000')) VIRTUAL
      ");
        } else {
            DB::statement("
        ALTER TABLE {$table}
        ADD COLUMN workspace_uniqueness_key TEXT
        GENERATED ALWAYS AS (COALESCE(workspace_id, '00000000-0000-0000-0000-000000000000')) VIRTUAL
      ");
        }

        Schema::table($table, function (Blueprint $table) use ($indexName) {
            $table->unique(['workspace_uniqueness_key', 'code'], $indexName);
        });
    }

    private function copyDefinitions(): void
    {
        $rows = DB::table('attribute_definitions')->orderBy('id')->get();

        foreach ($rows as $row) {
            DB::table('field_definitions')->insert([
                'id' => $row->id,
                'workspace_id' => $row->workspace_id,
                'code' => $row->code,
                'data_type' => $row->data_type,
                'scope' => $row->scope,
                'localized_labels' => $row->localized_labels,
                'description' => null,
                'validation_rules' => $row->validation_rules,
                'is_localizable' => $row->is_localizable,
                'is_multi_value' => $row->is_multi_value,
                'status' => $row->status,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ]);
        }
    }

    private function createBindingsFromDefinitions(): void
    {
        $rows = DB::table('attribute_definitions')->orderBy('id')->get();

        foreach ($rows as $row) {
            $objectTypes = match ($row->value_level) {
                'product' => [FieldObjectType::Product->value],
                'variant' => [FieldObjectType::ProductVariant->value],
                'both' => [FieldObjectType::Product->value, FieldObjectType::ProductVariant->value],
                default => throw new RuntimeException("Unknown value_level '{$row->value_level}' for definition {$row->id}"),
            };

            foreach ($objectTypes as $objectType) {
                DB::table('field_bindings')->insert([
                    'id' => (string) Str::uuid(),
                    'workspace_id' => $row->workspace_id,
                    'field_definition_id' => $row->id,
                    'object_type' => $objectType,
                    'storage_type' => $row->storage_type,
                    'storage_path' => $row->storage_path,
                    'field_group' => $row->attribute_group,
                    'is_required' => $row->is_required,
                    'is_filterable' => $row->is_filterable,
                    'is_sortable' => $row->is_sortable,
                    'visibility_settings' => $row->visibility_settings,
                    'sort_order' => $row->sort_order,
                    'status' => $row->status,
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ]);
            }
        }
    }

    private function copyProductValues(): void
    {
        $bindingMap = $this->bindingMapForObjectType(FieldObjectType::Product->value);

        foreach (DB::table('product_attribute_values')->orderBy('id')->get() as $row) {
            $bindingId = $bindingMap[$row->attribute_definition_id] ?? null;

            if ($bindingId === null) {
                throw new RuntimeException(
                    "No product field_binding for attribute_definition_id {$row->attribute_definition_id}"
                );
            }

            DB::table('product_field_values')->insert([
                'id' => $row->id,
                'workspace_id' => $row->workspace_id,
                'product_id' => $row->product_id,
                'field_binding_id' => $bindingId,
                'value_text' => $row->value_text,
                'value_num' => $row->value_num,
                'value_jsonb' => $row->value_jsonb,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ]);
        }
    }

    private function copyVariantValues(): void
    {
        $bindingMap = $this->bindingMapForObjectType(FieldObjectType::ProductVariant->value);

        foreach (DB::table('variant_attribute_values')->orderBy('id')->get() as $row) {
            $bindingId = $bindingMap[$row->attribute_definition_id] ?? null;

            if ($bindingId === null) {
                throw new RuntimeException(
                    "No product_variant field_binding for attribute_definition_id {$row->attribute_definition_id}"
                );
            }

            DB::table('variant_field_values')->insert([
                'id' => $row->id,
                'workspace_id' => $row->workspace_id,
                'variant_id' => $row->variant_id,
                'field_binding_id' => $bindingId,
                'value_text' => $row->value_text,
                'value_num' => $row->value_num,
                'value_jsonb' => $row->value_jsonb,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ]);
        }
    }

    /**
     * @return array<string, string>
     */
    private function bindingMapForObjectType(string $objectType): array
    {
        return DB::table('field_bindings')
            ->where('object_type', $objectType)
            ->pluck('id', 'field_definition_id')
            ->all();
    }

    private function assertCheckpoint1(): void
    {
        $definitionCount = DB::table('attribute_definitions')->count();
        $fieldDefinitionCount = DB::table('field_definitions')->count();

        if ($definitionCount !== $fieldDefinitionCount) {
            throw new RuntimeException(
                "Checkpoint 1 failed: attribute_definitions ({$definitionCount}) != field_definitions ({$fieldDefinitionCount})"
            );
        }

        $productCount = DB::table('attribute_definitions')->where('value_level', 'product')->count();
        $variantCount = DB::table('attribute_definitions')->where('value_level', 'variant')->count();
        $bothCount = DB::table('attribute_definitions')->where('value_level', 'both')->count();
        $expectedBindings = $productCount + $variantCount + (2 * $bothCount);
        $actualBindings = DB::table('field_bindings')
            ->whereIn('object_type', [FieldObjectType::Product->value, FieldObjectType::ProductVariant->value])
            ->count();

        if ($expectedBindings !== $actualBindings) {
            throw new RuntimeException(
                "Checkpoint 1 failed: expected {$expectedBindings} product/variant bindings, got {$actualBindings}"
            );
        }

        if (DB::table('product_attribute_values')->count() !== DB::table('product_field_values')->count()) {
            throw new RuntimeException('Checkpoint 1 failed: product value row count mismatch');
        }

        if (DB::table('variant_attribute_values')->count() !== DB::table('variant_field_values')->count()) {
            throw new RuntimeException('Checkpoint 1 failed: variant value row count mismatch');
        }

        foreach (DB::table('attribute_definitions')->get() as $old) {
            $new = DB::table('field_definitions')->where('id', $old->id)->first();

            if ($new === null) {
                throw new RuntimeException("Checkpoint 1 failed: missing field_definition for id {$old->id}");
            }
        }

        $this->assertValueTableIntegrity('product_attribute_values', 'product_field_values', FieldObjectType::Product->value);
        $this->assertValueTableIntegrity('variant_attribute_values', 'variant_field_values', FieldObjectType::ProductVariant->value);
    }

    private function assertValueTableIntegrity(string $oldTable, string $newTable, string $objectType): void
    {
        foreach (DB::table($newTable)->get() as $row) {
            $binding = DB::table('field_bindings')->where('id', $row->field_binding_id)->first();

            if ($binding === null) {
                throw new RuntimeException("Checkpoint 1 failed: orphan field_binding_id {$row->field_binding_id} in {$newTable}");
            }

            if ($binding->object_type !== $objectType) {
                throw new RuntimeException("Checkpoint 1 failed: wrong object_type on binding {$row->field_binding_id}");
            }
        }
    }

    private function migrateAliases(): void
    {
        Schema::table('workspace_import_aliases', function (Blueprint $table) {
            $table->foreignUuid('field_binding_id')->nullable()->after('workspace_id');
        });

        $definitionToBinding = [];

        foreach (DB::table('workspace_import_aliases')->orderBy('id')->get() as $alias) {
            $definition = DB::table('attribute_definitions')->where('id', $alias->attribute_definition_id)->first();

            if ($definition === null) {
                throw new RuntimeException("Alias {$alias->id} references missing attribute_definition {$alias->attribute_definition_id}");
            }

            $objectType = match ($definition->value_level) {
                'product' => FieldObjectType::Product->value,
                'variant' => FieldObjectType::ProductVariant->value,
                'both' => throw new RuntimeException(
                    "Alias {$alias->id} references definition {$definition->id} with value_level=both — ambiguous binding"
                ),
                default => throw new RuntimeException("Unknown value_level for alias mapping: {$definition->value_level}"),
            };

            $cacheKey = "{$definition->id}:{$objectType}";

            if (! isset($definitionToBinding[$cacheKey])) {
                $binding = DB::table('field_bindings')
                    ->where('field_definition_id', $definition->id)
                    ->where('object_type', $objectType)
                    ->first();

                if ($binding === null) {
                    throw new RuntimeException(
                        "No field_binding for definition {$definition->id} object_type {$objectType}"
                    );
                }

                $definitionToBinding[$cacheKey] = $binding->id;
            }

            DB::table('workspace_import_aliases')
                ->where('id', $alias->id)
                ->update(['field_binding_id' => $definitionToBinding[$cacheKey]]);
        }

        $nullCount = DB::table('workspace_import_aliases')->whereNull('field_binding_id')->count();

        if ($nullCount > 0) {
            throw new RuntimeException("Alias migration left {$nullCount} NULL field_binding_id row(s)");
        }

        $this->aliasDefinitionIdWasNotNull = ! $this->columnIsNullable('workspace_import_aliases', 'attribute_definition_id');

        if ($this->aliasDefinitionIdWasNotNull) {
            $this->setColumnNotNull('workspace_import_aliases', 'field_binding_id');
        }

        Schema::table('workspace_import_aliases', function (Blueprint $table) {
            $table->foreign('field_binding_id')->references('id')->on('field_bindings')->cascadeOnDelete();
        });
    }

    private function seedCustomerFields(): void
    {
        $definitions = FieldFoundationCustomerSeed::definitions();
        $bindings = FieldFoundationCustomerSeed::bindings();

        foreach (FieldFoundationCustomerSeed::CODES as $code) {
            $expectedDef = $definitions[$code];
            $expectedBinding = $bindings[$code];

            $existing = DB::table('field_definitions')
                ->whereNull('workspace_id')
                ->where('code', $code)
                ->first();

            $definitionId = null;

            if ($existing === null) {
                $definitionId = (string) Str::uuid();
                DB::table('field_definitions')->insert([
                    'id' => $definitionId,
                    'workspace_id' => null,
                    'code' => $code,
                    'data_type' => $expectedDef['data_type'] instanceof \BackedEnum
                      ? $expectedDef['data_type']->value
                      : $expectedDef['data_type'],
                    'scope' => AttributeScope::System->value,
                    'localized_labels' => json_encode($expectedDef['localized_labels']),
                    'description' => $expectedDef['description'],
                    'validation_rules' => $expectedDef['validation_rules'] === null
                      ? null
                      : json_encode($expectedDef['validation_rules']),
                    'is_localizable' => $expectedDef['is_localizable'],
                    'is_multi_value' => $expectedDef['is_multi_value'],
                    'status' => $expectedDef['status'] instanceof \BackedEnum
                      ? $expectedDef['status']->value
                      : $expectedDef['status'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                $conflicts = $this->definitionConflicts($existing, $expectedDef);

                if ($conflicts !== []) {
                    throw new RuntimeException(
                        "Customer field reuse conflict for code '{$code}':\n- ".implode("\n- ", $conflicts)
                    );
                }

                $definitionId = $existing->id;
            }

            DB::table('field_bindings')->insert([
                'id' => (string) Str::uuid(),
                'workspace_id' => null,
                'field_definition_id' => $definitionId,
                'object_type' => FieldObjectType::Customer->value,
                'storage_type' => $expectedBinding['storage_type'] instanceof \BackedEnum
                  ? $expectedBinding['storage_type']->value
                  : $expectedBinding['storage_type'],
                'storage_path' => $expectedBinding['storage_path'],
                'field_group' => $expectedBinding['field_group'],
                'is_required' => $expectedBinding['is_required'],
                'is_filterable' => $expectedBinding['is_filterable'],
                'is_sortable' => $expectedBinding['is_sortable'],
                'visibility_settings' => json_encode($expectedBinding['visibility_settings']),
                'sort_order' => $expectedBinding['sort_order'],
                'status' => $expectedBinding['status'] instanceof \BackedEnum
                  ? $expectedBinding['status']->value
                  : $expectedBinding['status'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function assertCheckpoint2(): void
    {
        $checkpoint1Definitions = DB::table('attribute_definitions')->count();
        $newCustomerDefinitions = DB::table('field_definitions')
            ->whereIn('code', FieldFoundationCustomerSeed::CODES)
            ->whereNull('workspace_id')
            ->whereNotIn('id', DB::table('attribute_definitions')->pluck('id'))
            ->count();

        $totalDefinitions = DB::table('field_definitions')->count();

        if ($totalDefinitions !== $checkpoint1Definitions + $newCustomerDefinitions) {
            throw new RuntimeException(
                "Checkpoint 2 failed: field_definitions count {$totalDefinitions} != {$checkpoint1Definitions} + {$newCustomerDefinitions}"
            );
        }

        $customerBindings = DB::table('field_bindings')
            ->where('object_type', FieldObjectType::Customer->value)
            ->count();

        if ($customerBindings !== 15) {
            throw new RuntimeException("Checkpoint 2 failed: expected 15 customer bindings, got {$customerBindings}");
        }

        foreach (FieldFoundationCustomerSeed::CODES as $code) {
            $this->assertCustomerFieldContract($code);
        }

        if (DB::table('customer_field_values')->count() !== 0) {
            throw new RuntimeException('Checkpoint 2 failed: customer_field_values must be empty');
        }

        $this->assertCheckpoint1ValueCountsUnchanged();
    }

    private function assertCustomerFieldContract(string $code): void
    {
        $expectedDef = FieldFoundationCustomerSeed::definitions()[$code];
        $expectedBinding = FieldFoundationCustomerSeed::bindings()[$code];

        $binding = DB::table('field_bindings')
            ->join('field_definitions', 'field_definitions.id', '=', 'field_bindings.field_definition_id')
            ->where('field_bindings.object_type', FieldObjectType::Customer->value)
            ->where('field_definitions.code', $code)
            ->select('field_bindings.*', 'field_definitions.code', 'field_definitions.data_type as def_data_type',
                'field_definitions.scope', 'field_definitions.localized_labels', 'field_definitions.description',
                'field_definitions.validation_rules', 'field_definitions.is_localizable', 'field_definitions.is_multi_value',
                'field_definitions.status as def_status', 'field_definitions.workspace_id as def_workspace_id')
            ->first();

        if ($binding === null) {
            throw new RuntimeException("Checkpoint 2 failed: missing customer binding for code {$code}");
        }

        if ($binding->def_workspace_id !== null || $binding->scope !== AttributeScope::System->value) {
            throw new RuntimeException("Checkpoint 2 failed: customer definition scope/workspace mismatch for {$code}");
        }

        $conflicts = $this->definitionConflicts((object) [
            'scope' => $binding->scope,
            'data_type' => $binding->def_data_type,
            'is_localizable' => $binding->is_localizable,
            'is_multi_value' => $binding->is_multi_value,
            'localized_labels' => $binding->localized_labels,
            'description' => $binding->description,
            'validation_rules' => $binding->validation_rules,
            'status' => $binding->def_status,
        ], $expectedDef);

        if ($conflicts !== []) {
            throw new RuntimeException("Checkpoint 2 definition contract failed for {$code}: ".implode(', ', $conflicts));
        }

        foreach (['storage_type', 'storage_path', 'field_group', 'sort_order', 'is_required', 'is_filterable', 'is_sortable', 'status'] as $field) {
            $expected = $expectedBinding[$field];
            $expectedValue = $expected instanceof \BackedEnum ? $expected->value : $expected;
            $actual = $binding->{$field};

            if (in_array($field, ['is_required', 'is_filterable', 'is_sortable'], true)) {
                if ((bool) $actual !== (bool) $expectedValue) {
                    throw new RuntimeException("Checkpoint 2 binding {$field} mismatch for {$code}");
                }

                continue;
            }

            if ((string) $actual !== (string) $expectedValue) {
                throw new RuntimeException("Checkpoint 2 binding {$field} mismatch for {$code}");
            }
        }

        if (! $this->jsonSemanticallyEqual($binding->visibility_settings, $expectedBinding['visibility_settings'])) {
            throw new RuntimeException("Checkpoint 2 visibility_settings mismatch for {$code}");
        }
    }

    private function assertCheckpoint1ValueCountsUnchanged(): void
    {
        if (DB::table('product_attribute_values')->count() !== DB::table('product_field_values')->count()) {
            throw new RuntimeException('Checkpoint 2 failed: product_field_values count changed');
        }

        if (DB::table('variant_attribute_values')->count() !== DB::table('variant_field_values')->count()) {
            throw new RuntimeException('Checkpoint 2 failed: variant_field_values count changed');
        }
    }

    private function dropOldSchema(): void
    {
        Schema::table('workspace_import_aliases', function (Blueprint $table) {
            $table->dropForeign(['attribute_definition_id']);
            $table->dropColumn('attribute_definition_id');
        });

        Schema::drop('product_attribute_values');
        Schema::drop('variant_attribute_values');
        Schema::drop('attribute_definitions');
    }

    private function assertFinalSchemaClean(): void
    {
        $patterns = [
            'attribute_definition',
            'attribute_group',
            'value_level',
            'product_attribute_values',
            'variant_attribute_values',
        ];

        $violations = $this->findSchemaNameViolations($patterns);

        if ($violations !== []) {
            throw new RuntimeException(
                'Post-up schema scan found legacy artifacts: '.implode(', ', $violations)
            );
        }

        if (Schema::hasColumn('workspace_import_aliases', 'attribute_definition_id')) {
            throw new RuntimeException('workspace_import_aliases.attribute_definition_id still exists after up()');
        }
    }

    /**
     * @param  list<string>  $patterns
     * @return list<string>
     */
    private function findSchemaNameViolations(array $patterns): array
    {
        $driver = Schema::getConnection()->getDriverName();
        $violations = [];

        if ($driver === 'mysql') {
            $database = Schema::getConnection()->getDatabaseName();
            $rows = DB::select(
                'SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ?',
                [$database]
            );

            foreach ($rows as $row) {
                foreach ($patterns as $pattern) {
                    if (str_contains(strtolower($row->TABLE_NAME), $pattern)
                      || str_contains(strtolower($row->COLUMN_NAME), $pattern)) {
                        $violations[] = "{$row->TABLE_NAME}.{$row->COLUMN_NAME}";
                    }
                }
            }

            $tables = DB::select(
                'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?',
                [$database]
            );

            foreach ($tables as $row) {
                foreach ($patterns as $pattern) {
                    if (str_contains(strtolower($row->TABLE_NAME), $pattern)) {
                        $violations[] = $row->TABLE_NAME;
                    }
                }
            }
        } else {
            foreach ($patterns as $pattern) {
                if (Schema::hasTable($pattern)) {
                    $violations[] = $pattern;
                }
            }

            if (Schema::hasColumn('workspace_import_aliases', 'attribute_definition_id')) {
                $violations[] = 'workspace_import_aliases.attribute_definition_id';
            }
        }

        return array_values(array_unique($violations));
    }

    /**
     * @return list<string>
     */
    private function collectDownPreflightFailures(): array
    {
        $reasons = [];

        $expectedAbsent = ['attribute_definitions', 'product_attribute_values', 'variant_attribute_values'];

        foreach ($expectedAbsent as $table) {
            if (Schema::hasTable($table)) {
                $reasons[] = "{$table} already exists — down() may have partially run before. Inspect manually.";
            }
        }

        if (Schema::hasColumn('workspace_import_aliases', 'attribute_definition_id')) {
            $reasons[] = 'workspace_import_aliases.attribute_definition_id already exists — unexpected state.';
        }

        $expectedPresent = [
            'field_definitions',
            'field_bindings',
            'product_field_values',
            'variant_field_values',
            'customer_field_values',
        ];

        foreach ($expectedPresent as $table) {
            if (! Schema::hasTable($table)) {
                $reasons[] = "{$table} not found — unexpected state for down(). Inspect manually.";
            }
        }

        if (! Schema::hasColumn('workspace_import_aliases', 'field_binding_id')) {
            $reasons[] = 'workspace_import_aliases.field_binding_id not found — unexpected state.';
        }

        $this->aliasDefinitionIdWasNotNull = ! $this->columnIsNullable('workspace_import_aliases', 'field_binding_id');

        if (DB::table('customer_field_values')->count() > 0) {
            $reasons[] = 'customer_field_values is not empty — cannot represent dynamic Customer data in old schema.';
        }

        $reasons = array_merge($reasons, $this->collectCustomerBindingRegressionFailures());
        $reasons = array_merge($reasons, $this->collectUnboundDefinitionFailures());
        $reasons = array_merge($reasons, $this->collectBothBindingMergeFailures());
        $reasons = array_merge($reasons, $this->collectStatusDivergenceFailures());
        $reasons = array_merge($reasons, $this->collectAliasRestorabilityFailures());

        return $reasons;
    }

    /**
     * @return list<string>
     */
    private function collectCustomerBindingRegressionFailures(): array
    {
        $reasons = [];
        $customerBindings = DB::table('field_bindings')
            ->join('field_definitions', 'field_definitions.id', '=', 'field_bindings.field_definition_id')
            ->where('field_bindings.object_type', FieldObjectType::Customer->value)
            ->select('field_bindings.*', 'field_definitions.code', 'field_definitions.data_type as def_data_type',
                'field_definitions.scope', 'field_definitions.localized_labels', 'field_definitions.description',
                'field_definitions.validation_rules', 'field_definitions.is_localizable', 'field_definitions.is_multi_value',
                'field_definitions.status as def_status', 'field_definitions.workspace_id as def_workspace_id')
            ->get();

        if ($customerBindings->count() !== 15) {
            $reasons[] = "Expected exactly 15 customer bindings, found {$customerBindings->count()}";
        }

        $codesFound = $customerBindings->pluck('code')->all();
        $unexpected = array_diff($codesFound, FieldFoundationCustomerSeed::CODES);
        $missing = array_diff(FieldFoundationCustomerSeed::CODES, $codesFound);

        if ($unexpected !== []) {
            $reasons[] = 'Unexpected customer binding codes: '.implode(', ', $unexpected);
        }

        if ($missing !== []) {
            $reasons[] = 'Missing customer binding codes: '.implode(', ', $missing);
        }

        $extraCustomerBindings = DB::table('field_bindings')
            ->where('object_type', FieldObjectType::Customer->value)
            ->whereNotIn('field_definition_id', $customerBindings->pluck('field_definition_id'))
            ->count();

        if ($extraCustomerBindings > 0) {
            $reasons[] = "Found {$extraCustomerBindings} customer bindings outside the 15 system codes";
        }

        foreach (FieldFoundationCustomerSeed::CODES as $code) {
            try {
                $this->assertCustomerFieldContract($code);
            } catch (RuntimeException $e) {
                $reasons[] = $e->getMessage();
            }
        }

        return $reasons;
    }

    /**
     * @return list<string>
     */
    private function collectUnboundDefinitionFailures(): array
    {
        $unbound = DB::table('field_definitions')
            ->whereNotIn('id', DB::table('field_bindings')->pluck('field_definition_id'))
            ->pluck('code', 'id')
            ->all();

        if ($unbound === []) {
            return [];
        }

        return ['Unbound field_definitions would be lost: '.json_encode($unbound)];
    }

    /**
     * @return list<string>
     */
    private function collectBothBindingMergeFailures(): array
    {
        $reasons = [];
        $bindingFields = [
            'storage_type', 'storage_path', 'field_group', 'is_required', 'is_filterable',
            'is_sortable', 'visibility_settings', 'sort_order', 'status',
        ];

        $definitions = DB::table('field_definitions')
            ->whereIn('id', function ($query) {
                $query->select('field_definition_id')
                    ->from('field_bindings')
                    ->whereIn('object_type', [FieldObjectType::Product->value, FieldObjectType::ProductVariant->value]);
            })
            ->get();

        foreach ($definitions as $definition) {
            $bindings = DB::table('field_bindings')
                ->where('field_definition_id', $definition->id)
                ->whereIn('object_type', [FieldObjectType::Product->value, FieldObjectType::ProductVariant->value])
                ->get();

            if ($bindings->count() === 2) {
                $product = $bindings->firstWhere('object_type', FieldObjectType::Product->value);
                $variant = $bindings->firstWhere('object_type', FieldObjectType::ProductVariant->value);

                foreach ($bindingFields as $field) {
                    if (! $this->valuesSemanticallyEqual($product->{$field}, $variant->{$field})) {
                        $reasons[] = "Definition {$definition->code}: product/variant binding mismatch on {$field}";
                    }
                }
            }
        }

        return $reasons;
    }

    /**
     * @return list<string>
     */
    private function collectStatusDivergenceFailures(): array
    {
        $reasons = [];

        $definitions = DB::table('field_definitions')
            ->whereIn('id', function ($query) {
                $query->select('field_definition_id')
                    ->from('field_bindings')
                    ->whereIn('object_type', [FieldObjectType::Product->value, FieldObjectType::ProductVariant->value]);
            })
            ->get();

        foreach ($definitions as $definition) {
            $bindings = DB::table('field_bindings')
                ->where('field_definition_id', $definition->id)
                ->whereIn('object_type', [FieldObjectType::Product->value, FieldObjectType::ProductVariant->value])
                ->get();

            if ($bindings->count() === 1) {
                if ($bindings->first()->status !== $definition->status) {
                    $reasons[] = "Definition {$definition->code}: binding status diverges from definition status";
                }
            }

            if ($bindings->count() === 2) {
                $statuses = $bindings->pluck('status')->unique();

                if ($statuses->count() > 1 || $statuses->first() !== $definition->status) {
                    $reasons[] = "Definition {$definition->code}: both-bindings status diverges from definition status";
                }
            }
        }

        return $reasons;
    }

    /**
     * @return list<string>
     */
    private function collectAliasRestorabilityFailures(): array
    {
        $reasons = [];

        foreach (DB::table('workspace_import_aliases')->get() as $alias) {
            $binding = DB::table('field_bindings')->where('id', $alias->field_binding_id)->first();

            if ($binding === null) {
                $reasons[] = "Alias {$alias->id} has orphan field_binding_id";

                continue;
            }

            if (! in_array($binding->object_type, [FieldObjectType::Product->value, FieldObjectType::ProductVariant->value], true)) {
                $reasons[] = "Alias {$alias->id} points to customer-only binding {$binding->id}";
            }
        }

        return $reasons;
    }

    private function recreateAttributeDefinitionsTable(): void
    {
        Schema::create('attribute_definitions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->nullable()->constrained('workspaces')->nullOnDelete();
            $table->string('code');
            $table->string('data_type');
            $table->string('scope');
            $table->string('value_level');
            $table->string('storage_type');
            $table->string('storage_path')->nullable();
            $table->string('attribute_group');
            $table->boolean('is_required')->default(false);
            $table->boolean('is_filterable')->default(false);
            $table->boolean('is_sortable')->default(false);
            $table->json('visibility_settings');
            $table->json('validation_rules')->nullable();
            $table->boolean('is_localizable')->default(false);
            $table->boolean('is_multi_value')->default(false);
            $table->string('status')->default('active');
            $table->integer('sort_order')->default(0);
            $table->json('localized_labels');
            $table->timestamps();
        });

        $this->addWorkspaceUniquenessKey('attribute_definitions', 'attribute_definitions_workspace_code_unique');
    }

    private function recreateAttributeValueTables(): void
    {
        Schema::create('product_attribute_values', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces');
            $table->unsignedBigInteger('product_id');
            $table->foreignUuid('attribute_definition_id')->constrained('attribute_definitions')->cascadeOnDelete();
            $table->text('value_text')->nullable();
            $table->decimal('value_num', 20, 6)->nullable();
            $table->json('value_jsonb')->nullable();
            $table->timestamps();

            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->unique(
                ['workspace_id', 'product_id', 'attribute_definition_id'],
                'product_attr_values_ws_product_attr_unique'
            );
        });

        Schema::create('variant_attribute_values', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces');
            $table->unsignedBigInteger('variant_id');
            $table->foreignUuid('attribute_definition_id')->constrained('attribute_definitions')->cascadeOnDelete();
            $table->text('value_text')->nullable();
            $table->decimal('value_num', 20, 6)->nullable();
            $table->json('value_jsonb')->nullable();
            $table->timestamps();

            $table->foreign('variant_id')->references('id')->on('product_variants')->cascadeOnDelete();
            $table->unique(
                ['workspace_id', 'variant_id', 'attribute_definition_id'],
                'variant_attr_values_ws_variant_attr_unique'
            );
        });
    }

    private function reconstructAttributeDefinitions(): void
    {
        $definitions = DB::table('field_definitions')
            ->whereIn('id', function ($query) {
                $query->select('field_definition_id')
                    ->from('field_bindings')
                    ->whereIn('object_type', [FieldObjectType::Product->value, FieldObjectType::ProductVariant->value]);
            })
            ->get();

        foreach ($definitions as $definition) {
            $bindings = DB::table('field_bindings')
                ->where('field_definition_id', $definition->id)
                ->whereIn('object_type', [FieldObjectType::Product->value, FieldObjectType::ProductVariant->value])
                ->get();

            $valueLevel = match ($bindings->count()) {
                1 => $bindings->first()->object_type === FieldObjectType::Product->value ? 'product' : 'variant',
                2 => 'both',
                default => throw new RuntimeException("Cannot reconstruct value_level for definition {$definition->code}"),
            };

            $sourceBinding = $bindings->firstWhere('object_type', FieldObjectType::Product->value)
              ?? $bindings->first();

            DB::table('attribute_definitions')->insert([
                'id' => $definition->id,
                'workspace_id' => $definition->workspace_id,
                'code' => $definition->code,
                'data_type' => $definition->data_type,
                'scope' => $definition->scope,
                'value_level' => $valueLevel,
                'storage_type' => $sourceBinding->storage_type,
                'storage_path' => $sourceBinding->storage_path,
                'attribute_group' => $sourceBinding->field_group,
                'is_required' => $sourceBinding->is_required,
                'is_filterable' => $sourceBinding->is_filterable,
                'is_sortable' => $sourceBinding->is_sortable,
                'visibility_settings' => $sourceBinding->visibility_settings,
                'validation_rules' => $definition->validation_rules,
                'is_localizable' => $definition->is_localizable,
                'is_multi_value' => $definition->is_multi_value,
                'status' => $definition->status,
                'sort_order' => $sourceBinding->sort_order,
                'localized_labels' => $definition->localized_labels,
                'created_at' => $definition->created_at,
                'updated_at' => $definition->updated_at,
            ]);
        }
    }

    private function copyFieldValuesBack(): void
    {
        foreach (DB::table('product_field_values')->orderBy('id')->get() as $row) {
            $binding = DB::table('field_bindings')->where('id', $row->field_binding_id)->first();

            DB::table('product_attribute_values')->insert([
                'id' => $row->id,
                'workspace_id' => $row->workspace_id,
                'product_id' => $row->product_id,
                'attribute_definition_id' => $binding->field_definition_id,
                'value_text' => $row->value_text,
                'value_num' => $row->value_num,
                'value_jsonb' => $row->value_jsonb,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ]);
        }

        foreach (DB::table('variant_field_values')->orderBy('id')->get() as $row) {
            $binding = DB::table('field_bindings')->where('id', $row->field_binding_id)->first();

            DB::table('variant_attribute_values')->insert([
                'id' => $row->id,
                'workspace_id' => $row->workspace_id,
                'variant_id' => $row->variant_id,
                'attribute_definition_id' => $binding->field_definition_id,
                'value_text' => $row->value_text,
                'value_num' => $row->value_num,
                'value_jsonb' => $row->value_jsonb,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ]);
        }
    }

    private function restoreAliasDefinitionColumn(bool $wasNotNull): void
    {
        Schema::table('workspace_import_aliases', function (Blueprint $table) {
            $table->foreignUuid('attribute_definition_id')->nullable()->after('workspace_id');
        });

        foreach (DB::table('workspace_import_aliases')->get() as $alias) {
            $binding = DB::table('field_bindings')->where('id', $alias->field_binding_id)->first();

            if ($binding === null) {
                throw new RuntimeException("Cannot restore alias {$alias->id}: orphan field_binding_id");
            }

            DB::table('workspace_import_aliases')
                ->where('id', $alias->id)
                ->update(['attribute_definition_id' => $binding->field_definition_id]);
        }

        if (DB::table('workspace_import_aliases')->whereNull('attribute_definition_id')->exists()) {
            throw new RuntimeException('Alias restore left NULL attribute_definition_id values');
        }

        if ($wasNotNull) {
            $this->setColumnNotNull('workspace_import_aliases', 'attribute_definition_id');
        }

        Schema::table('workspace_import_aliases', function (Blueprint $table) {
            $table->foreign('attribute_definition_id')->references('id')->on('attribute_definitions')->cascadeOnDelete();
        });
    }

    private function assertDownInvariants(): void
    {
        $productCount = DB::table('product_field_values')->count();
        $variantCount = DB::table('variant_field_values')->count();

        if ($productCount !== DB::table('product_attribute_values')->count()) {
            throw new RuntimeException('Down invariant failed: product value row count mismatch');
        }

        if ($variantCount !== DB::table('variant_attribute_values')->count()) {
            throw new RuntimeException('Down invariant failed: variant value row count mismatch');
        }

        $reconstructed = DB::table('field_definitions')
            ->whereIn('id', function ($query) {
                $query->select('field_definition_id')
                    ->from('field_bindings')
                    ->whereIn('object_type', [FieldObjectType::Product->value, FieldObjectType::ProductVariant->value]);
            })
            ->count();

        if ($reconstructed !== DB::table('attribute_definitions')->count()) {
            throw new RuntimeException('Down invariant failed: attribute_definitions count mismatch');
        }
    }

    private function dropNewSchema(): void
    {
        Schema::table('workspace_import_aliases', function (Blueprint $table) {
            $table->dropForeign(['field_binding_id']);
            $table->dropColumn('field_binding_id');
        });

        Schema::drop('customer_field_values');
        Schema::drop('product_field_values');
        Schema::drop('variant_field_values');
        Schema::drop('field_bindings');
        Schema::drop('field_definitions');
    }

    private function columnIsNullable(string $table, string $column): bool
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            $database = Schema::getConnection()->getDatabaseName();
            $row = DB::selectOne(
                'SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$database, $table, $column]
            );

            return $row !== null && $row->IS_NULLABLE === 'YES';
        }

        $columns = Schema::getColumnListing($table);

        return true;
    }

    private function setColumnNotNull(string $table, string $column): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            $type = $column === 'field_binding_id' || $column === 'attribute_definition_id'
              ? 'CHAR(36)'
              : 'VARCHAR(255)';

            DB::statement("ALTER TABLE {$table} MODIFY {$column} {$type} NOT NULL");
        } else {
            Schema::table($table, function (Blueprint $table) use ($column) {
                $table->uuid($column)->nullable(false)->change();
            });
        }
    }

    private function jsonSemanticallyEqual(mixed $a, mixed $b): bool
    {
        return $this->valuesSemanticallyEqual(
            is_string($a) ? json_decode($a, true) : $a,
            is_string($b) ? json_decode($b, true) : $b
        );
    }

    private function valuesSemanticallyEqual(mixed $a, mixed $b): bool
    {
        if (is_string($a) && $this->looksLikeJson($a)) {
            $a = json_decode($a, true);
        }

        if (is_string($b) && $this->looksLikeJson($b)) {
            $b = json_decode($b, true);
        }

        return $a == $b;
    }

    private function looksLikeJson(string $value): bool
    {
        return str_starts_with(trim($value), '{') || str_starts_with(trim($value), '[');
    }
}
