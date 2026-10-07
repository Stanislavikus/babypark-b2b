<?php

use App\Enums\AttributeScope;
use App\Enums\AttributeStorageType;
use App\Enums\FieldObjectType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $labels = $this->legacyLabels();
        $this->assertLegacyLabelsAreDeterministic($labels);
        $bindingId = $this->canonicalBrandBindingIdOrNull(
            expectedStorageType: AttributeStorageType::Column->value,
            expectedStoragePath: 'products.brand',
        );
        $this->assertNoOtherLegacyBrandBindings($bindingId);

        Schema::create('brands', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->string('name');
            $table->uuid('logo_media_asset_id')->nullable();
            $table->text('short_description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['workspace_id', 'id'], 'brands_workspace_id_id_unique');
            $table->index(['workspace_id', 'is_active', 'name'], 'brands_workspace_active_name_index');
            $table->foreign(['workspace_id', 'logo_media_asset_id'], 'brands_workspace_logo_fk')
                ->references(['workspace_id', 'id'])->on('media_assets')->restrictOnDelete();
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->uuid('brand_id')->nullable()->after('category_id');
        });

        $this->materializeLegacyBrands($labels);

        $unbound = DB::table('products')
            ->whereNotNull('brand')
            ->where('brand', '!=', '')
            ->whereNull('brand_id')
            ->count();

        if ($unbound > 0) {
            throw new RuntimeException("Brand migration left {$unbound} non-empty Product Brand values unbound.");
        }

        Schema::table('products', function (Blueprint $table): void {
            $table->foreign(['workspace_id', 'brand_id'], 'products_workspace_brand_fk')
                ->references(['workspace_id', 'id'])
                ->on('brands')
                ->restrictOnDelete();
        });

        if ($bindingId !== null) {
            DB::table('field_bindings')
                ->where('id', $bindingId)
                ->update([
                    'storage_type' => AttributeStorageType::Relation->value,
                    'storage_path' => 'products.brand_id',
                    'updated_at' => now(),
                ]);
        }

        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('brand');
        });
    }

    public function down(): void
    {
        $bindingId = $this->canonicalBrandBindingIdOrNull(
            expectedStorageType: AttributeStorageType::Relation->value,
            expectedStoragePath: 'products.brand_id',
        );

        Schema::table('products', function (Blueprint $table): void {
            $table->string('brand')->nullable()->after('brand_id');
        });

        $rows = DB::table('products')
            ->leftJoin('brands', function ($join): void {
                $join->on('brands.id', '=', 'products.brand_id')
                    ->on('brands.workspace_id', '=', 'products.workspace_id');
            })
            ->whereNotNull('products.brand_id')
            ->orderBy('products.id')
            ->get([
                'products.id as product_id',
                'products.workspace_id as workspace_id',
                'brands.name as brand_name',
            ]);

        foreach ($rows as $row) {
            if (! is_string($row->brand_name) || trim($row->brand_name) === '') {
                throw new RuntimeException('Cannot restore legacy Product Brand string from missing Brand relation.');
            }

            DB::table('products')
                ->where('id', $row->product_id)
                ->where('workspace_id', $row->workspace_id)
                ->update(['brand' => $row->brand_name]);
        }

        if ($bindingId !== null) {
            DB::table('field_bindings')
                ->where('id', $bindingId)
                ->update([
                    'storage_type' => AttributeStorageType::Column->value,
                    'storage_path' => 'products.brand',
                    'updated_at' => now(),
                ]);
        }

        $driver = Schema::getConnection()->getDriverName();

        Schema::table('products', function (Blueprint $table) use ($driver): void {
            if ($driver === 'mysql') {
                $table->dropForeign('products_workspace_brand_fk');
            } else {
                $table->dropForeign(['workspace_id', 'brand_id']);
            }

            $table->dropColumn('brand_id');
        });

        Schema::dropIfExists('brands');
    }

    /**
     * @return list<object{workspace_id:string, brand:string}>
     */
    private function legacyLabels(): array
    {
        $rows = DB::table('products')
            ->whereNotNull('brand')
            ->select(['workspace_id', 'brand'])
            ->orderBy('workspace_id')
            ->orderBy('id')
            ->get();

        $exact = [];

        foreach ($rows as $row) {
            $raw = (string) $row->brand;

            if ($raw === '') {
                continue;
            }

            $key = (string) $row->workspace_id."\0".base64_encode($raw);
            $exact[$key] ??= $row;
        }

        return array_values($exact);
    }

    /** @param list<object{workspace_id:string, brand:string}> $labels */
    private function assertLegacyLabelsAreDeterministic(array $labels): void
    {
        foreach ($labels as $row) {
            $raw = (string) $row->brand;

            if ($raw !== trim($raw)) {
                throw new RuntimeException('Legacy Product Brand contains leading/trailing whitespace; migration stopped.');
            }
        }
    }

    /** @param list<object{workspace_id:string, brand:string}> $labels */
    private function materializeLegacyBrands(array $labels): void
    {
        $now = now();

        foreach ($labels as $row) {
            $workspaceId = (string) $row->workspace_id;
            $display = trim((string) $row->brand);
            $brandId = (string) Str::uuid();

            DB::table('brands')->insert([
                'id' => $brandId,
                'workspace_id' => $workspaceId,
                'name' => $display,
                'logo_media_asset_id' => null,
                'short_description' => null,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $query = DB::table('products')
                ->where('workspace_id', $workspaceId);

            $this->whereExactLegacyBrand($query, (string) $row->brand)
                ->update(['brand_id' => $brandId]);
        }
    }

    private function whereExactLegacyBrand($query, string $brand)
    {
        return Schema::getConnection()->getDriverName() === 'mysql'
            ? $query->whereRaw('BINARY brand = ?', [$brand])
            : $query->where('brand', $brand);
    }

    private function canonicalBrandBindingIdOrNull(string $expectedStorageType, string $expectedStoragePath): ?string
    {
        $definitionId = DB::table('field_definitions')
            ->whereNull('workspace_id')
            ->where('scope', AttributeScope::System->value)
            ->where('code', 'brand')
            ->value('id');

        if (! is_string($definitionId) || $definitionId === '') {
            return null;
        }

        $bindings = DB::table('field_bindings')
            ->where('field_definition_id', $definitionId)
            ->where('object_type', FieldObjectType::Product->value)
            ->get(['id', 'storage_type', 'storage_path']);

        if ($bindings->count() !== 1) {
            throw new RuntimeException('Canonical Product Brand binding is missing or ambiguous; migration stopped.');
        }

        $binding = $bindings->first();

        if ((string) $binding->storage_type !== $expectedStorageType
            || (string) $binding->storage_path !== $expectedStoragePath
        ) {
            throw new RuntimeException('Canonical Product Brand binding is in an unexpected storage state; migration stopped.');
        }

        return (string) $binding->id;
    }

    private function assertNoOtherLegacyBrandBindings(?string $canonicalBindingId): void
    {
        $unexpectedQuery = DB::table('field_bindings')
            ->where('storage_path', 'products.brand');

        if ($canonicalBindingId !== null) {
            $unexpectedQuery->where('id', '!=', $canonicalBindingId);
        }

        $unexpected = $unexpectedQuery->count();

        if ($unexpected > 0) {
            throw new RuntimeException(
                "Found {$unexpected} additional FieldBinding rows referencing products.brand; migration stopped."
            );
        }
    }
};
