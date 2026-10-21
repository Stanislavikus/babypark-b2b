<?php

use App\Support\Migrations\MasterMediaAssociationUniquenessPreflight;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $preflight = new MasterMediaAssociationUniquenessPreflight;
        $preflight->assertNoDuplicates('product_media', 'product_id');
        $preflight->assertNoDuplicates('variant_media', 'variant_id');

        Schema::table('product_media', function (Blueprint $table): void {
            $table->string('locale_scope_key', 32)
                ->storedAs(MasterMediaAssociationUniquenessPreflight::LOCALE_SCOPE_SQL);
            $table->unique(
                ['workspace_id', 'product_id', 'media_asset_id', 'locale_scope_key'],
                'product_media_owner_asset_locale_unique',
            );
        });

        Schema::table('variant_media', function (Blueprint $table): void {
            $table->string('locale_scope_key', 32)
                ->storedAs(MasterMediaAssociationUniquenessPreflight::LOCALE_SCOPE_SQL);
            $table->unique(
                ['workspace_id', 'variant_id', 'media_asset_id', 'locale_scope_key'],
                'variant_media_owner_asset_locale_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('variant_media', function (Blueprint $table): void {
            $table->dropUnique('variant_media_owner_asset_locale_unique');
            $table->dropColumn('locale_scope_key');
        });

        Schema::table('product_media', function (Blueprint $table): void {
            $table->dropUnique('product_media_owner_asset_locale_unique');
            $table->dropColumn('locale_scope_key');
        });
    }
};
