<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Complete legal name, birthday and gender of an account.
 *
 *   users.first_name, middle_name (optional), last_name   the legal name in parts
 *   users.name                                            kept: the full display name ("First Middle Last"), kept in
 *                                                         step by the User model, so every page that shows a name works
 *   users.birthday                                        date of birth (the age is worked out from it, never stored)
 *   users.gender                                          male | female | prefer_not
 *
 * Existing accounts: the single name is split (last word = last name, the rest = first name) and left as it was.
 * Birthday and gender stay empty until the person adds them on their profile.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('first_name', 80)->nullable()->after('name');
            $t->string('middle_name', 80)->nullable()->after('first_name');
            $t->string('last_name', 80)->nullable()->after('middle_name');
            $t->date('birthday')->nullable()->after('last_name');
            $t->string('gender', 20)->nullable()->after('birthday');
        });
        foreach (DB::table('users')->whereNull('first_name')->get(['id', 'name']) as $u) {
            $parts = preg_split('/\s+/', trim((string) $u->name)) ?: [];
            $last = count($parts) > 1 ? array_pop($parts) : null;
            DB::table('users')->where('id', $u->id)->update(['first_name' => mb_substr(implode(' ', $parts), 0, 80) ?: null, 'last_name' => $last ? mb_substr($last, 0, 80) : null]);
        }
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['first_name', 'middle_name', 'last_name', 'birthday', 'gender']));
    }
};
