<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contractor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('onec_guid')->nullable()->unique();
            $table->string('onec_number')->nullable();
            $table->enum('status', [
                'new',
                'pending',
                'confirmed',
                'in_progress',
                'shipped',
                'delivered',
                'cancelled',
            ])->default('new');
            $table->decimal('total', 15, 2)->default(0);
            $table->decimal('total_with_vat', 15, 2)->default(0);
            $table->string('currency', 3)->default('UAH');
            $table->text('comment')->nullable();
            $table->text('manager_comment')->nullable();
            $table->boolean('needs_call')->default(false);
            $table->timestamp('transmitted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
