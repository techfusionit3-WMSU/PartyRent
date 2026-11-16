<?php

namespace Tests\Feature;

use App\Domain\Reviews;
use App\Models\Product;
use App\Models\RentalRequest;
use App\Models\Review;
use App\Models\ReviewReport;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Reviews come only from customers with a completed rental; ratings are computed from them. */
class ReviewsTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = \Database\Seeders\DemoSeeder::class;

    private function as(User $u, ?string $mode = null): static
    {
        return $this->actingAs($u)->withSession(['mode' => $mode ?? $u->defaultMode()]);
    }

    /** A completed rental with at least one item line and no reviews yet. */
    private function completedRental(): RentalRequest
    {
        $r = RentalRequest::whereDoesntHave('reviews')->get()->first(fn ($x) => collect($x->lines)->contains('type', 'item'));
        $r->update(['status' => 'completed']);

        return $r->fresh();
    }

    /** Real 1x1 PNG bytes (the GD extension is not installed, so fake()->image() is unavailable). */
    private function png(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII="));
    }

    private function itemKey(RentalRequest $r): string
    {
        return collect(Reviews::reviewables($r))->firstWhere('type', 'item')['key'];
    }


    public function test_only_the_customer_of_a_completed_rental_can_review(): void
    {
        $pending = RentalRequest::where('status', '!=', 'completed')->first();
        $key = array_key_first(Reviews::reviewables($pending)) ?? 'item-1';
        $this->as($pending->customer, 'customer')->post("/rentals/{$pending->code}/review", ['reviews' => [$key => ['rating' => 5, 'comment' => 'Trying to review early']]])->assertStatus(422);

        $r = $this->completedRental();
        $stranger = User::whereJsonContains('roles', 'customer')->where('id', '!=', $r->customer_id)->first();
        $this->as($stranger, 'customer')->post("/rentals/{$r->code}/review", ['reviews' => [$this->itemKey($r) => ['rating' => 1, 'comment' => 'I never rented this']]])->assertNotFound();
        $this->assertSame(0, Review::where('rental_request_id', $r->id)->count());
    }

    public function test_customer_reviews_each_item_with_photos_and_video(): void
    {
        Storage::fake('public');
        $r = $this->completedRental();
        $key = $this->itemKey($r);
        $pid = (int) substr($key, 5);
        $this->as($r->customer, 'customer')->get("/rentals/{$r->code}")->assertOk()->assertSee('Rate your rental')->assertSee('data-review-form', false);

        $this->as($r->customer, 'customer')->post("/rentals/{$r->code}/review", ['reviews' => [$key => [
            'rating' => 2, 'comment' => 'Two chairs were wobbly and one was dirty.',
            'photos' => [$this->png('a.png'), $this->png('b.png')],
            'video' => UploadedFile::fake()->create('clip.mp4', 2048, 'video/mp4'),
        ]]])->assertSessionHas('success');

        $rv = Review::where('rental_request_id', $r->id)->where('product_id', $pid)->firstOrFail();
        $this->assertSame(2, (int) $rv->rating);
        $this->assertCount(3, $rv->media);
        $this->assertSame(['image', 'image', 'video'], array_column($rv->media, 'type'));
        foreach ($rv->media as $m) {
            Storage::disk('public')->assertExists($m['path']);
        }
        // ratings recomputed from real reviews
        $p = Product::find($pid);
        $this->assertEqualsWithDelta(round(Review::visible()->where('product_id', $pid)->avg('rating'), 2), $p->rating, 0.001);
        $this->assertSame(Review::visible()->where('product_id', $pid)->count(), $p->review_count);

        // shown on the item page with the verified badge, media and filter
        $this->get("/products/{$pid}")->assertOk()->assertSee('Verified rental')->assertSee('Two chairs were wobbly')->assertSee('data-media-type="video"', false)->assertSee('With photos/videos');
        $this->get("/products/{$pid}?rf=media")->assertOk()->assertSee('Two chairs were wobbly');
        $this->get("/products/{$pid}?rf=5")->assertOk()->assertDontSee('Two chairs were wobbly');
        $this->get("/suppliers/{$r->supplier_id}?tab=reviews")->assertOk()->assertSee('Two chairs were wobbly');

        // the same item can't be reviewed twice
        $this->as($r->customer, 'customer')->post("/rentals/{$r->code}/review", ['reviews' => [$key => ['rating' => 5, 'comment' => 'Changing my mind completely']]])->assertSessionHasErrors();
        $this->assertSame(1, Review::where('rental_request_id', $r->id)->where('product_id', $pid)->count());
    }

    public function test_review_validation(): void
    {
        Storage::fake('public');
        $r = $this->completedRental();
        $key = $this->itemKey($r);
        $post = fn (array $data) => $this->as($r->customer, 'customer')->post("/rentals/{$r->code}/review", ['reviews' => [$key => $data]]);

        $post(['rating' => 4, 'comment' => 'short'])->assertSessionHasErrors("reviews.$key.comment");
        $post(['comment' => 'A long enough comment but no stars'])->assertSessionHasErrors("reviews.$key.rating");
        $post([])->assertSessionHasErrors('reviews');
        $post(['rating' => 4, 'comment' => 'Too many photos attached here', 'photos' => array_map(fn ($i) => $this->png("p$i.png"), range(1, 6))])->assertSessionHasErrors("reviews.$key.photos");
        $post(['rating' => 4, 'comment' => 'Wrong kind of file attached', 'photos' => [UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf')]])->assertSessionHasErrors("reviews.$key.photos.0");
        $post(['rating' => 4, 'comment' => 'Video is far too large here', 'video' => UploadedFile::fake()->create('big.mp4', 30000, 'video/mp4')])->assertSessionHasErrors("reviews.$key.video");
        $post(['rating' => 6, 'comment' => 'Six stars is not a thing'])->assertSessionHasErrors("reviews.$key.rating");
        $this->assertSame(0, Review::where('rental_request_id', $r->id)->count());
    }

    public function test_supplier_replies_once_and_others_cannot(): void
    {
        $rv = $this->rentalReview();
        $owner = $rv->supplier->owner;
        $this->as($owner, 'supplier')->get('/supplier/reviews')->assertOk()->assertSee($rv->comment);
        $this->as($owner, 'supplier')->post("/supplier/reviews/{$rv->id}/reply", ['reply' => 'Thank you so much for renting with us!'])->assertSessionHas('success');
        $this->assertSame('Thank you so much for renting with us!', $rv->fresh()->reply);
        $this->as($owner, 'supplier')->post("/supplier/reviews/{$rv->id}/reply", ['reply' => 'Editing my reply again'])->assertSessionHas('error');

        $otherOwner = Supplier::approved()->where('id', '!=', $rv->supplier_id)->first()->owner;
        $rv2 = Review::where('supplier_id', $rv->supplier_id)->whereNull('reply')->first() ?? $rv;
        $this->as($otherOwner, 'supplier')->post("/supplier/reviews/{$rv2->id}/reply", ['reply' => 'Not my shop but replying'])->assertSessionHas('error');
        $this->get("/suppliers/{$rv->supplier_id}?tab=reviews")->assertSee('Response from');
    }

    public function test_report_hide_restore_and_dismiss(): void
    {
        $rv = Review::whereNotNull('product_id')->firstOrFail();
        $pid = $rv->product_id;
        $reporter = User::whereJsonContains('roles', 'customer')->where('id', '!=', $rv->customer_id)->first();

        $this->as($rv->customer, 'customer')->post("/reviews/{$rv->id}/report", ['reason' => 'fake'])->assertSessionHas('error');
        $this->as($reporter, 'customer')->post("/reviews/{$rv->id}/report", ['reason' => 'offensive', 'details' => 'Rude words'])->assertSessionHas('success');
        $this->as($reporter, 'customer')->post("/reviews/{$rv->id}/report", ['reason' => 'spam'])->assertSessionHas('error');
        $this->assertSame(1, ReviewReport::where('review_id', $rv->id)->count());

        $admin = User::where('email', 'admin@partyrent.ph')->firstOrFail();
        $this->as($admin)->get('/admin/reviews')->assertOk()->assertSee('Offensive or abusive language')->assertSee('Rude words');

        $this->as($admin)->post("/admin/reviews/{$rv->id}/moderate", ['action' => 'hide'])->assertSessionHasErrors('reason');
        $this->as($admin)->post("/admin/reviews/{$rv->id}/moderate", ['action' => 'hide', 'reason' => 'Abusive language'])->assertSessionHas('success');
        $this->assertNotNull($rv->fresh()->hidden_at);
        $this->assertSame('actioned', ReviewReport::where('review_id', $rv->id)->first()->status);
        $this->assertSame(Review::visible()->where('product_id', $pid)->count(), Product::find($pid)->review_count);
        $this->get("/products/{$pid}")->assertDontSee($rv->comment);

        $this->as($admin)->post("/admin/reviews/{$rv->id}/moderate", ['action' => 'restore'])->assertSessionHas('success');
        $this->assertNull($rv->fresh()->hidden_at);
        $this->get("/products/{$pid}")->assertSee($rv->comment);

        // dismiss keeps it visible
        $this->as($reporter, 'customer')->post('/reviews/'.Review::where('id', '!=', $rv->id)->where('customer_id', '!=', $reporter->id)->first()->id.'/report', ['reason' => 'spam'])->assertSessionHas('success');
        $other = ReviewReport::where('status', 'open')->first()->review;
        $this->as($admin)->post("/admin/reviews/{$other->id}/moderate", ['action' => 'dismiss'])->assertSessionHas('success');
        $this->assertNull($other->fresh()->hidden_at);
        $this->assertSame(0, ReviewReport::where('status', 'open')->count());
    }

    public function test_review_pages_render_for_each_role(): void
    {
        $this->get('/')->assertOk();
        $sup = Supplier::approved()->first();
        $this->as($sup->owner, 'supplier')->get('/supplier/reviews?tab=all')->assertOk();
        $this->as($sup->owner, 'supplier')->get('/supplier/reviews?tab=hidden')->assertOk();
        $super = User::whereJsonContains('roles', 'superadmin')->first();
        $this->as($super)->get('/admin/reviews?tab=all')->assertOk();
        $cashier = User::where('email', 'cashier@elegantoccasion.ph')->first();
        $this->assertNotSame(200, $this->as($cashier)->get('/admin/reviews')->status());
        $k = $this->rentalReview(package: true)->package;
        $this->get("/packages/{$k->id}")->assertOk()->assertSee('Customer reviews')->assertSee('Verified rental');
    }

    /** A verified review from a completed rental (the v4 seed's reviews are not tied to rentals). */
    private function rentalReview(bool $package = false): Review
    {
        $r = \App\Models\RentalRequest::where('status', 'completed')->firstOrFail();
        $item = $package ? ['package_id' => \App\Models\Package::where('supplier_id', $r->supplier_id)->value('id')] : ['product_id' => array_key_first(\App\Domain\Rentals::peakUnits($r))];

        return Review::create(['supplier_id' => $r->supplier_id, 'rental_request_id' => $r->id, 'customer_id' => $r->customer_id, 'rating' => 5, 'comment' => 'Everything arrived on time and clean.'] + $item);
    }
}
