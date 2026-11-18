<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cases can now be opened by the customer too ("report a problem with items"): kind = shortfall (shop reports
 * units it can't provide) | problem (customer reports missing, damaged, wrong or late items); problem = which.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shortfall_cases', function (Blueprint $t) {
            $t->string('kind', 10)->default('shortfall')->after('report');
            $t->string('problem', 12)->nullable()->after('kind');
        });
    }

    public function down(): void
    {
        Schema::table('shortfall_cases', fn (Blueprint $t) => $t->dropColumn(['kind', 'problem']));
    }
};
