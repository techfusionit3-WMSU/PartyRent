<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A shop can keep several payout accounts (App\Models\PayoutAccount): e-wallets (GCash, Maya, other), banks
 * (BPI, BDO, other) and, once a card-payout provider is connected, debit cards by provider token only.
 *   account_number  encrypted (Laravel encrypter); last4 kept apart for masked display
 *   details         provider-specific extras (bank account type, branch; card brand / expiry / token ref)
 * The default account is mirrored into suppliers.payout_* so payouts and older screens keep working.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payout_accounts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $t->string('category', 10);            // ewallet | bank | card
            $t->string('provider', 20);            // gcash | maya | other_ewallet | bpi | bdo | other_bank | card
            $t->string('provider_name', 60);       // shown name: GCash, Maya, Metrobank, …
            $t->string('account_name', 120);
            $t->text('account_number')->nullable(); // encrypted; never a card number (cards: null)
            $t->string('last4', 4)->nullable();
            $t->json('details')->nullable();
            $t->boolean('is_default')->default(false);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['supplier_id', 'is_default']);
        });

        // existing accounts become the shop's first (default) payout account
        foreach (DB::table('suppliers')->whereNotNull('payout_method')->whereNotNull('payout_account_no')->get() as $s) {
            $isWallet = $s->payout_method !== 'bank';
            $bank = trim((string) $s->payout_bank);
            $provider = $isWallet ? ($s->payout_method === 'maya' ? 'maya' : 'gcash') : (in_array(strtoupper($bank), ['BPI', 'BDO'], true) ? strtolower($bank) : 'other_bank');
            $digits = preg_replace('/\D/', '', (string) $s->payout_account_no);
            DB::table('payout_accounts')->insert([
                'supplier_id' => $s->id, 'category' => $isWallet ? 'ewallet' : 'bank', 'provider' => $provider,
                'provider_name' => $isWallet ? ($provider === 'maya' ? 'Maya' : 'GCash') : ($bank ?: 'Bank'),
                'account_name' => (string) $s->payout_account_name, 'account_number' => encrypt($digits, false), 'last4' => substr($digits, -4) ?: null,
                'details' => json_encode($isWallet ? [] : ['accountType' => 'savings']), 'is_default' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payout_accounts');
    }
};
