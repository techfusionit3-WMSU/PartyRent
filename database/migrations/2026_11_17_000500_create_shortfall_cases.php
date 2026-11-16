<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Change list C12: shortfall / dispute cases. A supplier's shortfall report (item, qty, dates, reason, photo)
 * opens one case per affected booking. case_events = timeline + message thread + proposals + audit log.
 * status: reported → proposed → countered → agreed → resolved (escalated when a proposal deadline passes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shortfall_cases', function (Blueprint $t) {
            $t->id();
            $t->string('code', 20)->unique();
            $t->string('report', 20)->index(); // one report can open several cases (SR-…)
            $t->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $t->foreignId('rental_request_id')->constrained()->cascadeOnDelete();
            $t->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->foreignId('hold_id')->nullable()->constrained('holds')->nullOnDelete();
            $t->json('short'); // {date: units short}
            $t->text('reason');
            $t->string('photo')->nullable();
            $t->string('status', 12)->default('reported');
            $t->unsignedBigInteger('proposal_id')->nullable(); // open proposal (case_events.id)
            $t->timestamp('deadline_at')->nullable();
            $t->json('resolution')->nullable();
            $t->timestamp('resolved_at')->nullable();
            $t->timestamps();
        });
        Schema::create('case_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('shortfall_case_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('role', 10); // supplier | customer | admin | system
            $t->string('kind', 12); // report | proposal | accept | counter | decline | message | escalate | apply | resolve
            $t->text('body')->nullable();
            $t->json('data')->nullable();
            $t->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('case_events');
        Schema::dropIfExists('shortfall_cases');
    }
};
