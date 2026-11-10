<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PartyRent's collection accounts (GCash, Maya, bank…) as a list with on/off switches and optional
 * monthly caps (GCash receiving limits). Payments record which account the customer paid into.
 * Replaces the single payGcash* / payBank* / payQrPath settings.
 */
return new class extends Migration
{
    private const OLD_KEYS = ['payGcashName', 'payGcashNumber', 'payBankName', 'payBankAccountName', 'payBankAccountNo', 'payQrPath'];

    public function up(): void
    {
        Schema::create('pay_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('type', 10); // gcash | maya | bank | other
            $table->string('label', 60); // shown to customers: "GCash", "BDO", "BPI QR Ph"
            $table->string('account_name', 80)->default('');
            $table->string('account_no', 40)->default('');
            $table->string('qr_path')->nullable();
            $table->decimal('monthly_cap', 12, 2)->nullable(); // hide once this month's receipts would pass it
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('pay_account_id')->nullable()->after('method')->constrained('pay_accounts')->nullOnDelete();
        });

        // carry over the accounts set up in System settings
        $old = DB::table('settings')->whereIn('key', self::OLD_KEYS)->pluck('value', 'key')->map(fn ($v) => (string) json_decode((string) $v, true));
        $qr = $old['payQrPath'] ?? '';
        $now = now();
        if (($old['payGcashNumber'] ?? '') !== '') {
            DB::table('pay_accounts')->insert(['type' => 'gcash', 'label' => 'GCash', 'account_name' => $old['payGcashName'] ?? '', 'account_no' => $old['payGcashNumber'],
                'qr_path' => $qr ?: null, 'sort' => 1, 'created_at' => $now, 'updated_at' => $now]);
            $qr = '';
        }
        if (($old['payBankAccountNo'] ?? '') !== '') {
            DB::table('pay_accounts')->insert(['type' => 'bank', 'label' => ($old['payBankName'] ?? '') ?: 'Bank', 'account_name' => $old['payBankAccountName'] ?? '',
                'account_no' => $old['payBankAccountNo'], 'qr_path' => $qr ?: null, 'sort' => 2, 'created_at' => $now, 'updated_at' => $now]);
            $qr = '';
        }
        if ($qr !== '') {
            DB::table('pay_accounts')->insert(['type' => 'other', 'label' => 'QR Ph', 'qr_path' => $qr, 'sort' => 3, 'created_at' => $now, 'updated_at' => $now]);
        }
        DB::table('settings')->whereIn('key', self::OLD_KEYS)->delete();
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pay_account_id');
        });
        Schema::dropIfExists('pay_accounts');
    }
};
