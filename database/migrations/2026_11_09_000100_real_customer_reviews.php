<?php

use App\Domain\Reviews;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Real-customer reviews:
 *  - one review per rented item/package (not one per rental),
 *  - photos/videos, a single supplier reply, admin hiding,
 *  - customer reports, and review counts on products/suppliers.
 * Ratings are then recalculated from actual reviews only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $t) {
            $t->foreignId('package_id')->nullable()->after('product_id')->constrained()->nullOnDelete();
            $t->json('media')->nullable()->after('comment');
            $t->text('reply')->nullable()->after('media');
            $t->timestamp('replied_at')->nullable()->after('reply');
            $t->timestamp('hidden_at')->nullable()->after('replied_at');
            $t->string('hidden_reason', 300)->nullable()->after('hidden_at');
        });
        // Add the per-item unique first (it starts with rental_request_id, so MySQL keeps an index for the FK), then drop the old one.
        Schema::table('reviews', function (Blueprint $t) {
            $t->unique(['rental_request_id', 'product_id', 'package_id'], 'reviews_rental_item_unique');
        });
        Schema::table('reviews', function (Blueprint $t) {
            $t->dropUnique(['rental_request_id']);
        });

        Schema::create('review_reports', function (Blueprint $t) {
            $t->id();
            $t->foreignId('review_id')->constrained()->cascadeOnDelete();
            $t->foreignId('reporter_id')->constrained('users')->cascadeOnDelete();
            $t->string('reason', 30);
            $t->string('details', 500)->nullable();
            $t->string('status', 20)->default('open'); // open | dismissed | actioned
            $t->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('resolved_at')->nullable();
            $t->timestamps();
            $t->unique(['review_id', 'reporter_id']);
        });

        Schema::table('products', fn (Blueprint $t) => $t->unsignedInteger('review_count')->default(0)->after('rating'));
        Schema::table('suppliers', fn (Blueprint $t) => $t->unsignedInteger('review_count')->default(0)->after('rating'));

        // Replace typed-in demo ratings with ones computed from real reviews.
        Reviews::recalcAll();
    }

    public function down(): void
    {
        Schema::table('suppliers', fn (Blueprint $t) => $t->dropColumn('review_count'));
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn('review_count'));
        Schema::dropIfExists('review_reports');
        Schema::table('reviews', function (Blueprint $t) {
            $t->unique(['rental_request_id']);
        });
        Schema::table('reviews', function (Blueprint $t) {
            $t->dropUnique('reviews_rental_item_unique');
            $t->dropConstrainedForeignId('package_id');
            $t->dropColumn(['media', 'reply', 'replied_at', 'hidden_at', 'hidden_reason']);
        });
    }
};
