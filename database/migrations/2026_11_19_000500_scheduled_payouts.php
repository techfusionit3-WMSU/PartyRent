<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Scheduled payouts (every 15 days or once a month, the shop chooses).
 *
 *   payouts            one row per completed rental = the shop's EARNINGS from it (unchanged meaning), plus
 *                      earned (before cash) / cash_kept (cash the shop already collected) / net (= earned − cash_kept)
 *                      and the payout_batch_id it was paid in. status: pending (waiting for the next payout) |
 *                      scheduled (in a payout not sent yet) | paid
 *   payout_batches     what PartyRent actually sends: all earnings of one period minus the cash the shop kept,
 *                      plus any balance carried from before. status: pending | sending | paid | carried (nothing
 *                      to send; a negative amount is taken from the next payout) | settled (the shop paid it)
 *   suppliers.payout_schedule   half_month (1st–15th, 16th–end) | monthly
 *   payments.disputed_at        the customer said they never paid this cash
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payout_batches', function (Blueprint $t) {
            $t->id();
            $t->string('code', 16)->unique();
            $t->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $t->string('schedule', 12);
            $t->date('period_start');
            $t->date('period_end');
            $t->decimal('earned', 12, 2)->default(0);     // earnings before cash, after commission
            $t->decimal('commission', 12, 2)->default(0);
            $t->decimal('cash_kept', 12, 2)->default(0);  // cash the shop already holds
            $t->decimal('carried_in', 12, 2)->default(0); // balance from earlier periods (negative = shop owed PartyRent)
            $t->decimal('net', 12, 2);                    // what PartyRent sends (≤ 0: nothing, carried)
            $t->string('status', 10)->default('pending');
            $t->string('method', 10)->nullable();
            $t->string('reference', 80)->nullable();
            $t->string('notes')->nullable();
            $t->string('gateway', 12)->nullable();
            $t->string('gateway_ref', 80)->nullable();
            $t->string('failure', 255)->nullable();
            $t->timestamp('paid_at')->nullable();
            $t->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $t->unsignedBigInteger('carried_to_id')->nullable();
            $t->timestamps();
            $t->index(['supplier_id', 'status']);
        });

        Schema::table('payouts', function (Blueprint $t) {
            $t->decimal('earned', 12, 2)->default(0)->after('compensation');
            $t->decimal('cash_kept', 12, 2)->default(0)->after('earned');
            $t->foreignId('payout_batch_id')->nullable()->after('rental_request_id')->constrained('payout_batches')->nullOnDelete();
        });
        Schema::table('suppliers', fn (Blueprint $t) => $t->string('payout_schedule', 12)->default('half_month'));
        Schema::table('payments', fn (Blueprint $t) => $t->timestamp('disputed_at')->nullable());

        // Existing payouts: each sent (or sending) one becomes its own one-rental payout; unsent ones wait for the next payout.
        $seq = 0;
        foreach (DB::table('payouts')->orderBy('id')->get() as $p) {
            $upd = ['earned' => $p->net];
            if (in_array($p->status, ['paid', 'sending'], true)) {
                $day = substr((string) $p->created_at, 0, 10) ?: date('Y-m-d');
                $upd['payout_batch_id'] = DB::table('payout_batches')->insertGetId([
                    'code' => 'PB-'.(10001 + $seq++), 'supplier_id' => $p->supplier_id, 'schedule' => 'half_month', 'period_start' => $day, 'period_end' => $day,
                    'earned' => $p->net, 'commission' => $p->commission, 'net' => $p->net, 'status' => $p->status,
                    'method' => $p->method, 'reference' => $p->reference, 'notes' => $p->notes, 'gateway' => $p->gateway ?? null, 'gateway_ref' => $p->gateway_ref ?? null,
                    'paid_at' => $p->paid_at, 'paid_by' => $p->paid_by, 'created_at' => $p->created_at, 'updated_at' => $p->updated_at,
                ]);
                $upd['status'] = $p->status === 'paid' ? 'paid' : 'scheduled';
            }
            DB::table('payouts')->where('id', $p->id)->update($upd);
        }
    }

    public function down(): void
    {
        Schema::table('payments', fn (Blueprint $t) => $t->dropColumn('disputed_at'));
        Schema::table('suppliers', fn (Blueprint $t) => $t->dropColumn('payout_schedule'));
        Schema::table('payouts', function (Blueprint $t) {
            $t->dropConstrainedForeignId('payout_batch_id');
            $t->dropColumn(['earned', 'cash_kept']);
        });
        Schema::dropIfExists('payout_batches');
    }
};
