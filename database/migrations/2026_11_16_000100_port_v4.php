<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Port of the reference prototype v4 data model (reference/partyrent/js/core/seed.js "v4" blocks).
 *
 * suppliers           type individual|organization, company, application (wizard answers + documents),
 *                     revision_note, payment_plans {standard, full, installment}, time_settings
 *                     {open, close, prepHours, recoveryHours}, delivery_zones [{id, name, fee}],
 *                     services [{id, name, description, amount, per: order|location, required}],
 *                     damage_policy {repairable, nonRepairable, lost, categories}
 * shop_staff.role     org_admin | cashier | inventory | staff
 * products            status active|hidden|inactive|deleted (+ hidden_reason/by/at), written_off,
 *                     max_hours, time_from/time_to (per-product time frame), allow_overnight
 * packages            status (replaces active/is_draft for moderation), hidden_*, min_hours, rental_events
 * inventory_locations, location_stock (product total = sum), stock_transfers; stock_movements.location_id
 * rental_requests     order_group (one checkout → one request per supplier), plan, installment_plan,
 *                     destinations [{id, label, address, zoneId, deliveryTime, contact…}], services [ids]
 * booking_days        one row per product per day per request line (packages expanded): used for
 *                     locked availability queries. Rebuilt from `lines` by App\Domain\BookingDays.
 * audit_logs.kind     activity (admin Activity Log) | audit (superadmin Audit Logs)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('type', 12)->default('individual');
            $table->json('company')->nullable();
            $table->json('application')->nullable();
            $table->string('revision_note', 500)->nullable();
            $table->json('payment_plans')->nullable();
            $table->json('time_settings')->nullable();
            $table->json('delivery_zones')->nullable();
            $table->json('services')->nullable();
            $table->json('damage_policy')->nullable();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('written_off')->default(0);
            $table->decimal('max_hours', 4, 1)->nullable();
            $table->string('time_from', 5)->nullable();
            $table->string('time_to', 5)->nullable();
            $table->boolean('allow_overnight')->default(false);
            $table->string('hidden_reason', 500)->nullable();
            $table->foreignId('hidden_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('hidden_at')->nullable();
        });

        Schema::table('packages', function (Blueprint $table) {
            $table->string('status', 10)->default('active');
            $table->string('hidden_reason', 500)->nullable();
            $table->foreignId('hidden_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('hidden_at')->nullable();
            $table->decimal('min_hours', 4, 1)->nullable();
            $table->json('rental_events')->nullable();
        });

        Schema::create('inventory_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('address')->default('');
            $table->string('contact')->default('');
            $table->string('phone', 40)->default('');
            $table->timestamps();
        });

        Schema::create('location_stock', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_location_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('qty')->default(0);
            $table->unique(['inventory_location_id', 'product_id']);
        });

        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_location_id')->constrained('inventory_locations')->cascadeOnDelete();
            $table->foreignId('to_location_id')->constrained('inventory_locations')->cascadeOnDelete();
            $table->unsignedInteger('qty');
            $table->string('note')->default('');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->nullable();
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('inventory_location_id')->nullable()->constrained()->nullOnDelete();
        });

        Schema::table('rental_requests', function (Blueprint $table) {
            $table->string('order_group', 20)->nullable()->index();
            $table->string('plan', 12)->nullable();
            $table->json('installment_plan')->nullable();
            $table->json('destinations')->nullable();
            $table->json('services')->nullable();
        });

        Schema::create('booking_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rental_request_id')->constrained()->cascadeOnDelete();
            $table->string('line_id', 20);
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->unsignedInteger('qty');
            $table->string('time_from', 5)->nullable();
            $table->string('time_to', 5)->nullable();
            $table->foreignId('inventory_location_id')->nullable()->constrained()->nullOnDelete();
            $table->string('dest_id', 20)->nullable();
            $table->index(['product_id', 'day']);
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('kind', 10)->default('audit');
        });

        $this->convert();
    }

    /** Existing rows → v4 vocabulary. */
    private function convert(): void
    {
        DB::table('suppliers')->where('account_type', 'business')->update(['type' => 'organization']);
        foreach (['manager' => 'org_admin', 'warehouse' => 'inventory', 'coordinator' => 'staff'] as $old => $new) {
            DB::table('shop_staff')->where('role', $old)->update(['role' => $new]);
        }
        DB::table('products')->where('status', 'published')->update(['status' => 'active']);
        DB::table('products')->whereIn('status', ['draft', 'archived'])->update(['status' => 'inactive']);
        DB::table('packages')->where('active', false)->update(['status' => 'inactive']);
        DB::table('packages')->where('is_draft', true)->update(['status' => 'inactive']);
        foreach (DB::table('rental_requests')->get(['id', 'arrangement']) as $r) {
            $plan = ['full' => 'full', 'installment' => 'installment'][$r->arrangement] ?? 'standard';
            DB::table('rental_requests')->where('id', $r->id)->update(['plan' => $plan]);
        }
    }

    public function down(): void
    {
        DB::table('products')->where('status', 'active')->update(['status' => 'published']);
        DB::table('products')->whereIn('status', ['inactive', 'deleted'])->update(['status' => 'archived']);
        foreach (['org_admin' => 'manager', 'inventory' => 'warehouse', 'staff' => 'coordinator'] as $old => $new) {
            DB::table('shop_staff')->where('role', $old)->update(['role' => $new]);
        }
        Schema::table('audit_logs', fn (Blueprint $t) => $t->dropColumn('kind'));
        Schema::dropIfExists('booking_days');
        Schema::table('rental_requests', fn (Blueprint $t) => $t->dropIndex(['order_group']));
        Schema::table('rental_requests', fn (Blueprint $t) => $t->dropColumn(['order_group', 'plan', 'installment_plan', 'destinations', 'services']));
        Schema::table('stock_movements', function (Blueprint $t) {
            $t->dropConstrainedForeignId('inventory_location_id');
        });
        Schema::dropIfExists('stock_transfers');
        Schema::dropIfExists('location_stock');
        Schema::dropIfExists('inventory_locations');
        Schema::table('packages', function (Blueprint $t) {
            $t->dropConstrainedForeignId('hidden_by');
            $t->dropColumn(['status', 'hidden_reason', 'hidden_at', 'min_hours', 'rental_events']);
        });
        Schema::table('products', function (Blueprint $t) {
            $t->dropConstrainedForeignId('hidden_by');
            $t->dropColumn(['written_off', 'max_hours', 'time_from', 'time_to', 'allow_overnight', 'hidden_reason', 'hidden_at']);
        });
        Schema::table('suppliers', fn (Blueprint $t) => $t->dropColumn(['type', 'company', 'application', 'revision_note', 'payment_plans', 'time_settings', 'delivery_zones', 'services', 'damage_policy']));
    }
};
