<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->assertExistingHierarchyIsWorkspaceSafe();

        Schema::table('categories', function (Blueprint $table): void {
            $table->unsignedInteger('sort_order')->default(0)->after('parent_id');
            $table->boolean('is_active')->default(true)->after('sort_order');
            $table->index(['workspace_id', 'parent_id', 'sort_order'], 'categories_workspace_parent_sort_index');
            $table->foreign(['workspace_id', 'parent_id'], 'categories_workspace_parent_fk')
                ->references(['workspace_id', 'id'])
                ->on('categories')
                ->restrictOnDelete();
        });

        $this->initializeSortOrder();
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        Schema::table('categories', function (Blueprint $table) use ($driver): void {
            if ($driver === 'mysql') {
                $table->dropForeign('categories_workspace_parent_fk');
            } else {
                $table->dropForeign(['workspace_id', 'parent_id']);
            }

            $table->dropIndex('categories_workspace_parent_sort_index');
            $table->dropColumn(['sort_order', 'is_active']);
        });
    }

    private function initializeSortOrder(): void
    {
        $rows = DB::table('categories')
            ->orderBy('workspace_id')
            ->orderBy('parent_id')
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'workspace_id', 'parent_id']);

        $positions = [];

        foreach ($rows as $row) {
            $key = (string) $row->workspace_id.'|'.($row->parent_id === null ? 'root' : (string) $row->parent_id);
            $sortOrder = $positions[$key] ?? 0;

            DB::table('categories')
                ->where('id', $row->id)
                ->where('workspace_id', $row->workspace_id)
                ->update(['sort_order' => $sortOrder]);

            $positions[$key] = $sortOrder + 1;
        }
    }

    private function assertExistingHierarchyIsWorkspaceSafe(): void
    {
        $rows = DB::table('categories')->orderBy('id')->get(['id', 'workspace_id', 'parent_id']);
        $byId = $rows->keyBy(fn (object $row): int => (int) $row->id);

        foreach ($rows as $row) {
            $seen = [];
            $current = $row;

            while ($current->parent_id !== null) {
                $currentId = (int) $current->id;

                if (isset($seen[$currentId])) {
                    throw new RuntimeException('Category hierarchy contains a cycle; migration stopped.');
                }

                $seen[$currentId] = true;
                $parent = $byId->get((int) $current->parent_id);

                if ($parent === null) {
                    throw new RuntimeException('Category hierarchy references a missing parent; migration stopped.');
                }

                if ((string) $parent->workspace_id !== (string) $row->workspace_id) {
                    throw new RuntimeException('Category hierarchy crosses workspace boundaries; migration stopped.');
                }

                $current = $parent;
            }
        }
    }
};
