<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-branch, part 2:
 * - service area per branch (km from the branch; null = the shop's default) and the shop's branch settings
 *   (default service distance, combining nearby branches and how far apart they may be)
 * - where a customer is renting near (saved for signed-in customers; guests keep it in the session)
 * - staff limited to branches (null = organization level: every branch)
 * - stock moved between branches for one rental (combined rentals)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_locations', function (Blueprint $t) {
            $t->decimal('service_km', 7, 1)->nullable()->after('lng');
            $t->string('hours')->nullable()->after('phone');
        });
        Schema::table('suppliers', function (Blueprint $t) {
            $t->json('branch_settings')->nullable();
        });
        Schema::table('users', function (Blueprint $t) {
            $t->json('near')->nullable();
        });
        Schema::table('shop_staff', function (Blueprint $t) {
            $t->json('branch_ids')->nullable()->after('roles');
        });
        Schema::table('staff_invites', function (Blueprint $t) {
            $t->json('branch_ids')->nullable()->after('roles');
        });
        Schema::create('rental_transfers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('rental_request_id')->constrained()->cascadeOnDelete();
            $t->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->foreignId('from_location_id')->constrained('inventory_locations')->cascadeOnDelete();
            $t->foreignId('to_location_id')->constrained('inventory_locations')->cascadeOnDelete();
            $t->unsignedInteger('qty');
            // planned → sent → received → returned (sent back) | kept (stays at the receiving branch); cancelled
            $t->string('status', 20)->default('planned');
            $t->date('due_on')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('received_at')->nullable();
            $t->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('closed_at')->nullable();
            $t->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('note')->nullable();
            $t->timestamps();
            $t->index(['supplier_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_transfers');
        Schema::table('staff_invites', fn (Blueprint $t) => $t->dropColumn('branch_ids'));
        Schema::table('shop_staff', fn (Blueprint $t) => $t->dropColumn('branch_ids'));
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('near'));
        Schema::table('suppliers', fn (Blueprint $t) => $t->dropColumn('branch_settings'));
        Schema::table('inventory_locations', fn (Blueprint $t) => $t->dropColumn(['service_km', 'hours']));
    }
};
