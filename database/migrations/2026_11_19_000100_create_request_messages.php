<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer <-> shop chat on a rental request (works at any time, no problem case needed).
 * rental_requests.chat_*_seen_at = when each side last opened the conversation (unread counts).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('request_messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('rental_request_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('side', 10); // customer | supplier
            $t->text('body');
            $t->timestamp('created_at')->nullable();
            $t->index(['rental_request_id', 'id']);
        });
        Schema::table('rental_requests', function (Blueprint $t) {
            $t->timestamp('chat_customer_seen_at')->nullable();
            $t->timestamp('chat_supplier_seen_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('rental_requests', fn (Blueprint $t) => $t->dropColumn(['chat_customer_seen_at', 'chat_supplier_seen_at']));
        Schema::dropIfExists('request_messages');
    }
};
