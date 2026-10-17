<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connector_definitions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('direction');
            $table->string('status')->default('draft');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('connector_schema_sources', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('connector_definition_id')
                ->constrained('connector_definitions')
                ->restrictOnDelete();
            $table->string('code');
            $table->string('label');
            $table->string('source_kind');
            $table->string('acquisition_mode');
            $table->string('schema_scope');
            $table->string('reference_url')->nullable();
            $table->string('endpoint_path')->nullable();
            $table->string('schema_version')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->string('verification_status')->default('unverified');
            $table->timestamp('last_verified_at')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['connector_definition_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connector_schema_sources');
        Schema::dropIfExists('connector_definitions');
    }
};
