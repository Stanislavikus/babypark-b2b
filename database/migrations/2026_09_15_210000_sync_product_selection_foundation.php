<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_configuration_product_selections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->uuid('sync_configuration_id');
            $table->unsignedBigInteger('product_id');
            $table->timestamps();

            $table->unique(['workspace_id', 'id'], 'scps_ws_id_unique');
            $table->unique(['sync_configuration_id', 'product_id'], 'scps_config_product_unique');
        });

        Schema::table('sync_configuration_product_selections', function (Blueprint $table) {
            $table->foreign(['workspace_id', 'sync_configuration_id'], 'scps_ws_config_fk')
                ->references(['workspace_id', 'id'])->on('sync_configurations')->cascadeOnDelete();
            $table->foreign(['workspace_id', 'product_id'], 'scps_ws_product_fk')
                ->references(['workspace_id', 'id'])->on('products')->restrictOnDelete();
        });

        $this->backfillHistoricalAllProductsSelections();
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            Schema::table('sync_configuration_product_selections', function (Blueprint $table) {
                $table->dropForeign('scps_ws_product_fk');
                $table->dropForeign('scps_ws_config_fk');
            });
        }

        Schema::dropIfExists('sync_configuration_product_selections');
    }

    private function backfillHistoricalAllProductsSelections(): void
    {
        DB::table('sync_configurations')
            ->where('data_domain', 'products')
            ->orderBy('id')
            ->chunk(100, function ($configurations): void {
                foreach ($configurations as $configuration) {
                    DB::table('products')
                        ->where('workspace_id', (string) $configuration->workspace_id)
                        ->orderBy('id')
                        ->chunkById(500, function ($products) use ($configuration): void {
                            $now = now();
                            $rows = [];

                            foreach ($products as $product) {
                                $rows[] = [
                                    'id' => (string) Str::uuid(),
                                    'workspace_id' => (string) $configuration->workspace_id,
                                    'sync_configuration_id' => (string) $configuration->id,
                                    'product_id' => (int) $product->id,
                                    'created_at' => $now,
                                    'updated_at' => $now,
                                ];
                            }

                            if ($rows !== []) {
                                DB::table('sync_configuration_product_selections')->insert($rows);
                            }
                        }, 'id');
                }
            });
    }
};
