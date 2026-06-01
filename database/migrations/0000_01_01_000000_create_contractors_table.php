<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contractors', function (Blueprint $table) {
            $table->id();
            $table->uuid('onec_guid')->unique();
            $table->string('name');
            $table->string('short_name')->nullable();
            $table->string('edrpou', 20)->nullable();
            $table->string('ipn', 20)->nullable();
            $table->string('manager_name')->nullable();
            $table->string('manager_phone')->nullable();
            $table->string('login')->unique();
            $table->string('password');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('payment_delay_days')->default(0);
            $table->decimal('credit_limit', 15, 2)->default(0);
            $table->decimal('current_debt', 15, 2)->default(0);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contractors');
    }
};
