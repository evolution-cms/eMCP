<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('permissions') || !Schema::hasTable('permissions_groups')) {
            return;
        }

        $groupId = $this->resolveGroupId();
        if ($groupId === null) {
            return;
        }

        $payload = [
            'name' => 'Manage eMCP',
            'lang_key' => 'eMCP::global.permission_manage',
            'group_id' => $groupId,
            'disabled' => 0,
            'updated_at' => now(),
        ];

        $existing = DB::table('permissions')->where('key', 'emcp_manage')->first();
        if ($existing) {
            DB::table('permissions')->where('key', 'emcp_manage')->update($payload);
        } else {
            try {
                DB::table('permissions')->insert($payload + [
                    'key' => 'emcp_manage',
                    'created_at' => now(),
                ]);
            } catch (QueryException) {
                DB::table('permissions')->where('key', 'emcp_manage')->update($payload);
            }
        }

        if (!Schema::hasTable('role_permissions')) {
            return;
        }

        $exists = DB::table('role_permissions')
            ->where('role_id', 1)
            ->where('permission', 'emcp_manage')
            ->exists();

        if (!$exists) {
            try {
                DB::table('role_permissions')->insert([
                    'role_id' => 1,
                    'permission' => 'emcp_manage',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (QueryException) {
                // Duplicate insert under race is safe to ignore.
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('role_permissions')) {
            DB::table('role_permissions')
                ->where('role_id', 1)
                ->where('permission', 'emcp_manage')
                ->delete();
        }

        if (Schema::hasTable('permissions')) {
            DB::table('permissions')->where('key', 'emcp_manage')->delete();
        }
    }

    private function resolveGroupId(): ?int
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
            $group = DB::table('permissions_groups')->where('name', 'eMCP')->first();

            return $group ? (int)$group->id : null;
        }
    }
};
