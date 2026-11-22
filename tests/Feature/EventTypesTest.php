<?php

namespace Tests\Feature;

use App\Models\EventBooking;
use App\Models\EventPackage;
use App\Models\EventType;
use App\Models\Supplier;
use App\Models\SupplierEventType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * What an event company can handle (2026-11-22): PartyRent keeps a catalog of event types; a business applies per
 * type with proof; PartyRent verifies each with limits; packages and inquiries only work for verified types within
 * the limits; a raise waits for review; one type can be paused; a business can ask for a new type.
 */
class EventTypesTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = \Database\Seeders\DemoSeeder::class;

    private Supplier $s;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        $this->s = Supplier::where('name', 'Grand Fiesta Events Co.')->firstOrFail();
    }

    private function png(string $name = 'event.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='));
    }

    private function owner(): static
    {
        return $this->actingAs($this->s->owner->fresh())->withSession(['mode' => 'supplier']);
    }

    private function admin(): static
    {
        return $this->actingAs(User::where('email', 'admin@partyrent.ph')->firstOrFail())->withSession(['mode' => 'admin']);
    }

    private function type(string $name): EventType
    {
        return EventType::where('name', $name)->firstOrFail();
    }

    private function inquiry(string $type, int $guests, string $date, ?int $pkg = null)
    {
        return $this->actingAs(User::where('email', 'jose@demo.ph')->firstOrFail())->withSession(['mode' => 'customer'])->post(route('events.store', $this->s), [
            'event_package_id' => $pkg, 'event_type' => $type, 'event_date' => $date, 'guests' => $guests, 'venue' => 'Function Hall, Quezon City',
        ]);
    }

    public function test_catalog_and_existing_company_keep_their_types(): void
    {
        $this->assertGreaterThanOrEqual(18, EventType::where('status', 'active')->count());
        $this->assertSame(['Prenup shoot', 'Ceremony', 'Reception'], array_column($this->type('Wedding')->parts, 'name'));
        // Grand Fiesta's four package types were verified as they were already offered
        $verified = \App\Domain\EventTypes::verified($this->s);
        $this->assertEqualsCanonicalizing(['Wedding', 'Debut', 'Corporate Party', 'Birthday'], $verified->keys()->all());
        $this->assertSame(250, $verified['Wedding']->max_guests);
        $this->assertSame(['Corporate Party'], EventPackage::where('name', 'Corporate Night Package')->value('events'));
        // the public page shows what PartyRent verified
        $this->get(route('suppliers.show', $this->s))->assertOk()->assertSee('Verified for')->assertSee('Wedding · up to 250 guests')->assertSee('Garden Wedding Package');
    }

    public function test_apply_review_and_offer_a_new_type(): void
    {
        $grad = $this->type('Graduation');
        $this->owner()->get(route('business.event-types'))->assertOk()->assertSee('Event Types')->assertSee('Graduation')->assertSee('Waiting for review');
        // no photo: refused
        $this->post(route('business.event-types.apply', $grad), ['max_guests' => 250, 'per_day' => 2, 'past_events' => 6])->assertSessionHas('error');
        $this->post(route('business.event-types.apply', $grad), ['max_guests' => 250, 'per_day' => 2, 'past_events' => 6, 'photos' => [$this->png()]])->assertSessionHas('success');
        $row = SupplierEventType::where('supplier_id', $this->s->id)->where('event_type_id', $grad->id)->firstOrFail();
        $this->assertSame('pending', $row->status);
        $this->assertCount(1, $row->photos);

        // not verified yet: no package, no inquiry
        $this->post(route('business.packages.store'), ['name' => 'Grad Ball', 'description' => 'A themed graduation ball with stage and lights.', 'price' => 60000, 'max_guests' => 200, 'events' => ['Graduation'], 'inclusions' => ['Stage']])
            ->assertSessionHasErrors('events');
        $this->inquiry('Graduation', 100, today()->addDays(40)->toDateString())->assertSessionHas('error', fn ($e) => str_contains($e, 'does not take Graduation'));

        // PartyRent verifies it, lower than asked
        $this->admin()->get(route('admin.event-types'))->assertOk()->assertSee('Grand Fiesta Events Co. · Graduation')->assertSee('Up to 250 guests · 2 a day');
        $this->get(route('admin.event-types.file', [$row, 'photo', 0]))->assertOk();
        $this->post(route('admin.event-types.review', $row), ['decision' => 'approve', 'max_guests' => 150, 'per_day' => 1])->assertSessionHas('success');
        $row->refresh();
        $this->assertSame(['verified', 150, 1], [$row->status, $row->max_guests, $row->per_day]);

        // packages: within the verified guest count only
        $this->owner()->post(route('business.packages.store'), ['name' => 'Grad Ball', 'description' => 'A themed graduation ball with stage and lights.', 'price' => 60000, 'max_guests' => 200, 'events' => ['Graduation'], 'inclusions' => ['Stage']])
            ->assertSessionHasErrors(['events' => 'You are verified for Graduation events of up to 150 guests. Lower the maximum guests, or ask PartyRent for a higher limit on the Event Types page.']);
        $this->post(route('business.packages.store'), ['name' => 'Grad Ball', 'description' => 'A themed graduation ball with stage and lights.', 'price' => 60000, 'max_guests' => 150, 'events' => ['Graduation'], 'inclusions' => ['Stage']])
            ->assertRedirect(route('business.packages'));

        // inquiries: guest limit and events of that type per day
        $date = today()->addDays(40)->toDateString();
        $this->inquiry('Graduation', 180, $date)->assertSessionHas('error', fn ($e) => str_contains($e, 'up to 150 guests'));
        $this->inquiry('Graduation', 120, $date)->assertRedirect();
        $b = EventBooking::where('event_type', 'Graduation')->firstOrFail();
        $b->update(['status' => 'confirmed']);
        $this->inquiry('Graduation', 80, $date)->assertSessionHas('error', fn ($e) => str_contains($e, 'fully booked'));
    }

    public function test_raise_keeps_old_limits_until_reviewed_and_pause_hides_packages(): void
    {
        $row = $this->s->eventTypes()->whereHas('type', fn ($q) => $q->where('name', 'Birthday'))->firstOrFail();
        $this->owner()->post(route('business.event-types.apply', $row->type), ['max_guests' => 200, 'per_day' => 3, 'past_events' => 40, 'photos' => [$this->png()]])->assertSessionHas('success');
        $row->refresh();
        $this->assertTrue($row->raise_pending);
        $this->assertSame(['verified', 80], [$row->status, $row->max_guests]); // still the old limit
        $this->admin()->post(route('admin.event-types.review', $row), ['decision' => 'reject', 'reason' => 'Show photos of bigger parties first.'])->assertSessionHas('success');
        $row->refresh();
        $this->assertSame(['verified', 80, false], [$row->status, $row->max_guests, $row->raise_pending]);

        // pausing one type hides its packages and stops inquiries; the other types keep working
        $this->post(route('admin.event-types.pause', $row), ['pause' => 1])->assertSessionHas('error'); // needs a reason
        $this->post(route('admin.event-types.pause', $row), ['pause' => 1, 'reason' => 'Three late cancellations this month'])->assertSessionHas('success');
        $this->get(route('suppliers.show', $this->s))->assertDontSee('Kids Party Package')->assertSee('Classic Debut Package');
        $this->inquiry('Birthday', 50, today()->addDays(40)->toDateString())->assertSessionHas('error');
        $this->owner()->get(route('business.packages'))->assertSee('Not shown');
        $this->admin()->post(route('admin.event-types.pause', $row), ['pause' => 0])->assertSessionHas('success');
        $this->get(route('suppliers.show', $this->s))->assertSee('Kids Party Package');
    }

    public function test_ask_for_a_new_type_and_permits(): void
    {
        $this->owner()->post(route('business.event-types.request'), ['name' => 'Wedding'])->assertSessionHas('error'); // already listed
        $this->post(route('business.event-types.request'), ['name' => 'Pet Birthday', 'note' => 'Dog and cat parties'])->assertSessionHas('success');
        $t = EventType::where('name', 'Pet Birthday')->firstOrFail();
        $this->assertSame('requested', $t->status);
        $this->post(route('business.event-types.apply', $t), ['max_guests' => 30, 'per_day' => 1, 'past_events' => 2, 'photos' => [$this->png()]])->assertSessionHas('error');
        $this->admin()->get(route('admin.event-types', ['tab' => 'catalog']))->assertOk()->assertSee('Pet Birthday')->assertSee('Asked for');
        $this->post(route('admin.event-types.decide', $t), ['add' => 1])->assertSessionHas('success');
        $this->assertSame('active', $t->fresh()->status);

        // a type that needs permits: refused until they are uploaded
        $concert = $this->type('Concert / Show');
        $this->owner()->post(route('business.event-types.apply', $concert), ['max_guests' => 1000, 'per_day' => 1, 'past_events' => 3, 'photos' => [$this->png()]])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'LGU event permit'));
        $this->post(route('business.event-types.apply', $concert), ['max_guests' => 1000, 'per_day' => 1, 'past_events' => 3, 'photos' => [$this->png()],
            'docs' => [0 => UploadedFile::fake()->create('permit.pdf', 20, 'application/pdf'), 1 => UploadedFile::fake()->create('security.pdf', 20, 'application/pdf')]])->assertSessionHas('success');

        // a front-desk employee cannot change what the business offers; other businesses cannot see the proof
        $row = SupplierEventType::where('event_type_id', $concert->id)->firstOrFail();
        $this->actingAs(User::where('email', 'juan@demo.ph')->firstOrFail())->withSession(['mode' => 'supplier'])
            ->get(route('business.event-types.file', [$row, 'doc', 0]))->assertStatus(302);
    }
}
