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

    private function orgForm(array $docs): array
    {
        return [
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
        $this->assertSame(['standard', 'installment'], \App\Domain\Finance::plansOf($s));
        $this->assertSame(['bank', 'BPI'], [$s->payout_method, $s->payout_bank]);
        $this->assertEquals(400, $s->delivery_fee);

        // asked to revise → the applicant can edit and resubmit; documents already sent are kept
        $s->update(['status' => 'revision', 'revision_note' => 'Upload a clearer permit.']);
        $this->actingAs($jose->fresh())->withSession(['mode' => 'customer']); // reload the cached supplier relation
        $this->get('/start-selling')->assertSee('Requires Revision')->assertSee('Upload a clearer permit.');
        $this->get('/start-selling?edit=1')->assertOk()->assertSee('cda.pdf');
        $this->post('/start-selling', $this->orgForm(['mayors_permit' => $this->pdf('permit-v2.pdf')]))->assertSessionHas('success');
        $s->refresh();
        $this->assertSame('pending', $s->status);
        $this->assertSame('permit-v2.pdf', $s->application['docs']['mayors_permit']['name']);
        $this->assertSame('revision', $s->application['history'][0]['status']);
    }
}
