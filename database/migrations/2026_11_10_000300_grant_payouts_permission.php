<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

/** Existing installs store the default admin permissions in settings: add the new payouts permission to it. */
return new class extends Migration
{
    public function up(): void
    {
        $row = Setting::find('permissions.admin');
        if ($row && is_array($row->value) && ! in_array('payouts.manage', $row->value, true)) {
            $row->update(['value' => array_values(array_merge($row->value, ['payouts.manage']))]);
        }
    }

    public function down(): void
    {
        $row = Setting::find('permissions.admin');
        if ($row && is_array($row->value)) {
            $row->update(['value' => array_values(array_diff($row->value, ['payouts.manage']))]);
        }
    }
};
