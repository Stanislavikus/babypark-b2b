<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Small, fixed lookup table for price kinds. Also the bridge to the GMC
     * standard: gmc_term is populated ONLY when Google has a literally-named
     * attribute that matches this concept directly. It is not a channel-export
     * mapping decision (that belongs in the future "Маппінг каналів" table).
     */
    public function up(): void
    {
        Schema::create('price_types', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('gmc_term')->nullable();
            $table->boolean('is_contractor_specific');
        });

        // Seed exactly the three types currently in use. Don't add speculative types.
        DB::table('price_types')->insert([
            [
                'code' => 'contract_price',
                'name' => 'Ціна контрагента',
                'gmc_term' => null,
                'is_contractor_specific' => true,
            ],
            [
                'code' => 'list_price',
                'name' => 'РРЦ',
                'gmc_term' => null,
                'is_contractor_specific' => false,
            ],
            [
                'code' => 'cost_of_goods_sold',
                'name' => 'Вхідна ціна',
                'gmc_term' => 'cost_of_goods_sold',
                'is_contractor_specific' => false,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('price_types');
    }
};
