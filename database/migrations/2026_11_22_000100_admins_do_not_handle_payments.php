<?php

use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;

/**
 * Tester feedback 2026-11-22: admins only keep and track payment records. Checking, rejecting and recording
 * payments is the cashier's job, so the two permissions leave the stored admin defaults and per-admin lists.
 */
return new class extends Migration
{
    private const OUT = ['payments.verify', 'payments.record'];

    public function up(): void
    {
        $row = Setting::find('permissions.admin');
        if ($row && is_array($row->value)) {
            $row->update(['value' => array_values(array_diff($row->value, self::OUT))]);
        }
        User::whereNotNull('admin_perms')->get()->each(function (User $u) {
            if (is_array($u->admin_perms) && array_intersect($u->admin_perms, self::OUT)) {
                $u->update(['admin_perms' => array_values(array_diff($u->admin_perms, self::OUT))]);
            }
        });
    }

    public function down(): void
    {
        // not restored: admins no longer handle payments
    }
};
