<?php

use App\Models\Payment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Duplicate reference check (normalized reference_key) and online payments through PayMongo.
 * Online payments start as status "awaiting" until PayMongo reports them paid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('reference_key', 80)->nullable()->after('reference')->index();
            $table->string('gateway', 20)->nullable()->after('recorded_by'); // paymongo
            $table->string('gateway_ref', 80)->nullable()->after('gateway')->index(); // checkout session id (cs_…)
            $table->text('gateway_url')->nullable()->after('gateway_ref'); // hosted checkout page
            $table->decimal('gateway_fee', 12, 2)->nullable()->after('gateway_url');
        });

        DB::table('payments')->where('reference', '!=', '')->orderBy('id')->each(function ($p) {
            DB::table('payments')->where('id', $p->id)->update(['reference_key' => Payment::refKey($p->reference)]);
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['reference_key']);
            $table->dropIndex(['gateway_ref']);
            $table->dropColumn(['reference_key', 'gateway', 'gateway_ref', 'gateway_url', 'gateway_fee']);
        });
    }
};
