<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

/**
 * Bank / debit transfers land in PartyRent's account, so PartyRent's admins verify them (not the shops).
 * Existing installs store the default admin permissions in settings: add the two new ones.
 */
return new class extends Migration
{
    private const NEW = ['payments.verify', 'payments.record'];

    public function up(): void
    {
        $row = Setting::find('permissions.admin');
        if ($row && is_array($row->value)) {
            $row->update(['value' => array_values(array_unique(array_merge($row->value, self::NEW)))]);
        }
    }

    public function down(): void
    {
        $row = Setting::find('permissions.admin');
        if ($row && is_array($row->value)) {
            $row->update(['value' => array_values(array_diff($row->value, self::NEW))]);
        }
    }
};
