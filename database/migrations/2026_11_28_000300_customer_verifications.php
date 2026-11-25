<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer verification before renting (App\Models\CustomerVerification), one row per customer:
 *   government ID (type, last 4 of the ID number, front/back photos)      required
 *   payment account (provider, account name, last 4, proof screenshot)      required, never passwords/OTPs
 *   proof of insurance                                                       optional
 * Files live on the private disk (storage/app/private/verifications), served only to the customer and to admins.
 * status: not_submitted | pending | approved | rejected | resubmit; reason = why it was rejected / must be redone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_verifications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $t->string('status', 16)->default('not_submitted');
            $t->string('id_type', 40)->nullable();
            $t->string('id_last4', 4)->nullable();
            $t->string('id_front_path')->nullable();
            $t->string('id_back_path')->nullable();
            $t->string('pay_provider', 40)->nullable();
            $t->string('pay_account_name', 120)->nullable();
            $t->string('pay_last4', 4)->nullable();
            $t->string('pay_proof_path')->nullable();
            $t->timestamp('id_verified_at')->nullable();
            $t->timestamp('pay_verified_at')->nullable();
            $t->string('insurance_path')->nullable();
            $t->string('insurance_status', 12)->default('none'); // none | submitted | verified | rejected
            $t->string('insurer', 80)->nullable();
            $t->date('insurance_expires')->nullable();
            $t->string('reason', 500)->nullable();
            $t->json('history')->nullable();
            $t->timestamp('submitted_at')->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_verifications');
    }
};
