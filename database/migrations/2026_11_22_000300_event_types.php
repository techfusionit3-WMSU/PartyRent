<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What an event company can handle (user 2026-11-22). Generic for every kind of event:
 *   event_types           the catalog PartyRent manages (any event: wedding, wake, seminar, fiesta, concert…), with a
 *                         typical guest range, a parts template (stages / days), usual services and the proof it needs.
 *                         A business may ask for a type that is not listed (status "requested").
 *   supplier_event_types  the types one business handles: what it claims (guests, events a day, past events, proof)
 *                         and what PartyRent verified. Packages and inquiries only work for verified types, within
 *                         the verified limits. A raise above the verified limits waits for review.
 * Existing event companies keep working: the types their packages already offer are verified as they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_types', function (Blueprint $t) {
            $t->id();
            $t->string('name', 60)->unique();
            $t->string('description', 500)->nullable();
            $t->unsignedInteger('min_guests')->nullable();
            $t->unsignedInteger('max_guests')->nullable();
            $t->json('parts')->nullable();     // [{name, optional, offset}] usual stages / days (event parts)
            $t->json('services')->nullable();  // usual services, e.g. Catering, Styling
            $t->json('proofs')->nullable();    // documents a business must show, e.g. Food handling permit
            $t->string('status', 10)->default('active'); // active | requested | hidden
            $t->foreignId('requested_by')->nullable()->constrained('suppliers')->nullOnDelete();
            $t->string('request_note', 500)->nullable();
            $t->unsignedSmallInteger('sort')->default(100);
            $t->timestamps();
        });

        Schema::create('supplier_event_types', function (Blueprint $t) {
            $t->id();
            $t->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $t->foreignId('event_type_id')->constrained()->cascadeOnDelete();
            $t->string('status', 10)->default('pending'); // pending | verified | rejected | paused
            // what the business says it can do
            $t->unsignedInteger('claimed_max_guests');
            $t->unsignedSmallInteger('claimed_per_day')->default(1);
            $t->unsignedInteger('past_events')->default(0);
            $t->json('photos')->nullable(); // past events of this type (private)
            $t->json('docs')->nullable();   // proof label => private file
            $t->string('note', 1000)->nullable();
            // what PartyRent verified (these are the limits that apply)
            $t->unsignedInteger('max_guests')->nullable();
            $t->unsignedSmallInteger('per_day')->nullable();
            $t->boolean('raise_pending')->default(false); // verified, asked for more: old limits apply until reviewed
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('reviewed_at')->nullable();
            $t->string('reason', 500)->nullable();
            $t->timestamps();
            $t->unique(['supplier_id', 'event_type_id']);
        });

        \App\Domain\EventTypes::seedCatalog();
        foreach (\App\Models\Supplier::where('type', 'organization')->get() as $s) {
            \App\Domain\EventTypes::grandfather($s);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_event_types');
        Schema::dropIfExists('event_types');
    }
};
