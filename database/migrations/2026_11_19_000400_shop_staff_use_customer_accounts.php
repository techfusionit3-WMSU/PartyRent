<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Shop employees (cashier / inventory / staff) are ordinary customer accounts now: they sign in as a customer
 * and switch to Business Mode for the shop they work in. Whether they still work there is the membership's
 * status (an owner deactivating them no longer locks their own customer account).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_staff', function (Blueprint $t) {
            $t->string('status', 12)->default('active')->after('role');
        });

        foreach (DB::table('shop_staff')->get() as $m) {
            $u = DB::table('users')->where('id', $m->user_id)->first();
            if (! $u) {
                continue;
            }
            $roles = json_decode($u->roles ?? '[]', true) ?: [];
            if (! array_intersect(['cashier', 'employee'], $roles)) {
                continue;
            }
            if ($u->status === 'deactivated') {
                DB::table('shop_staff')->where('id', $m->id)->update(['status' => 'deactivated']);
            }
            DB::table('users')->where('id', $u->id)->update([
                'roles' => json_encode(['customer']),
                'status' => $u->status === 'deactivated' ? 'active' : $u->status,
            ]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('shop_staff')->get() as $m) {
            DB::table('users')->where('id', $m->user_id)->update([
                'roles' => json_encode([$m->role === 'cashier' ? 'cashier' : 'employee']),
                'status' => $m->status === 'deactivated' ? 'deactivated' : 'active',
            ]);
        }
        Schema::table('shop_staff', fn (Blueprint $t) => $t->dropColumn('status'));
    }
};
