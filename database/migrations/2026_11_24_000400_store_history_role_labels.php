<?php

use App\Http\Middleware\RecordStaffAction;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Store History: older lines stored the account mode ("Supplier"); show the person's role in the shop instead. */
return new class extends Migration
{
    public function up(): void
    {
        $ids = DB::table('audit_logs')->whereNotNull('supplier_id')->whereNotNull('actor_id')
            ->whereIn('role_label', ['Supplier', 'Cashier', 'Customer', 'Staff', 'Admin', 'Superadmin', 'Employee'])->distinct()->pluck('actor_id');
        foreach (User::whereIn('id', $ids)->get() as $u) {
            DB::table('audit_logs')->where('actor_id', $u->id)->whereNotNull('supplier_id')
                ->whereIn('role_label', ['Supplier', 'Cashier', 'Customer', 'Staff', 'Admin', 'Superadmin', 'Employee'])
                ->update(['role_label' => RecordStaffAction::roleLabel($u)]);
        }
    }

    public function down(): void
    {
    }
};
