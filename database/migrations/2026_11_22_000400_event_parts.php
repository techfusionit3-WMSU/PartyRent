<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Event parts (2026-11-22): one booking can cover several dates or stages, for any kind of event (a wedding's prenup
 * shoot, ceremony and reception; a 3-day fiesta; a wake's nights; a conference's setup day and two days).
 *   event_packages.parts   what the package covers: [{name, optional, price}] (an optional part can cost extra)
 *   event_booking_parts    each part of a booking with its own date, time and venue; marked done one by one.
 * The booking's event_date stays the FIRST part's date (payments are due before it). Existing bookings get one part.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_packages', function (Blueprint $t) {
            $t->json('parts')->nullable()->after('inclusions');
        });
        Schema::create('event_booking_parts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('event_booking_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('sort')->default(0);
            $t->string('name', 60);
            $t->date('date');
            $t->string('start_time', 5)->nullable();
            $t->string('end_time', 5)->nullable();
            $t->string('venue', 255)->nullable(); // empty = the booking's venue
            $t->decimal('price', 12, 2)->default(0); // extra for an optional part chosen from the package
            $t->timestamp('done_at')->nullable();
            $t->timestamps();
            $t->index('date');
        });

        foreach (\App\Models\EventPackage::all() as $k) {
            $k->update(['parts' => \App\Domain\EventParts::defaultFor($k->events[0] ?? null)]);
        }
        foreach (DB::table('event_bookings')->get() as $b) {
            DB::table('event_booking_parts')->insert(['event_booking_id' => $b->id, 'sort' => 0, 'name' => 'Event', 'date' => $b->event_date,
                'start_time' => $b->start_time, 'end_time' => $b->end_time, 'venue' => null, 'price' => 0,
                'done_at' => $b->status === 'completed' ? ($b->completed_at ?? now()) : null, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('event_booking_parts');
        Schema::table('event_packages', function (Blueprint $t) {
            $t->dropColumn('parts');
        });
    }
};
