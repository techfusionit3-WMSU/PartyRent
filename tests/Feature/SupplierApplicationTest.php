<?php

namespace Tests\Feature;

use App\Domain\Applications;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** v4 supplier application wizard: Individual / Organization, documents per type, resubmission. */
class SupplierApplicationTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = \Database\Seeders\DemoSeeder::class;

    private function pdf(string $name): UploadedFile
    {
        return UploadedFile::fake()->create($name, 40, 'application/pdf');
    }

    private function photo(string $name): UploadedFile
    {
        return UploadedFile::fake()->create($name, 60, 'image/jpeg');
    }

    /** A business also says what it offers and shows at least 3 photos of past events. */
    private function orgForm(array $docs, ?array $portfolio = null): array
    {
        return [
            'events_catered' => ['wedding', 'Corporate', 'Graduation'], 'offers' => ['Full event coordination', 'balloon styling'],
            'event_size' => '150_300', 'packages_from' => 25000,
            // an event company: service areas and a payment schedule instead of delivery and payment methods
            'serviceAreas' => ['Marikina', 'pasig city'], 'reservePct' => 20, 'depositPct' => 30,
            'rescheduling' => 'One free date change up to 14 days before.',
            'portfolio' => $portfolio ?? [$this->photo('a.jpg'), $this->photo('b.jpg'), $this->photo('c.jpg')],
            'app' => ['type' => 'organization',
                'company' => ['legalName' => 'Garcia Events Corp.', 'companyType' => 'cooperative', 'branches' => 2, 'address' => '7 Sampaguita St., Marikina Heights, Marikina', 'email' => 'ops@garcia.ph', 'phone' => '0923 555 0707', 'natureOfBusiness' => 'Party and event equipment rental', 'description' => 'Tents, sound and lights for Marikina events.'],
                'representative' => ['name' => 'Jose Garcia', 'position' => 'Manager', 'idType' => 'Passport', 'email' => 'jose@demo.ph', 'phone' => '0923 555 0707'],
                'applicable' => ['barangay_clearance'],
                'payout' => ['method' => 'bank', 'provider' => 'BPI', 'accountName' => 'Garcia Events Corp.', 'accountNumber' => '1234567890', 'financeContact' => 'Ana Garcia']],
            'docs' => $docs,
            'name' => 'Garcia Events', 'description' => 'Tents, sound and lights for Marikina events.', 'logo' => '🎪',
            'phone' => '0923 555 0707', 'email' => 'ops@garcia.ph', 'hours' => 'Daily 8–6',
            'shop_addr' => ['regionCode' => '130000000', 'provinceCode' => '137000000', 'cityCode' => '137402000', 'barangay' => 'Marikina Heights', 'street' => '7 Sampaguita St.'],
            'location_type' => 'shop', 'lat' => 14.6520, 'lng' => 121.1100, 'fulfillment' => 'both', 'delivery_fee' => 400,
            'methods' => ['bank'], 'offerPlans' => ['standard', 'installment'], 'depositType' => 'percent', 'depositValue' => 10, 'cancelDays' => 5,
            'cancellation' => 'Free up to 5 days before.', 'returns' => 'Return clean.', 'conditions' => 'Renter pays for damage.',
        ];
    }

    public function test_requirements_depend_on_type_and_company_type(): void
    {
        $this->assertSame('gov_id', Applications::requirements('individual')[0]['key']);
        $this->assertSame('cda_certificate', Applications::requirements('organization', 'cooperative')[0]['key']);
        $missing = Applications::missingDocs(['type' => 'organization', 'company' => ['companyType' => 'cooperative'], 'applicable' => ['barangay_clearance'], 'docs' => ['bir_cor' => ['name' => 'x']]]);
        $this->assertEqualsCanonicalizing(['cda_certificate', 'mayors_permit', 'barangay_clearance', 'rep_id', 'proof_of_authority'], array_column($missing, 'key'));
    }

    public function test_organization_application(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $jose = User::where('email', 'jose@demo.ph')->firstOrFail();
        $this->actingAs($jose)->withSession(['mode' => 'customer'])->get('/start-selling')->assertOk()->assertSee('Organization / Company Supplier');

        $docs = ['cda_certificate' => $this->pdf('cda.pdf'), 'bir_cor' => $this->pdf('bir.pdf'), 'mayors_permit' => $this->pdf('permit.pdf'), 'rep_id' => $this->pdf('passport.pdf'), 'proof_of_authority' => $this->pdf('authority.pdf')];
        // barangay clearance was marked "applies" but not uploaded
        $this->post('/start-selling', $this->orgForm($docs))->assertSessionHasErrors('docs');
        $this->assertNull(Supplier::where('name', 'Garcia Events')->first());

        $this->post('/start-selling', $this->orgForm($docs + ['barangay_clearance' => $this->pdf('brgy.pdf'), 'gov_id' => $this->pdf('ignored.pdf')]))->assertSessionHas('success');
        $s = Supplier::where('name', 'Garcia Events')->firstOrFail();
        $this->assertSame(['organization', 'pending', 'business'], [$s->type, $s->status, $s->account_type]);
        $this->assertSame(['legalName' => 'Garcia Events Corp.', 'companyType' => 'cooperative'], $s->company);
        $this->assertSame('Jose Garcia', $s->contact_person);
        $this->assertArrayNotHasKey('gov_id', $s->application['docs']); // not an organization document
        $this->assertCount(6, $s->application['docs']);
        // payment terms are not part of the application: defaults (offerPlans/deposit in the form are ignored), no deposit
        $this->assertSame(['standard', 'full'], \App\Domain\Finance::plansOf($s));
        $this->assertEquals(0, $s->policy['depositValue']);
        $this->assertSame(['bank', 'BPI'], [$s->payout_method, $s->payout_bank]);
        $this->assertEquals(0, $s->delivery_fee); // delivery fee is not asked in the application (form value ignored)
        // what the business offers (typed tags keep the suggested spelling) and its portfolio
        $bp = $s->business_profile;
        $this->assertEquals(['Wedding', 'Corporate', 'Graduation'], $bp['events']);
        $this->assertEquals(['Full event coordination', 'Balloon Styling'], $bp['offers']);
        $this->assertEquals(['150_300', 25000], [$bp['eventSize'], $bp['packagesFrom']]);
        $this->assertEquals(['Marikina', 'Pasig City'], $s->bizSettings()['serviceAreas']);
        $this->assertEquals([20, 30], [$s->bizSettings()['reservePct'], $s->bizSettings()['depositPct']]);
        $this->assertSame('One free date change up to 14 days before.', $s->policy['rescheduling']);
        $this->assertCount(3, $s->portfolio);
        Storage::disk('public')->assertExists($s->portfolio[0]);
        $this->get(route('suppliers.show', $s))->assertOk()->assertSee('Events we cater')->assertSee('Balloon Styling')->assertSee('Packages from');

        // asked to revise → the applicant can edit and resubmit; documents already sent are kept
        $admin = User::where('email', 'admin@partyrent.ph')->firstOrFail();
        $this->actingAs($admin)->withSession(['mode' => 'admin'])->get('/admin/applications')->assertOk()->assertSee('permit.pdf')->assertSee('Request revision');
        $this->get(route('admin.applications.doc', [$s, 'mayors_permit']))->assertOk();
        $this->get(route('admin.applications.doc', [$s, 'nope']))->assertNotFound();
        $this->post(route('admin.applications.review', $s), ['decision' => 'revision', 'reason' => ''])->assertSessionHas('error');
        $this->post(route('admin.applications.review', $s), ['decision' => 'revision', 'reason' => 'Upload a clearer permit.'])->assertSessionHas('success');
        $this->assertSame('revision', $s->fresh()->status);
        $this->get('/admin/applications?tab=revision')->assertOk()->assertSee('Upload a clearer permit.');
        $this->actingAs($jose)->withSession(['mode' => 'customer'])->get(route('admin.applications.doc', [$s, 'mayors_permit']))->assertRedirect(); // not a reviewer
        $this->actingAs($jose->fresh())->withSession(['mode' => 'customer']); // reload the cached supplier relation
        $this->get('/start-selling')->assertSee('Requires Revision')->assertSee('Upload a clearer permit.');
        $this->get('/start-selling?edit=1')->assertOk()->assertSee('cda.pdf');
        $this->get('/start-selling?edit=1')->assertSee('portfolio_keep[]', false);
        // keeping 2 of the 3 saved photos is not enough; keeping all 3 works without uploading again
        $this->post('/start-selling', $this->orgForm(['mayors_permit' => $this->pdf('permit-v2.pdf')], []) + ['portfolio_keep' => array_slice($s->portfolio, 0, 2)])->assertSessionHasErrors('portfolio');
        $this->post('/start-selling', $this->orgForm([], []) + ['portfolio_keep' => $s->portfolio])->assertSessionHas('success');
        $s->refresh();
        $this->assertCount(3, $s->portfolio);
        $this->assertSame('pending', $s->status);
        $this->assertSame('permit-v2.pdf', $s->application['docs']['mayors_permit']['name']);
        $this->assertSame('revision', $s->application['history'][0]['status']);
    }

    /** "Continue" checks each step on the server, so a bad number stops the applicant on that step. */
    public function test_each_step_is_checked_before_continuing(): void
    {
        $jose = User::where('email', 'jose@demo.ph')->firstOrFail();
        $form = $this->orgForm([]);
        $this->actingAs($jose)->withSession(['mode' => 'customer']);

        $bad = $form;
        $bad['app']['company']['phone'] = '12345';
        $this->postJson('/start-selling/check', $bad + ['step' => 1])->assertStatus(422)
            ->assertJsonPath('step', 1)->assertJsonPath('errors', ['app.company.phone' => ['Enter a valid PH mobile number (e.g. 0917 555 0101).']]);
        $this->postJson('/start-selling/check', $form + ['step' => 1])->assertOk();
        // later steps' fields are not checked yet
        $this->postJson('/start-selling/check', array_merge($bad, ['phone' => 'abc']) + ['step' => 0])->assertOk();

        $this->postJson('/start-selling/check', array_merge($form, ['phone' => '0917']) + ['step' => 3])->assertStatus(422)->assertJsonValidationErrors('phone');
        // a business must say which events it caters and what it offers
        $this->postJson('/start-selling/check', array_merge($form, ['events_catered' => [], 'event_size' => '']) + ['step' => 3])->assertStatus(422)->assertJsonValidationErrors(['events_catered', 'event_size']);
        // an event company has no pickup, so no pin is needed; it must list the areas it serves instead of delivery/methods
        $noPin = array_merge($form, ['lat' => null, 'lng' => null]);
        $this->postJson('/start-selling/check', $noPin + ['step' => 4])->assertOk();
        $this->postJson('/start-selling/check', array_merge($form, ['serviceAreas' => [], 'fulfillment' => null, 'methods' => []]) + ['step' => 4])
            ->assertStatus(422)->assertJsonValidationErrors('serviceAreas')->assertJsonMissingValidationErrors(['fulfillment', 'methods']);
        // no payment percentages are asked in the application any more
        $this->postJson('/start-selling/check', array_merge($form, ['depositValue' => 150, 'offerPlans' => []]) + ['step' => 4])->assertOk();
    }

    /** A failed final submit keeps the documents already uploaded (file inputs cannot be refilled after a reload). */
    public function test_uploaded_documents_survive_a_failed_submit(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $jose = User::where('email', 'jose@demo.ph')->firstOrFail();
        $docs = ['cda_certificate' => $this->pdf('cda.pdf'), 'bir_cor' => $this->pdf('bir.pdf'), 'mayors_permit' => $this->pdf('permit.pdf'), 'rep_id' => $this->pdf('passport.pdf'), 'proof_of_authority' => $this->pdf('authority.pdf'), 'barangay_clearance' => $this->pdf('brgy.pdf')];
        $bad = $this->orgForm($docs);
        $bad['app']['representative']['phone'] = '0917';
        $this->actingAs($jose)->withSession(['mode' => 'customer'])->post('/start-selling', $bad)->assertSessionHasErrors('app.representative.phone');
        $this->get('/start-selling')->assertOk()->assertSee('permit.pdf');

        // resubmitting without picking the files again works (the photos from the failed submit are kept too)
        $kept = session('apply_photos')['portfolio'];
        $this->assertCount(3, $kept);
        $this->post('/start-selling', $this->orgForm([], []) + ['portfolio_keep' => $kept])->assertSessionHas('success');
        $this->assertCount(3, Supplier::where('name', 'Garcia Events')->firstOrFail()->portfolio);
        $this->assertCount(6, Supplier::where('name', 'Garcia Events')->firstOrFail()->application['docs']);
        $this->assertNull(session('apply_docs'));
    }
}
