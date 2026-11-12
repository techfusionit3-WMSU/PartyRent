<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Event packages (weddings, debuts):
 *   packages.stages      [{name, hint}] e.g. Preparation, Ceremony, Reception; components point at a stage by index
 *                        and customers give each stage a time and venue (line sel.stages)
 *   packages.tier_group  packages with the same group are tiers of one offer (e.g. "Wedding")
 *   packages.tier_label  e.g. Silver / Gold / Platinum
 * Service components may set teamsPerDay: bookings past that many teams on a date are refused.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->json('stages')->nullable();
            $table->string('tier_group', 60)->nullable();
            $table->string('tier_label', 30)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('packages', fn (Blueprint $t) => $t->dropColumn(['stages', 'tier_group', 'tier_label']));
    }
};
