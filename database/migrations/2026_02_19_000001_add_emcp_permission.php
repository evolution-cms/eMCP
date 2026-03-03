<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('permissions_groups') || !Schema::hasTable('permissions')) {
            return;
        }

        $groupId = $this->getOrCreateGroup();
        $this->upsertPermission($groupId);
        $this->assignPermissionToAdmin();
    }

    public function down(): void
    {
        if (Schema::hasTable('role_permissions')) {
            DB::table('role_permissions')
                ->where('role_id', 1)
                ->where('permission', 'emcp')
                ->delete();
        }

        if (Schema::hasTable('permissions')) {
            DB::table('permissions')->where('key', 'emcp')->delete();
        }

        if (!Schema::hasTable('permissions_groups')) {
            return;
        }

        $group = DB::table('permissions_groups')->where('name', 'eMCP')->first();
        if (!$group) {
            return;
        }

        $hasPermissions = Schema::hasTable('permissions')
            && DB::table('permissions')->where('group_id', $group->id)->exists();

        if (!$hasPermissions) {
            DB::table('permissions_groups')->where('id', $group->id)->delete();
        }
    }

    protected function getOrCreateGroup(): int
    {
        $group = DB::table('permissions_groups')
            ->where('name', 'eMCP')
            ->first();

        if ($group) {
            return (int)$group->id;
        }

        try {
            return (int)DB::table('permissions_groups')->insertGetId([
                'name' => 'eMCP',
                'lang_key' => 'eMCP::global.permissions_group',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException) {
            $this->fixPostgresSequence('permissions_groups');

            $group = DB::table('permissions_groups')->where('name', 'eMCP')->first();
            if ($group) {
                return (int)$group->id;
            }

            return (int)DB::table('permissions_groups')->insertGetId([
                'name' => 'eMCP',
                'lang_key' => 'eMCP::global.permissions_group',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    protected function upsertPermission(int $groupId): void
    {
        $payload = [
            'name' => 'Access eMCP',
            'lang_key' => 'eMCP::global.permission_access',
            'group_id' => $groupId,
            'disabled' => 0,
            'updated_at' => now(),
        ];

        $exists = DB::table('permissions')->where('key', 'emcp')->first();
        if ($exists) {
            DB::table('permissions')->where('key', 'emcp')->update($payload);
            return;
        }

        try {
            DB::table('permissions')->insert($payload + [
                'key' => 'emcp',
                'created_at' => now(),
            ]);
        } catch (QueryException) {
            DB::table('permissions')->where('key', 'emcp')->update($payload);
        }
    }

    protected function assignPermissionToAdmin(): void
    {
        if (!Schema::hasTable('role_permissions')) {
            return;
        }

        $exists = DB::table('role_permissions')
            ->where('role_id', 1)
            ->where('permission', 'emcp')
            ->exists();

        if ($exists) {
            return;
        }

        try {
            DB::table('role_permissions')->insert([
                'role_id' => 1,
                'permission' => 'emcp',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException) {
            // Duplicate insert under race is safe to ignore.
        }
    }

    protected function fixPostgresSequence(string $table): void
    {
        try {
            $fullTable = DB::getTablePrefix() . $table;
            $maxId = DB::table($table)->max('id') ?? 0;
            DB::statement("SELECT setval(pg_get_serial_sequence('{$fullTable}', 'id'), " . ($maxId + 1) . ', false)');
        } catch (\Throwable) {
            // Non-PostgreSQL or missing sequence.
        }
    }
};
