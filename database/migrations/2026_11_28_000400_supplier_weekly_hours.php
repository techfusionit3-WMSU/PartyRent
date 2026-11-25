<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Operating hours per day of the week (App\Support\WeeklyHours); suppliers.hours keeps the one-line summary. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', fn (Blueprint $t) => $t->json('weekly_hours')->nullable());
    }

    public function down(): void
    {
        Schema::table('suppliers', fn (Blueprint $t) => $t->dropColumn('weekly_hours'));
    }
};
