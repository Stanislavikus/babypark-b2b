<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone')->nullable()->after('email');
            $table->date('vacation_until')->nullable()->after('phone');
        });

        Schema::table('contractors', function (Blueprint $table) {
            $table->unsignedBigInteger('account_manager_id')->nullable()->after('manager_phone');
            $table->unsignedBigInteger('backup_manager_id')->nullable()->after('account_manager_id');

            $table->foreign('account_manager_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('backup_manager_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('contractors', function (Blueprint $table) {
            $table->dropForeign(['account_manager_id']);
            $table->dropForeign(['backup_manager_id']);
            $table->dropColumn(['account_manager_id', 'backup_manager_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['phone', 'vacation_until']);
        });
    }
};
