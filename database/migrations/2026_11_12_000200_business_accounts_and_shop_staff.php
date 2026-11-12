<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Personal vs Business rental partners, and staff accounts for businesses.
 *   suppliers.account_type   personal (one-to-one, the original setup) | business
 *   suppliers.biz_*          registration details + uploaded permit (private disk)
 *   suppliers.biz_status     null (personal) | pending | verified | rejected — admin checks the documents
 *   shop_staff               users who work for a business: manager | cashier | warehouse | coordinator
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('account_type', 10)->default('personal');
            $table->string('biz_reg_type', 8)->nullable();
            $table->string('biz_reg_no', 60)->nullable();
            $table->string('biz_tin', 30)->nullable();
            $table->string('biz_doc_path')->nullable();
            $table->string('biz_status', 10)->nullable();
            $table->string('biz_reject_reason')->nullable();
            $table->timestamp('biz_verified_at')->nullable();
        });
        Schema::create('shop_staff', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('role', 12);
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_staff');
        Schema::table('suppliers', fn (Blueprint $t) => $t->dropColumn(['account_type', 'biz_reg_type', 'biz_reg_no', 'biz_tin', 'biz_doc_path', 'biz_status', 'biz_reject_reason', 'biz_verified_at']));
    }
};
