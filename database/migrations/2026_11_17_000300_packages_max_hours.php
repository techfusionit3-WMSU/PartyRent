<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Reference package form: "Maximum rental hours (optional)" (min_hours came with the v4 port). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packages', fn (Blueprint $t) => $t->decimal('max_hours', 4, 1)->nullable());
    }

    public function down(): void
    {
        Schema::table('packages', fn (Blueprint $t) => $t->dropColumn('max_hours'));
    }
};
