<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Employees join a shop by invitation: the owner invites an email, the person confirms from that inbox
 * (an existing account accepts while signed in; a new email enters the emailed code and sets their own password).
 *
 * shop_staff.user_id is no longer unique: someone deactivated at one shop may join another (one ACTIVE
 * membership at a time is enforced in ShopStaffs), and the old shop keeps them in its list as deactivated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_invites', function (Blueprint $t) {
            $t->id();
            $t->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $t->string('email')->index();
            $t->string('role', 12);
            $t->string('token_hash', 64)->unique();
            $t->string('code_hash', 64);
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->timestamp('expires_at')->nullable();
            $t->timestamp('last_sent_at')->nullable();
            $t->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::table('shop_staff', function (Blueprint $t) {
            $t->unique(['supplier_id', 'user_id']);
            $t->index('user_id'); // keeps the user_id foreign key indexed once the unique index is gone
        });
        Schema::table('shop_staff', function (Blueprint $t) {
            $t->dropUnique(['user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_invites');
        Schema::table('shop_staff', function (Blueprint $t) {
            $t->unique('user_id');
        });
        Schema::table('shop_staff', function (Blueprint $t) {
            $t->dropUnique(['supplier_id', 'user_id']);
            $t->dropIndex(['user_id']);
        });
    }
};
