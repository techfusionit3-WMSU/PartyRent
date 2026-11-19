<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Company roles live inside the company (docs/ROLE_MODULES_PLAN.md):
 * a member can hold several roles (each opens its own module) and the owner can add or remove
 * single permissions per member. `role` stays as the member's main role; `roles` is the full list.
 * "Staff" is renamed "Field Crew" (key crew).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_staff', function (Blueprint $t) {
            $t->json('roles')->nullable()->after('role');
            $t->json('perms_add')->nullable()->after('roles');
            $t->json('perms_remove')->nullable()->after('perms_add');
        });
        Schema::table('staff_invites', function (Blueprint $t) {
            $t->json('roles')->nullable()->after('role');
        });
        foreach (['shop_staff', 'staff_invites'] as $table) {
            DB::table($table)->where('role', 'staff')->update(['role' => 'crew']);
            foreach (DB::table($table)->get(['id', 'role']) as $row) {
                DB::table($table)->where('id', $row->id)->update(['roles' => json_encode([$row->role])]);
            }
        }
    }

    public function down(): void
    {
        foreach (['shop_staff', 'staff_invites'] as $table) {
            DB::table($table)->where('role', 'crew')->update(['role' => 'staff']);
            DB::table($table)->whereNotIn('role', ['cashier', 'inventory', 'staff'])->update(['role' => 'staff']);
        }
        Schema::table('shop_staff', fn (Blueprint $t) => $t->dropColumn(['roles', 'perms_add', 'perms_remove']));
        Schema::table('staff_invites', fn (Blueprint $t) => $t->dropColumn('roles'));
    }
};
