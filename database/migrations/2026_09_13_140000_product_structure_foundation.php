<?php

use App\Enums\AttributeStatus;
use App\Enums\FieldObjectType;
use App\Services\ProductStructure\ProductStructureIdentity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $this->createTables();
        $this->addProductTypeReference();
        $this->bootstrapExistingWorkspaces();
    }

    public function down(): void
    {
        if (Schema::hasColumn('products', 'product_type_id')) {
            if (DB::connection()->getDriverName() !== 'sqlite') {
                Schema::table('products', function (Blueprint $table): void {
                    $table->dropForeign('products_workspace_product_type_fk');
                });
            }

            Schema::table('products', function (Blueprint $table): void {
                $table->dropColumn('product_type_id');
            });
        }

        Schema::dropIfExists('product_active_optional_groups');
        Schema::dropIfExists('product_type_field_placements');
        Schema::dropIfExists('product_type_group_placements');
        Schema::dropIfExists('attribute_groups');
        Schema::dropIfExists('product_types');
    }

    private function createTables(): void
    {
        Schema::create('product_types', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->string('code');
            $table->json('localized_labels');
            $table->text('description')->nullable();
            $table->string('status')->default('active');
            $table->boolean('is_default')->default(false);
            $table->unsignedTinyInteger('default_marker')->nullable()->storedAs(
                'CASE WHEN is_default = 1 THEN 1 ELSE NULL END'
            );
            $table->unsignedBigInteger('structure_revision')->default(1);
            $table->timestamps();

            $table->unique(['workspace_id', 'code'], 'product_types_workspace_code_unique');
            $table->unique(['workspace_id', 'id'], 'product_types_workspace_id_id_unique');
            $table->unique(['workspace_id', 'default_marker'], 'product_types_one_default_per_workspace_unique');
        });

        Schema::create('attribute_groups', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->string('code');
            $table->json('localized_labels');
            $table->text('description')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();

            $table->unique(['workspace_id', 'code'], 'attribute_groups_workspace_code_unique');
            $table->unique(['workspace_id', 'id'], 'attribute_groups_workspace_id_id_unique');
        });

        Schema::create('product_type_group_placements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->uuid('product_type_id');
            $table->uuid('attribute_group_id');
            $table->integer('sort_order')->default(0);
            $table->boolean('is_optional')->default(false);
            $table->boolean('default_active')->default(true);
            $table->timestamps();

            $table->unique(['workspace_id', 'id'], 'pt_group_places_workspace_id_id_unique');
            $table->unique(['workspace_id', 'product_type_id', 'id'], 'pt_group_places_workspace_type_id_unique');
            $table->unique(['product_type_id', 'attribute_group_id'], 'pt_group_places_type_group_unique');
            $table->foreign(['workspace_id', 'product_type_id'], 'pt_group_places_workspace_type_fk')
                ->references(['workspace_id', 'id'])->on('product_types')->cascadeOnDelete();
            $table->foreign(['workspace_id', 'attribute_group_id'], 'pt_group_places_workspace_group_fk')
                ->references(['workspace_id', 'id'])->on('attribute_groups')->restrictOnDelete();
        });

        Schema::create('product_type_field_placements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->uuid('product_type_id');
            $table->uuid('product_type_group_placement_id');
            $table->foreignUuid('field_binding_id')->constrained('field_bindings')->restrictOnDelete();
            $table->integer('sort_order')->default(0);
            $table->boolean('required_for_completeness')->default(false);
            $table->timestamps();

            $table->unique(['workspace_id', 'id'], 'pt_field_places_workspace_id_id_unique');
            $table->unique(['product_type_id', 'field_binding_id'], 'pt_field_places_type_binding_unique');
            $table->foreign(['workspace_id', 'product_type_id'], 'pt_field_places_workspace_type_fk')
                ->references(['workspace_id', 'id'])->on('product_types')->cascadeOnDelete();
            $table->foreign(
                ['workspace_id', 'product_type_id', 'product_type_group_placement_id'],
                'pt_field_places_workspace_type_group_fk'
            )->references(['workspace_id', 'product_type_id', 'id'])
                ->on('product_type_group_placements')->cascadeOnDelete();
        });

        Schema::create('product_active_optional_groups', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->unsignedBigInteger('product_id');
            $table->uuid('product_type_group_placement_id');
            $table->boolean('is_active');
            $table->timestamps();

            $table->unique(['product_id', 'product_type_group_placement_id'], 'product_optional_groups_product_place_unique');
            $table->foreign(['workspace_id', 'product_id'], 'product_optional_groups_workspace_product_fk')
                ->references(['workspace_id', 'id'])->on('products')->cascadeOnDelete();
            $table->foreign(['workspace_id', 'product_type_group_placement_id'], 'product_optional_groups_workspace_place_fk')
                ->references(['workspace_id', 'id'])->on('product_type_group_placements')->cascadeOnDelete();
        });
    }

    private function addProductTypeReference(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->uuid('product_type_id')->nullable()->after('category_id');
        });

        if (DB::connection()->getDriverName() !== 'sqlite') {
            Schema::table('products', function (Blueprint $table): void {
                $table->foreign(['workspace_id', 'product_type_id'], 'products_workspace_product_type_fk')
                    ->references(['workspace_id', 'id'])->on('product_types')->restrictOnDelete();
            });
        }
    }

    private function bootstrapExistingWorkspaces(): void
    {
        foreach (DB::table('workspaces')->orderBy('id')->pluck('id') as $workspaceId) {
            $workspaceId = (string) $workspaceId;
            $productTypeId = ProductStructureIdentity::basicProductTypeId($workspaceId);
            $now = now();

            DB::table('product_types')->updateOrInsert(
                ['id' => $productTypeId],
                [
                    'workspace_id' => $workspaceId,
                    'code' => 'basic_product',
                    'localized_labels' => json_encode(['en' => 'Basic Product', 'uk' => 'Базовий товар'], JSON_THROW_ON_ERROR),
                    'description' => null,
                    'status' => 'active',
                    'is_default' => true,
                    'structure_revision' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );

            $bindings = DB::table('field_bindings')
                ->where('status', AttributeStatus::Active->value)
                ->whereIn('object_type', [FieldObjectType::Product->value, FieldObjectType::ProductVariant->value])
                ->where(function ($query) use ($workspaceId): void {
                    $query->whereNull('workspace_id')->orWhere('workspace_id', $workspaceId);
                })
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get();

            $bindings = $bindings->filter(function (object $binding): bool {
                $visibility = json_decode((string) $binding->visibility_settings, true);

                return is_array($visibility) && ($visibility['admin'] ?? false) === true;
            })->values();

            $groupOrder = $bindings->groupBy('field_group')
                ->map(fn ($rows) => (int) $rows->min('sort_order'))
                ->sort()
                ->keys()
                ->values();

            foreach ($groupOrder as $groupIndex => $groupCode) {
                $groupCode = (string) $groupCode;
                $groupId = ProductStructureIdentity::attributeGroupId($workspaceId, $groupCode);
                $groupPlacementId = ProductStructureIdentity::groupPlacementId($workspaceId, $productTypeId, $groupId);

                DB::table('attribute_groups')->updateOrInsert(
                    ['id' => $groupId],
                    [
                        'workspace_id' => $workspaceId,
                        'code' => $groupCode,
                        'localized_labels' => json_encode(['en' => Str::headline($groupCode)], JSON_THROW_ON_ERROR),
                        'description' => null,
                        'status' => 'active',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                );

                DB::table('product_type_group_placements')->updateOrInsert(
                    ['id' => $groupPlacementId],
                    [
                        'workspace_id' => $workspaceId,
                        'product_type_id' => $productTypeId,
                        'attribute_group_id' => $groupId,
                        'sort_order' => $groupIndex * 100,
                        'is_optional' => false,
                        'default_active' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                );

                foreach ($bindings->where('field_group', $groupCode) as $binding) {
                    DB::table('product_type_field_placements')->updateOrInsert(
                        ['id' => ProductStructureIdentity::fieldPlacementId($workspaceId, $productTypeId, (string) $binding->id)],
                        [
                            'workspace_id' => $workspaceId,
                            'product_type_id' => $productTypeId,
                            'product_type_group_placement_id' => $groupPlacementId,
                            'field_binding_id' => $binding->id,
                            'sort_order' => (int) $binding->sort_order,
                            'required_for_completeness' => (bool) $binding->is_required,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ],
                    );
                }
            }

            DB::table('products')
                ->where('workspace_id', $workspaceId)
                ->whereNull('product_type_id')
                ->update(['product_type_id' => $productTypeId]);
        }
    }
};
