<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Teams (2026-11-22): how many events an event company can run at the same time comes from its teams, not a typed
 * number. A team works one event part at a time, holding setup + event + teardown hours, so one team can do a
 * morning and an evening event on the same day. Each part of a confirmed booking gets a team.
 * A business without teams keeps its "events a day" setting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_teams', function (Blueprint $t) {
            $t->id();
            $t->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $t->string('name', 60);
            $t->json('members')->nullable(); // user ids from Team & Roles
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::table('event_booking_parts', function (Blueprint $t) {
            $t->foreignId('event_team_id')->nullable()->after('venue')->constrained('event_teams')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('event_booking_parts', function (Blueprint $t) {
            $t->dropConstrainedForeignId('event_team_id');
        });
        Schema::dropIfExists('event_teams');
    }
};
