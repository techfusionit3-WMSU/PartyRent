<?php

namespace Database\Seeders;

use App\Domain\Finance;
use App\Domain\Locations;
use App\Domain\Pricing;
use App\Domain\Settings;
use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\CartLine;
use App\Models\Category;
use App\Models\Complaint;
use App\Models\Hold;
use App\Models\InventoryLocation;
use App\Models\LocationStock;
use App\Models\Package;
use App\Models\Payment;
use App\Models\Product;
use App\Models\RentalRequest;
use App\Models\RequestEvent;
use App\Models\Review;
use App\Models\Setting;
use App\Models\ShopStaff;
use App\Models\Supplier;
use App\Models\User;
use App\Support\AddressInput;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Demo data ported from the reference prototype's js/core/seed.js (Version 4).
 * Dates are relative to "today" so the demo stays consistent whenever it is seeded.
 * Every demo account uses the password "demo123".
 */
class DemoSeeder extends Seeder
{
    /** When true, users() creates only the platform staff accounts (admins, superadmin). */
    protected bool $staffOnly = false;

    private array $U = [];   // seed user key => db id

    private array $S = [];   // supplier

    private array $P = [];   // product

    private array $K = [];   // package

    private array $R = [];   // request code => db id

    private array $L = [];   // "s4|L1" => inventory_location id

    /** Demo addresses: [street, barangay, city code] (codes from database/data/locations-ph.php). */
    private const ADDR = [
        'maria' => ['23 Mabini St.', 'Kamuning', '137404000'],
        'juan' => ['88 Katipunan Ave.', 'Loyola Heights', '137404000'],
        'ana' => ['12 Caruncho Ave.', 'Malinao', '137403000'],
        'carlo' => ['401 Chino Roces Ave.', 'Magallanes', '137602000'],
        'liza' => ['5th Ave., Bonifacio Global City', 'Fort Bonifacio', '137607000'],
        'ramon' => ['1530 Rizal Ave., Sta. Cruz', '310', '133900000'],
        'jose' => ['7 Sampaguita St.', 'Marikina Heights', '137402000'],
        'bea' => ['19 Rizal Ave. Ext.', 'Grace Park East', '137501000'],
        'kristine' => ['33 Aguirre Ave., BF Homes', 'BF Homes', '137604000'],
        'miguel' => ['2 Gen. Luna St.', 'Concepcion', '137502000'],
        'aquino' => ['120 Dr. A. Santos Ave.', 'San Isidro', '137604000'],
    ];

    protected function addr(?array $a, string $landmark = ''): array
    {
        if (! $a) {
            return ['address' => '', 'address_parts' => null, 'city_code' => null];
        }

        return AddressInput::attributes(['cityCode' => $a[2], 'barangay' => $a[1], 'street' => $a[0], 'landmark' => $landmark]);
    }

    protected function D(int $n): string
    {
        return add_days(today_iso(), $n);
    }

    protected function ts(int $n, int $h = 10): Carbon
    {
        return Carbon::parse($this->D($n))->setTime($h, 15);
    }

    public function run(): void
    {
        $this->settings();
        $this->categories();
        $this->users();
        if ($this->staffOnly) {
            return;
        }
        $this->suppliers();
        $this->products();
        $this->holds();
        $this->packages();
        $this->requests();
        $this->payAccounts();
        $this->payments();
        $this->payouts();
        $this->reviewsAndComplaints();
        $this->notifications();
        $this->audit();
        $this->cart();
    }

    /** App-only: PartyRent's own collection accounts for bank / e-wallet transfers (superadmin Pay accounts). */
    protected function payAccounts(): void
    {
        \App\Models\PayAccount::insert([
            ['type' => 'gcash', 'label' => 'GCash', 'account_name' => 'PartyRent Rentals Inc.', 'account_no' => '0917 555 0142', 'monthly_cap' => 100000, 'active' => true, 'sort' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['type' => 'bank', 'label' => 'BDO', 'account_name' => 'PartyRent Rentals Inc.', 'account_no' => '0012 3456 7890', 'monthly_cap' => null, 'active' => true, 'sort' => 2, 'created_at' => now(), 'updated_at' => now()],
            ['type' => 'maya', 'label' => 'Maya', 'account_name' => 'PartyRent Rentals Inc.', 'account_no' => '0918 555 0199', 'monthly_cap' => 100000, 'active' => false, 'sort' => 3, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    protected function settings(): void
    {
        foreach (Settings::DEFAULTS as $k => $v) {
            Setting::create(['key' => $k, 'value' => $v]);
        }
        Setting::create(['key' => 'permissions.admin', 'value' => ['customers.view', 'customers.manage', 'suppliers.view', 'suppliers.manage', 'applications.review', 'listings.view', 'listings.manage', 'categories.manage', 'transactions.view', 'payouts.manage', 'complaints.manage', 'reports.view']]);
        Setting::create(['key' => 'permissions.cashier', 'value' => array_keys(User::CASHIER_PERMS)]);
        foreach (['seq.request' => 13, 'seq.payment' => 25, 'seq.receipt' => 10025, 'seq.complaint' => 3] as $k => $v) {
            Setting::create(['key' => $k, 'value' => $v]);
        }
        Settings::flush();
    }

    protected function categories(): void
    {
        $cats = [
            ['tables', 'Tables', 'table', '#FFB4D1', '#E11D74'], ['chairs', 'Chairs', 'chair', '#C4B5FD', '#7C3AED'],
            ['tents', 'Tents & Canopies', 'tent', '#99F6E4', '#0D9488'], ['tableware', 'Tableware', 'plate', '#FDE68A', '#F59E0B'],
            ['sound', 'Sound Systems', 'speaker', '#A5B4FC', '#4338CA'], ['lighting', 'Lighting Equipment', 'bulb', '#FDE68A', '#D97706'],
            ['decorations', 'Decorations', 'balloon', '#FBCFE8', '#DB2777'], ['backdrops', 'Backdrops', 'frame', '#F5D0FE', '#A21CAF'],
            ['games', 'Party Games', 'game', '#BAE6FD', '#0284C7'], ['catering', 'Catering Equipment', 'pot', '#FED7AA', '#EA580C'],
            ['furniture', 'Event Furniture', 'sofa', '#DDD6FE', '#6D28D9'], ['other', 'Other Party Supplies', 'gift', '#FECDD3', '#E11D48'],
        ];
        foreach ($cats as $i => [$id, $name, $icon, $a, $b]) {
            Category::create(['id' => $id, 'name' => $name, 'icon' => $icon, 'grad_from' => $a, 'grad_to' => $b, 'active' => true, 'sort' => $i]);
        }
    }

    protected function users(): void
    {
        $pw = Hash::make('demo123');
        $A = self::ADDR;
        $HQ = 'PartyRent HQ, Ortigas Center, Pasig';
        $BGC = '5th Ave., Bonifacio Global City, Taguig';
        $rows = [
            ['u1', 'Maria Santos', 'maria@demo.ph', '0917 555 0101', $A['maria'], ['customer'], 'active', -120],
            ['u2', 'Juan Dela Cruz', 'juan@demo.ph', '0918 555 0202', $A['juan'], ['customer'], 'active', -300],
            ['u3', 'Ana Reyes', 'ana@demo.ph', '0919 555 0303', $A['ana'], ['customer'], 'active', -280],
            ['u4', 'Carlo Mendoza', 'carlo@demo.ph', '0920 555 0404', $A['carlo'], ['customer'], 'active', -260],
            ['u5', 'Liza Navarro', 'liza@demo.ph', '0921 555 0505', $A['liza'], ['customer'], 'active', -240],
            ['u6', 'Ramon Bautista', 'ramon@demo.ph', '0922 555 0606', $A['ramon'], ['customer'], 'active', -220],
            ['u7', 'Jose Garcia', 'jose@demo.ph', '0923 555 0707', $A['jose'], ['customer'], 'active', -90],
            ['u8', 'Bea Mendoza', 'bea@demo.ph', '0924 555 0808', $A['bea'], ['customer'], 'active', -60],
            ['u9', 'Kristine Aquino', 'kristine@demo.ph', '0925 555 0909', $A['kristine'], ['customer'], 'active', -75],
            ['u14', 'Miguel Torres', 'miguel@demo.ph', '0926 555 1414', $A['miguel'], ['customer'], 'suspended', -40],
            ['u10', 'Grace Villanueva', 'cashier@elegantoccasion.ph', '0917 800 1010', $BGC, ['cashier'], 'active', -230],
            ['u15', 'Rico Santos', 'inventory@elegantoccasion.ph', '0917 800 1515', $BGC, ['employee'], 'active', -200],
            ['u16', 'Joy Lim', 'staff@elegantoccasion.ph', '0917 800 1616', 'Pasig City', ['employee'], 'active', -120],
            ['u11', 'Mark Reyes', 'admin@partyrent.ph', '0917 800 1111', $HQ, ['admin'], 'active', -340],
            ['u13', 'Paolo Cruz', 'paolo.admin@partyrent.ph', '0917 800 1313', $HQ, ['admin'], 'active', -100],
            ['u12', 'Patricia Lim', 'super@partyrent.ph', '0917 800 1212', $HQ, ['superadmin'], 'active', -365],
        ];
        if ($this->staffOnly) {
            $rows = array_filter($rows, fn ($r) => (bool) array_intersect(['admin', 'superadmin'], $r[5]));
        }
        foreach ($rows as [$key, $name, $email, $phone, $addr, $roles, $status, $d]) {
            $u = new User(array_merge(['name' => $name, 'email' => $email, 'phone' => $phone, 'roles' => $roles, 'status' => $status, 'password' => $pw], is_array($addr) ? $this->addr($addr) : ['address' => $addr]));
            $u->created_at = $this->ts($d);
            $u->email_verified_at = $u->created_at;
            if ($key === 'u13') {
                $u->admin_perms = ['customers.view', 'suppliers.view', 'listings.view', 'transactions.view', 'complaints.manage', 'reports.view'];
            }
            $u->save();
            $this->U[$key] = $u->id;
        }
    }

    protected function suppliers(): void
    {
        $base = [
            'cancellation' => 'Free cancellation up to 3 days before the rental start date. Cancellations within 3 days forfeit the down payment.',
            'returns' => 'Items must be returned clean and complete on the agreed return date. Late returns are charged one extra rental day.',
            'conditions' => 'Renter is responsible for items while in their care. Damaged or missing items are charged at repair or replacement cost.',
        ];
        $A = self::ADDR;
        $DMG = fn (array $o = []) => array_replace_recursive(['repairable' => ['enabled' => true, 'pct' => 50], 'nonRepairable' => ['enabled' => true, 'pct' => 70], 'lost' => ['enabled' => true, 'brand_new' => 100, 'good_as_new' => 100, 'used' => 70], 'categories' => []], $o);
        $loc = fn ($id, $name, $address, $contact, $phone) => compact('id', 'name', 'address', 'contact', 'phone');
        $plans = fn ($std, $full, $inst = null) => ['standard' => ['enabled' => true] + $std, 'full' => ['enabled' => true, 'days' => $full], 'installment' => $inst ? ['enabled' => true] + $inst : ['enabled' => false]];
        // key, owner, name, logo, color, description, address, phone, email, hours, fulfillment, deliveryFee (fallback), methods, policy, rating, status, applied, v4
        $rows = [
            ['s1', 'u2', 'Happy Moments Party Rentals', '🎈', '#E11D74', 'Family-run party rentals serving Quezon City since 2016. Birthday setups, kiddie parties, and backyard celebrations made easy.', $A['juan'], '0918 555 0202', 'hello@happymoments.ph', 'Mon–Sun, 8:00 AM – 7:00 PM', 'both', 500, ['cod', 'bank', 'debit'], ['depositType' => 'fixed', 'depositValue' => 1000, 'cancelDays' => 3], 4.8, 'approved', -300, [
                'type' => 'individual', 'payment_plans' => $plans(['downPct' => 30, 'downDays' => 10], 5, ['downPct' => 20, 'downDays' => 14, 'minMonths' => 2, 'maxInstallments' => 4]),
                'time_settings' => ['open' => '06:00', 'close' => '22:00', 'prepHours' => 1, 'recoveryHours' => 1],
                'delivery_zones' => [['id' => 'z1', 'name' => 'Quezon City', 'fee' => 350], ['id' => 'z2', 'name' => 'Marikina / San Juan', 'fee' => 450], ['id' => 'z3', 'name' => 'Manila / Caloocan', 'fee' => 600]],
                'services' => [['id' => 'sv1', 'name' => 'Setup and installation', 'description' => 'Our crew arranges the tables, chairs and décor at the venue.', 'amount' => 300, 'per' => 'location', 'required' => false], ['id' => 'sv2', 'name' => 'Dismantling and packing', 'description' => 'Pack-up and pickup right after the event.', 'amount' => 200, 'per' => 'location', 'required' => false]],
                'damage_policy' => $DMG(), 'locations' => [$loc('L1', 'Main Warehouse', '88 Katipunan Ave., Loyola Heights, Quezon City', 'Juan Dela Cruz', '0918 555 0202'), $loc('L2', 'Branch A – Marikina', '7 Gil Fernando Ave., Marikina', 'Nestor Dela Cruz', '0918 555 0222')]]],
            ['s2', 'u3', 'Celebration Hub Rentals', '✨', '#7C3AED', 'Elegant Tiffany chairs, lights, and floral backdrops for debuts, weddings and milestone events in Pasig and the east of Metro Manila.', $A['ana'], '0919 555 0303', 'book@celebrationhub.ph', 'Mon–Sat, 9:00 AM – 6:00 PM', 'delivery', 800, ['bank', 'debit'], ['depositType' => 'percent', 'depositValue' => 15, 'cancelDays' => 5, 'cancellation' => 'Free cancellation up to 5 days before the event. The down payment is non-refundable.'], 4.6, 'approved', -280, [
                'type' => 'individual', 'payment_plans' => $plans(['downPct' => 40, 'downDays' => 7], 3),
                'time_settings' => ['open' => '08:00', 'close' => '22:00', 'prepHours' => 2, 'recoveryHours' => 1],
                'delivery_zones' => [['id' => 'z1', 'name' => 'Pasig', 'fee' => 500], ['id' => 'z2', 'name' => 'Mandaluyong / San Juan', 'fee' => 700]],
                'services' => [['id' => 'sv1', 'name' => 'Equipment preparation', 'description' => 'Cleaning, cushion fitting and quality check before delivery.', 'amount' => 250, 'per' => 'order', 'required' => true], ['id' => 'sv2', 'name' => 'Styling and setup', 'description' => 'Arrangement of chairs, lights and backdrop by our stylist.', 'amount' => 800, 'per' => 'location', 'required' => false]],
                'damage_policy' => $DMG(), 'locations' => [$loc('L1', 'Pasig Showroom', '12 Caruncho Ave., Pasig City', 'Ana Reyes', '0919 555 0303')]]],
            ['s3', 'u4', 'Dream Events Supplies', '🎪', '#0D9488', 'High-volume tents, banquet tables and pro sound for corporate events and big gatherings. Warehouse pickup in Makati.', $A['carlo'], '0920 555 0404', 'ops@dreamevents.ph', 'Mon–Sat, 7:00 AM – 6:00 PM', 'pickup', 0, ['cod', 'bank'], ['depositType' => 'fixed', 'depositValue' => 2000, 'cancelDays' => 2], 4.5, 'approved', -260, [
                'type' => 'individual', 'payment_plans' => $plans(['downPct' => 20, 'downDays' => 7], 7),
                'time_settings' => ['open' => '07:00', 'close' => '21:00', 'prepHours' => 2, 'recoveryHours' => 2], 'delivery_zones' => [],
                'services' => [['id' => 'sv1', 'name' => 'Tent assembly crew', 'description' => 'Two-person crew to assemble and dismantle tents at your venue.', 'amount' => 1500, 'per' => 'order', 'required' => false]],
                'damage_policy' => $DMG(['repairable' => ['enabled' => true, 'pct' => 40]]), 'locations' => [$loc('L1', 'Makati Warehouse', '401 Chino Roces Ave., Makati City', 'Carlo Mendoza', '0920 555 0404')]]],
            ['s4', 'u5', 'Elegant Occasion Rentals', '💍', '#B45309', 'Premium chiavari chairs, gold-and-white backdrops and lounge furniture for weddings and formal receptions across BGC and the south.', $A['liza'], '0921 555 0505', 'events@elegantoccasion.ph', 'Tue–Sun, 10:00 AM – 7:00 PM', 'both', 1200, ['bank', 'debit'], ['depositType' => 'fixed', 'depositValue' => 3000, 'cancelDays' => 7, 'cancellation' => 'Free cancellation up to 7 days before the event. Later cancellations forfeit the down payment and 20% of payments made.'], 4.9, 'approved', -240, [
                'type' => 'organization', 'payment_plans' => $plans(['downPct' => 30, 'downDays' => 14], 7, ['downPct' => 20, 'downDays' => 14, 'minMonths' => 2, 'maxInstallments' => 6]),
                'time_settings' => ['open' => '06:00', 'close' => '23:00', 'prepHours' => 1, 'recoveryHours' => 1],
                'delivery_zones' => [['id' => 'z1', 'name' => 'BGC / Taguig', 'fee' => 500], ['id' => 'z2', 'name' => 'Makati / Pasig', 'fee' => 700]],
                'services' => [['id' => 'sv1', 'name' => 'Setup and installation', 'description' => 'Styling crew sets up furniture, backdrop and décor at each venue.', 'amount' => 300, 'per' => 'location', 'required' => true], ['id' => 'sv2', 'name' => 'Dismantling and packing', 'description' => 'Crew dismantles and packs everything after the event.', 'amount' => 200, 'per' => 'location', 'required' => true], ['id' => 'sv3', 'name' => 'On-site coordinator', 'description' => 'A coordinator stays during the event for adjustments.', 'amount' => 1500, 'per' => 'order', 'required' => false]],
                'damage_policy' => $DMG(['categories' => ['decorations' => ['repairable' => ['enabled' => true, 'pct' => 40], 'nonRepairable' => ['enabled' => true, 'pct' => 80], 'lost' => ['enabled' => true, 'brand_new' => 100, 'good_as_new' => 100, 'used' => 80]]]]),
                'locations' => [$loc('L1', 'Main Warehouse', 'Lot 4, 32nd St., Bonifacio Global City, Taguig', 'Rico Santos', '0917 800 1515'), $loc('L2', 'Branch A – Pasig', 'Ortigas Ave. Ext., Pasig City', 'Joy Lim', '0917 800 1616'), $loc('L3', 'Branch B – Makati', 'Pasong Tamo Ext., Makati City', 'Liza Navarro', '0921 555 0505')],
                'company' => ['legalName' => 'Elegant Occasion Rentals Inc.', 'companyType' => 'corporation', 'natureOfBusiness' => 'Event furniture and décor rental', 'branches' => 3, 'representative' => 'Liza Navarro', 'position' => 'President']]],
            ['s5', 'u6', 'Party Essentials Shop', '🥳', '#2563EB', 'Affordable kiddie party must-haves in Manila: small chairs, kiddie tables, bubble machines, photo booth props and more.', $A['ramon'], '0922 555 0606', 'partyessentials@demo.ph', 'Mon–Sun, 8:00 AM – 8:00 PM', 'both', 350, ['cod', 'bank'], ['depositType' => 'fixed', 'depositValue' => 500, 'cancelDays' => 1], 4.4, 'approved', -220, [
                'type' => 'individual', 'payment_plans' => $plans(['downPct' => 50, 'downDays' => 5], 3),
                'time_settings' => ['open' => '08:00', 'close' => '21:00', 'prepHours' => 1, 'recoveryHours' => 1], 'delivery_zones' => [['id' => 'z1', 'name' => 'Manila', 'fee' => 250]],
                'services' => [], 'damage_policy' => $DMG(), 'locations' => [$loc('L1', 'Sta. Cruz Shop', '1530 Rizal Ave., Sta. Cruz, Manila', 'Ramon Bautista', '0922 555 0606')]]],
            ['s6', 'u8', "Bea's Balloon Corner", '🎀', '#DB2777', 'Custom balloon garlands, arches and pastel party décor in Caloocan and nearby cities.', $A['bea'], '0924 555 0808', 'bea.balloons@demo.ph', 'Mon–Sat, 9:00 AM – 5:00 PM', 'both', 400, ['cod', 'bank'], ['depositType' => 'fixed', 'depositValue' => 500, 'cancelDays' => 3], 0, 'pending', -2, [
                'type' => 'individual', 'payment_plans' => $plans(['downPct' => 40, 'downDays' => 7], 3),
                'time_settings' => ['open' => '09:00', 'close' => '20:00', 'prepHours' => 1, 'recoveryHours' => 1], 'delivery_zones' => [], 'services' => [], 'damage_policy' => $DMG(),
                'locations' => [$loc('L1', 'Home studio', '19 Rizal Ave. Ext., Grace Park, Caloocan', 'Bea Mendoza', '0924 555 0808')]]],
            ['s7', 'u9', 'Aquino Events Corp.', '🏢', '#0EA5E9', 'Corporate event production company with two warehouses, offering staging, tents, lights and furniture for company events in the south of Metro Manila.', $A['aquino'], '(02) 8820 1234', 'partners@aquinoevents.ph', 'Mon–Sat, 8:00 AM – 6:00 PM', 'both', 900, ['bank'], ['depositType' => 'fixed', 'depositValue' => 2000, 'cancelDays' => 7], 0, 'pending', -1, [
                'type' => 'organization', 'payment_plans' => $plans(['downPct' => 30, 'downDays' => 14], 7),
                'time_settings' => ['open' => '07:00', 'close' => '22:00', 'prepHours' => 2, 'recoveryHours' => 2], 'delivery_zones' => [], 'services' => [], 'damage_policy' => $DMG(),
                'locations' => [$loc('L1', 'Parañaque Warehouse', '120 Dr. A. Santos Ave., Parañaque', 'Kristine Aquino', '0925 555 0909'), $loc('L2', 'Las Piñas Warehouse', 'Alabang–Zapote Rd., Las Piñas', 'Ben Aquino', '0925 555 0919')],
                'company' => ['legalName' => 'Aquino Events Corporation', 'companyType' => 'corporation', 'natureOfBusiness' => 'Corporate event production and equipment rental', 'branches' => 2, 'representative' => 'Kristine Aquino', 'position' => 'Operations Director']]],
        ];
        $docs = fn (array $keys) => array_combine($keys, array_map(fn ($k) => ['name' => str_replace('_', '-', $k).'.pdf', 'size' => 184000 + strlen($k) * 731, 'uploadedAt' => $this->ts(-2)->toIso8601String()], $keys));
        foreach ($rows as [$key, $owner, $name, $logo, $color, $desc, $addr, $phone, $email, $hours, $ful, $fee, $methods, $pol, $rating, $status, $d, $v4]) {
            $a = $this->addr($addr);
            $ownerUser = User::find($this->U[$owner]);
            $company = $v4['company'] ?? null;
            $application = $v4['type'] === 'organization'
                ? ['type' => 'organization', 'submittedAt' => $this->ts($d)->toIso8601String(),
                    'company' => ['email' => $email, 'phone' => $phone, 'address' => $a['address'], 'description' => $desc] + $company,
                    'representative' => ['name' => $company['representative'], 'position' => $company['position'], 'email' => $email, 'phone' => $phone, 'idType' => 'Philippine National ID'],
                    'docs' => $docs(['sec_certificate', 'bir_cor', 'mayors_permit', 'barangay_clearance', 'rep_id', 'proof_of_authority', 'board_resolution', 'establishment_photos']),
                    'payout' => ['method' => 'bank', 'provider' => 'BDO', 'accountName' => $company['legalName'], 'accountNumber' => '0012-'.(3000 + strlen($key) * 111).'-7788', 'financeContact' => $company['representative']]]
                : ['type' => 'individual', 'submittedAt' => $this->ts($d)->toIso8601String(),
                    'personal' => ['fullName' => $ownerUser->name, 'birthDate' => '1990-05-14', 'address' => $a['address'], 'phone' => $phone, 'email' => $email],
                    'identity' => ['idType' => 'Philippine National ID', 'idNumber' => 'PSN-'.(4000 + strlen($key) * 37).'-1188'],
                    'business' => ['shopName' => $name, 'address' => $a['address'], 'productTypes' => $desc, 'phone' => $phone],
                    'docs' => $docs($key === 's6' ? ['gov_id', 'selfie_id', 'barangay_clearance'] : ['gov_id', 'selfie_id', 'dti_registration', 'mayors_permit']),
                    'payout' => ['method' => 'ewallet', 'provider' => 'GCash', 'accountName' => $ownerUser->name, 'accountNumber' => '0917-555-'.(1000 + strlen($key) * 13)]];
            $s = Supplier::create(array_merge($a, [
                'owner_id' => $ownerUser->id, 'name' => $name, 'logo' => $logo, 'color' => $color, 'description' => $desc, 'city' => Locations::name($a['city_code']),
                'phone' => $phone, 'email' => $email, 'contact_person' => $ownerUser->name, 'hours' => $hours, 'fulfillment' => $ful, 'delivery_fee' => $fee,
                'methods' => $methods, 'arrangements' => [], 'policy' => array_merge($base, $pol), 'rating' => $rating, 'status' => $status,
                'applied_at' => $this->ts($d), 'approved_at' => $status === 'approved' ? $this->ts($d + 2) : null,
                'type' => $v4['type'], 'payment_plans' => $v4['payment_plans'], 'time_settings' => $v4['time_settings'],
                'delivery_zones' => $v4['delivery_zones'], 'services' => $v4['services'], 'damage_policy' => $v4['damage_policy'],
                'company' => $company ? ['legalName' => $company['legalName'], 'companyType' => $company['companyType']] : null, 'application' => $application,
            ]));
            $this->S[$key] = $s->id;
            foreach ($v4['locations'] as $l) {
                $this->L[$key.'|'.$l['id']] = InventoryLocation::create(['supplier_id' => $s->id, 'name' => $l['name'], 'address' => $l['address'], 'contact' => $l['contact'], 'phone' => $l['phone']])->id;
            }
        }
        // Elegant Occasion's employees (organization)
        foreach ([['u10', 'cashier'], ['u15', 'inventory'], ['u16', 'staff']] as [$u, $role]) {
            ShopStaff::create(['supplier_id' => $this->S['s4'], 'user_id' => $this->U[$u], 'role' => $role, 'added_by' => $this->U['u5']]);
        }
    }

    protected function products(): void
    {
        $P = fn ($key, $sup, $name, $cat, $stock, $tiers, $x = []) => [$key, $sup, array_merge([
            'supplier_id' => $this->S[$sup], 'name' => $name, 'category_id' => $cat, 'total_stock' => $stock, 'damaged' => 0, 'missing' => 0,
            'tiers' => array_map(fn ($t) => ['min' => $t[0], 'max' => $t[1], 'price' => $t[2]], $tiers),
            'unit' => 'piece / day', 'colors' => [], 'themes' => [], 'events' => [], 'images' => [],
            'status' => 'active', 'rating' => 4.6, 'rent_count' => 0, 'delivery' => true, 'pickup' => true, 'description' => '',
        ], $x)];
        $list = [
            $P('p1', 's1', 'White Monoblock Chair', 'chairs', 100, [[1, 10, 15], [11, 30, 13], [31, 50, 11], [51, null, 10]], ['colors' => ['White'], 'themes' => ['Classic', 'Minimalist'], 'events' => ['Birthday', 'Christening', 'Reunion', 'Corporate'], 'rating' => 4.7, 'rent_count' => 412, 'description' => 'Sturdy, stackable white monoblock chairs. Cleaned and sanitized after every rental. Ideal for backyard parties, reunions and community events.', 'damaged' => 2]),
            $P('p2', 's1', 'Round Table (8-Seater)', 'tables', 20, [[1, 9, 150], [10, null, 130]], ['colors' => ['White'], 'themes' => ['Classic'], 'events' => ['Birthday', 'Wedding', 'Reunion', 'Christening'], 'rating' => 4.8, 'rent_count' => 188, 'description' => '60-inch round table that comfortably seats 8 guests. Pair with our round tablecloths for a polished look.']),
            $P('p3', 's1', 'Rectangular Table (6 ft)', 'tables', 15, [[1, null, 180]], ['colors' => ['White'], 'themes' => ['Classic'], 'events' => ['Birthday', 'Corporate', 'Reunion'], 'rating' => 4.6, 'rent_count' => 96, 'description' => 'Foldable 6-foot rectangular table for buffets, gift tables and registration areas.']),
            $P('p4', 's1', '10×10 ft Canopy Tent (White)', 'tents', 6, [[1, null, 1200]], ['colors' => ['White'], 'themes' => ['Outdoor', 'Garden'], 'events' => ['Birthday', 'Reunion', 'Christening'], 'rating' => 4.7, 'rent_count' => 64, 'unit' => 'unit / day', 'description' => 'Pop-up canopy with side walls, sandbags and setup included. Shelters about 20–25 seated guests.']),
            $P('p5', 's1', 'Pink Balloon Garland Set', 'decorations', 8, [[1, null, 850]], ['colors' => ['Pink', 'White'], 'themes' => ['Pastel', 'Princess'], 'events' => ['Birthday', 'Debut', 'Baby Shower'], 'rating' => 4.9, 'rent_count' => 120, 'unit' => 'set / day', 'description' => '4-meter pastel pink and white balloon garland with frame stand. Reusable frame, fresh balloons per rental.']),
            $P('p6', 's1', 'Party Speaker 1000W with Mic', 'sound', 4, [[1, null, 1500]], ['colors' => ['Black'], 'themes' => ['Modern'], 'events' => ['Birthday', 'Reunion', 'Corporate', 'Debut'], 'rating' => 4.5, 'rent_count' => 77, 'unit' => 'unit / day', 'description' => 'Bluetooth party speaker with 2 wireless microphones and stand. Good for up to 80 guests.']),
            $P('p7', 's1', 'Kids Party Games Kit', 'games', 5, [[1, null, 600]], ['colors' => ['Multicolor'], 'themes' => ['Kids', 'Carnival'], 'events' => ['Birthday'], 'rating' => 4.6, 'rent_count' => 54, 'unit' => 'kit / day', 'description' => 'Bring-me game cards, sack race bags, pabitin frame and prize bowl. Host guide included.']),
            $P('p8', 's1', 'White Round Tablecloth', 'tableware', 40, [[1, 19, 60], [20, null, 50]], ['colors' => ['White'], 'themes' => ['Classic', 'Elegant'], 'events' => ['Birthday', 'Wedding', 'Christening'], 'rating' => 4.5, 'rent_count' => 140, 'description' => 'Floor-length polyester tablecloth for 60-inch round tables. Laundered and pressed.', 'missing' => 1]),
            $P('p9', 's2', 'Tiffany Chair (Gold)', 'chairs', 120, [[1, 50, 45], [51, null, 40]], ['colors' => ['Gold'], 'themes' => ['Elegant', 'Glam'], 'events' => ['Wedding', 'Debut', 'Anniversary'], 'rating' => 4.8, 'rent_count' => 356, 'description' => 'Classic gold Tiffany chairs with ivory seat cushions. Delivered and arranged by our crew.']),
            $P('p10', 's2', 'Tiffany Chair (Clear Ghost)', 'chairs', 60, [[1, null, 55]], ['colors' => ['Clear', 'White'], 'themes' => ['Modern', 'Minimalist'], 'events' => ['Wedding', 'Debut', 'Corporate'], 'rating' => 4.7, 'rent_count' => 141, 'description' => 'Transparent acrylic Tiffany chairs for a modern, airy setup.']),
            $P('p11', 's2', 'LED Fairy Light Curtain', 'lighting', 15, [[1, null, 450]], ['colors' => ['Warm White', 'Gold'], 'themes' => ['Elegant', 'Romantic', 'Garden'], 'events' => ['Wedding', 'Debut', 'Birthday'], 'rating' => 4.7, 'rent_count' => 98, 'unit' => 'panel / day', 'description' => '3m × 3m warm-white curtain light. Perfect behind backdrops or along stage edges.']),
            $P('p12', 's2', 'Moving Head Party Lights (Pair)', 'lighting', 6, [[1, null, 1800]], ['colors' => ['Multicolor'], 'themes' => ['Disco', 'Modern'], 'events' => ['Debut', 'Birthday', 'Corporate'], 'rating' => 4.5, 'rent_count' => 40, 'unit' => 'pair / day', 'description' => 'DMX moving head beam lights with controller. Operator available on request.']),
            $P('p13', 's2', 'Floral Arch Backdrop (Pink & White)', 'backdrops', 3, [[1, null, 2500]], ['colors' => ['Pink', 'White'], 'themes' => ['Romantic', 'Pastel', 'Garden'], 'events' => ['Debut', 'Wedding', 'Birthday'], 'rating' => 4.9, 'rent_count' => 37, 'unit' => 'set / day', 'description' => '2.4m round arch with silk florals in blush pink and white. Includes setup.']),
            $P('p14', 's2', 'Pastel Pink Centerpiece Set', 'decorations', 25, [[1, null, 180]], ['colors' => ['Pink'], 'themes' => ['Pastel', 'Romantic'], 'events' => ['Debut', 'Birthday', 'Baby Shower'], 'rating' => 4.6, 'rent_count' => 75, 'unit' => 'set / day', 'description' => 'Glass vase with silk peonies and votive candles, one set per table.']),
            $P('p15', 's3', 'White Monoblock Chair', 'chairs', 200, [[1, 50, 12], [51, null, 9]], ['colors' => ['White'], 'themes' => ['Classic'], 'events' => ['Corporate', 'Reunion', 'Birthday'], 'rating' => 4.4, 'rent_count' => 520, 'description' => 'Bulk monoblock chairs for large events. Warehouse pickup only.', 'delivery' => false, 'written_off' => 2, 'missing' => 1]),
            $P('p16', 's3', 'Folding Banquet Table', 'tables', 30, [[1, null, 200]], ['colors' => ['White'], 'themes' => ['Classic'], 'events' => ['Corporate', 'Reunion'], 'rating' => 4.5, 'rent_count' => 210, 'description' => '8-foot banquet table, seats 8–10. Pickup only.', 'delivery' => false]),
            $P('p17', 's3', '20×20 ft Party Tent', 'tents', 4, [[1, null, 3500]], ['colors' => ['White'], 'themes' => ['Outdoor'], 'events' => ['Corporate', 'Reunion', 'Wedding', 'Birthday'], 'rating' => 4.6, 'rent_count' => 52, 'unit' => 'unit / day', 'description' => 'Large frame tent for up to 60 seated guests. Assembly crew available for an extra fee.', 'delivery' => false]),
            $P('p18', 's3', 'Chafing Dish Set', 'catering', 20, [[1, null, 250]], ['colors' => ['Silver'], 'themes' => ['Classic'], 'events' => ['Corporate', 'Wedding', 'Reunion', 'Birthday'], 'rating' => 4.5, 'rent_count' => 160, 'unit' => 'set / day', 'description' => 'Stainless chafing dish with water pan, food pan and fuel holder.', 'delivery' => false]),
            $P('p19', 's3', 'Line Array Sound System', 'sound', 2, [[1, null, 6500]], ['colors' => ['Black'], 'themes' => ['Modern'], 'events' => ['Corporate', 'Wedding', 'Debut'], 'rating' => 4.8, 'rent_count' => 33, 'unit' => 'system / day', 'description' => 'Professional line array with mixer, 4 wireless mics and technician for up to 300 guests.', 'delivery' => false]),
            $P('p20', 's3', 'Drink Station with Water Dispenser', 'catering', 6, [[1, null, 400]], ['colors' => ['Silver'], 'themes' => ['Classic'], 'events' => ['Corporate', 'Reunion', 'Birthday'], 'rating' => 4.3, 'rent_count' => 48, 'unit' => 'set / day', 'description' => 'Two 5-gallon dispensers, juice jars and a skirted serving table.', 'delivery' => false]),
            $P('p21', 's4', 'Gold Chiavari Chair', 'chairs', 150, [[1, 99, 65], [100, null, 55]], ['colors' => ['Gold'], 'themes' => ['Elegant', 'Glam', 'Classic'], 'events' => ['Wedding', 'Debut', 'Anniversary'], 'rating' => 4.9, 'rent_count' => 290, 'description' => 'Resin chiavari chairs in champagne gold with white cushions.']),
            $P('p22', 's4', 'Elegant White & Gold Backdrop', 'backdrops', 4, [[1, null, 4500]], ['colors' => ['White', 'Gold'], 'themes' => ['Elegant', 'Glam'], 'events' => ['Wedding', 'Debut', 'Anniversary'], 'rating' => 4.9, 'rent_count' => 46, 'unit' => 'set / day', 'description' => '3m × 2.4m panel backdrop with draping, gold frame accents and floral corners.']),
            $P('p23', 's4', 'Crystal Centerpiece', 'decorations', 30, [[1, null, 350]], ['colors' => ['White', 'Gold', 'Clear'], 'themes' => ['Elegant', 'Glam'], 'events' => ['Wedding', 'Anniversary', 'Debut'], 'rating' => 4.8, 'rent_count' => 112, 'unit' => 'piece / day', 'description' => 'Crystal candelabra with white roses. One per guest table.']),
            $P('p24', 's4', 'Satin Tablecloth (Champagne)', 'tableware', 60, [[1, null, 90]], ['colors' => ['Gold', 'Champagne'], 'themes' => ['Elegant'], 'events' => ['Wedding', 'Debut', 'Anniversary'], 'rating' => 4.7, 'rent_count' => 150, 'description' => 'Floor-length satin cloth for round tables, champagne gold.']),
            $P('p25', 's4', 'Lounge Sofa Set (White)', 'furniture', 5, [[1, null, 2800]], ['colors' => ['White'], 'themes' => ['Elegant', 'Modern'], 'events' => ['Wedding', 'Corporate', 'Debut'], 'rating' => 4.8, 'rent_count' => 29, 'unit' => 'set / day', 'description' => '3-seater sofa, 2 armchairs and acrylic coffee table for VIP lounges.']),
            $P('p26', 's4', 'LED Uplighting (Set of 8)', 'lighting', 6, [[1, null, 1600]], ['colors' => ['Warm White', 'Gold', 'Multicolor'], 'themes' => ['Elegant', 'Romantic'], 'events' => ['Wedding', 'Debut', 'Corporate'], 'rating' => 4.7, 'rent_count' => 58, 'unit' => 'set / day', 'description' => 'Battery uplights with remote color control. Wash walls in any color.']),
            $P('p27', 's5', 'Kids Monoblock Chair (Blue)', 'chairs', 80, [[1, null, 10]], ['colors' => ['Blue'], 'themes' => ['Kids'], 'events' => ['Birthday', 'Christening'], 'rating' => 4.4, 'rent_count' => 260, 'description' => 'Small chairs sized for kids 3–10 years old.']),
            $P('p28', 's5', 'Kiddie Table', 'tables', 20, [[1, null, 80]], ['colors' => ['Blue', 'Pink', 'Multicolor'], 'themes' => ['Kids'], 'events' => ['Birthday', 'Christening'], 'rating' => 4.4, 'rent_count' => 140, 'description' => 'Low rectangular table for 6 kids.']),
            $P('p29', 's5', 'Photo Booth Frame & Props', 'other', 5, [[1, null, 700]], ['colors' => ['Multicolor', 'Gold'], 'themes' => ['Fun', 'Kids', 'Carnival'], 'events' => ['Birthday', 'Debut', 'Corporate', 'Wedding'], 'rating' => 4.6, 'rent_count' => 66, 'unit' => 'set / day', 'description' => 'Giant frame, 30 props and a ring light. Fun for all ages.']),
            $P('p30', 's5', 'Bubble Machine', 'games', 6, [[1, null, 500]], ['colors' => ['Multicolor'], 'themes' => ['Kids', 'Fun'], 'events' => ['Birthday', 'Christening'], 'rating' => 4.5, 'rent_count' => 81, 'unit' => 'unit / day', 'description' => 'Automatic bubble machine with 2 liters of bubble solution.']),
            $P('p31', 's5', 'Plates, Glasses & Cutlery (10 pax)', 'tableware', 30, [[1, null, 120]], ['colors' => ['White'], 'themes' => ['Classic'], 'events' => ['Birthday', 'Reunion', 'Christening'], 'rating' => 4.3, 'rent_count' => 97, 'unit' => 'set / day', 'description' => 'Ceramic plates, glasses, spoons and forks for 10 guests.']),
            $P('p32', 's5', 'Popcorn Machine', 'catering', 3, [[1, null, 900]], ['colors' => ['Red'], 'themes' => ['Carnival', 'Kids'], 'events' => ['Birthday', 'Corporate'], 'rating' => 4.6, 'rent_count' => 44, 'unit' => 'unit / day', 'description' => 'Cart-style popcorn machine with kernels for 50 servings.']),
            $P('p33', 's4', 'Wedding Decoration Set (Floral)', 'decorations', 100, [[1, 39, 250], [40, null, 220]], ['colors' => ['White', 'Gold'], 'themes' => ['Elegant', 'Romantic', 'Garden'], 'events' => ['Wedding', 'Debut', 'Anniversary'], 'rating' => 4.8, 'rent_count' => 64, 'unit' => 'set / day', 'description' => 'Aisle and table floral decoration set (pew bows, flower stands and garlands). Stored across our three warehouses.']),
        ];
        // v4: replacement value & condition (damage compensation basis), optional hour limits, stock per location
        $VAL = ['p1' => [500, 'good_as_new'], 'p2' => [3500, 'good_as_new'], 'p3' => [3000, 'used'], 'p4' => [15000, 'good_as_new'], 'p5' => [2500, 'brand_new'], 'p6' => [18000, 'good_as_new'], 'p7' => [2500, 'used'], 'p8' => [600, 'used'], 'p9' => [3500, 'good_as_new'], 'p10' => [4000, 'brand_new'], 'p11' => [2500, 'good_as_new'], 'p12' => [35000, 'good_as_new'], 'p13' => [12000, 'good_as_new'], 'p14' => [1200, 'used'], 'p15' => [450, 'used'], 'p16' => [3200, 'used'], 'p17' => [45000, 'good_as_new'], 'p18' => [2800, 'used'], 'p19' => [120000, 'good_as_new'], 'p20' => [3500, 'used'], 'p21' => [4500, 'good_as_new'], 'p22' => [25000, 'good_as_new'], 'p23' => [3000, 'good_as_new'], 'p24' => [1500, 'used'], 'p25' => [60000, 'good_as_new'], 'p26' => [18000, 'good_as_new'], 'p27' => [300, 'used'], 'p28' => [1500, 'used'], 'p29' => [6000, 'used'], 'p30' => [4500, 'good_as_new'], 'p31' => [1800, 'used'], 'p32' => [10000, 'good_as_new'], 'p33' => [2000, 'used']];
        $HOURS = ['p6' => ['min_hours' => 4], 'p12' => ['min_hours' => 3], 'p19' => ['min_hours' => 4], 'p29' => ['min_hours' => 3, 'max_hours' => 12]];
        $SPLIT = ['p1' => ['L1' => 60, 'L2' => 40], 'p2' => ['L1' => 12, 'L2' => 8], 'p21' => ['L1' => 100, 'L2' => 50], 'p23' => ['L1' => 20, 'L3' => 10], 'p33' => ['L1' => 50, 'L2' => 30, 'L3' => 20]];
        $recent = ['p13' => -3, 'p25' => -5, 'p30' => -6];
        foreach ($list as $i => [$key, $sup, $attrs]) {
            $p = new Product(array_merge($attrs, ['replacement_value' => $VAL[$key][0], 'condition' => $VAL[$key][1], 'min_hours' => 0], $HOURS[$key] ?? []));
            $p->created_at = $this->ts($recent[$key] ?? (-200 + $i * 6));
            $p->save();
            $this->P[$key] = $p->id;
            foreach ($SPLIT[$key] ?? ['L1' => $attrs['total_stock']] as $loc => $qty) {
                LocationStock::create(['inventory_location_id' => $this->L[$sup.'|'.$loc], 'product_id' => $p->id, 'qty' => $qty]);
            }
        }
    }

    protected function holds(): void
    {
        Hold::create(['product_id' => $this->P['p1'], 'type' => 'maintenance', 'qty' => 5, 'start_date' => $this->D(-2), 'end_date' => $this->D(10), 'note' => 'Repainting scuffed chairs']);
        Hold::create(['product_id' => $this->P['p9'], 'type' => 'setaside', 'qty' => 10, 'start_date' => $this->D(0), 'end_date' => null, 'note' => 'Reserved for showroom display']);
        Hold::create(['product_id' => $this->P['p17'], 'type' => 'maintenance', 'qty' => 1, 'start_date' => $this->D(-5), 'end_date' => $this->D(4), 'note' => 'Replacing torn tent panel']);
    }

    protected function packages(): void
    {
        $it = fn (array $pairs) => array_map(fn ($x) => ['product_id' => $this->P[$x[0]], 'qty' => $x[1]], $pairs);
        $rows = [
            ['k1', 's1', 'Birthday Party Package', '🎂', 'Everything for a 50-guest birthday: chairs, round tables with cloths and a pink balloon garland.', [['p1', 50], ['p2', 5], ['p5', 1], ['p8', 5]], 3500, 'Price is per day. Setup within Quezon City included with delivery.', ['Birthday'], -150, null],
            ['k2', 's1', 'Outdoor Party Package', '⛺', 'Two canopy tents, 40 chairs, 4 long tables and a party speaker for garden or street parties.', [['p4', 2], ['p1', 40], ['p3', 4], ['p6', 1]], 5800, 'Price is per day. Requires a flat area of at least 6 × 3 m.', ['Birthday', 'Reunion', 'Christening'], -120, null],
            ['k3', 's4', 'Wedding Reception Package', '💒', 'An elegant white-and-gold reception for 100 guests: chiavari chairs, backdrop, crystal centerpieces, satin cloths and uplighting.', [['p21', 100], ['p22', 1], ['p23', 12], ['p24', 12], ['p26', 1]], 18500, 'Price is per day. Includes styling crew for 4 hours.', ['Wedding', 'Anniversary'], -140, 6],
            ['k4', 's2', 'Debut Party Package', '👑', '80 gold Tiffany chairs, floral arch, fairy-light curtains and pastel centerpieces for a dreamy 18th.', [['p9', 80], ['p13', 1], ['p11', 4], ['p14', 10]], 9900, 'Price is per day. Delivery within Pasig, Mandaluyong and San Juan.', ['Debut'], -110, null],
            ['k5', 's5', "Kids' Party Package", '🧸', '30 kids chairs, 6 kiddie tables, a bubble machine, photo booth props and dinnerware for 30.', [['p27', 30], ['p28', 6], ['p30', 1], ['p29', 1], ['p31', 3]], 2200, 'Price is per day.', ['Birthday', 'Christening'], -100, null],
            ['k6', 's3', 'Corporate Event Package', '🏢', '100 chairs, 12 banquet tables, line array sound and 6 chafing dishes for company events.', [['p15', 100], ['p16', 12], ['p19', 1], ['p18', 6]], 12000, 'Price is per day. Warehouse pickup only.', ['Corporate'], -90, null],
        ];
        foreach ($rows as [$key, $sup, $name, $emoji, $desc, $items, $price, $cond, $events, $d, $minH]) {
            $k = new Package(['supplier_id' => $this->S[$sup], 'name' => $name, 'emoji' => $emoji, 'description' => $desc, 'items' => $it($items), 'price' => $price, 'conditions' => $cond, 'events' => $events, 'images' => [], 'active' => true, 'status' => 'active', 'min_hours' => $minH]);
            $k->created_at = $this->ts($d);
            $k->save();
            $this->K[$key] = $k->id;
        }
    }

    protected function requests(): void
    {
        $days = function (string $start, array $qtys) {
            $o = [];
            foreach ($qtys as $i => $q) {
                $o[add_days($start, $i)] = $q;
            }

            return $o;
        };
        $L = fn ($id, $type, $ref, $d, array $x = []) => ['id' => $id, 'type' => $type, 'ref_id' => $type === 'package' ? $this->K[$ref] : $this->P[$ref], 'days' => $d] + $x;
        $D = fn ($n) => $this->D($n);
        $A = self::ADDR;
        $PLAN = ['down' => 'standard', 'reservation' => 'standard', 'full' => 'full', 'installment' => 'installment'];
        $dest = fn ($id, $label, $address, $zone = '', $time = '') => ['id' => $id, 'label' => $label, 'address' => $address, 'zoneId' => $zone, 'deliveryTime' => $time, 'contactName' => '', 'contactPhone' => ''];
        // v4 extras per request: source location, rental time, delivery destinations, services
        $RQ = [
            'PR-0001' => ['src' => 'L2', 'time' => ['start' => '08:00', 'end' => '20:00'], 'dest' => [$dest('d1', 'Home – garden party', '23 Mabini St., Brgy. Kamuning, Quezon City', 'z1', '07:00')], 'services' => ['sv1']],
            'PR-0002' => ['time' => ['start' => '06:00', 'end' => '11:00']],
            'PR-0003' => ['time' => ['start' => '13:00', 'end' => '18:00'], 'dest' => [$dest('d1', 'Barangay fiesta booth', '7 Sampaguita St., Marikina Heights, Marikina', 'z2', '12:00')]],
            'PR-0004' => ['time' => ['start' => '09:00', 'end' => '17:00'], 'dest' => [$dest('d1', 'Christening reception', '19 Rizal Ave. Ext., Grace Park, Caloocan', 'z3', '08:00')]],
            'PR-0005' => ['time' => ['start' => '14:00', 'end' => '22:00'], 'dest' => [$dest('d1', 'Valle Verde Clubhouse', 'Valle Verde Clubhouse, Pasig City', 'z1', '13:00')], 'services' => ['sv2']],
            'PR-0007' => ['dest' => [$dest('d1', 'Office', 'Sta. Mesa, Manila', 'z1')]],
            'PR-0009' => ['dest' => [$dest('d1', 'Home', '88 Katipunan Ave., Loyola Heights, Quezon City')]],
            'PR-0011' => ['time' => ['start' => '10:00', 'end' => '21:00'], 'dest' => [$dest('d1', 'Home', '7 Sampaguita St., Marikina Heights, Marikina')]],
            'PR-0013' => ['time' => ['start' => '15:00', 'end' => '22:00'], 'dest' => [$dest('d1', 'Home', '33 Aguirre Ave., BF Homes, Parañaque')]],
        ];
        $inspection6 = ['at' => $this->ts(-17)->toIso8601String(), 'by' => 'u4', 'returnDate' => $D(-17), 'confirmed' => true, 'confirmedAt' => $this->ts(-17)->toIso8601String(),
            'items' => [
                ['productId' => 'p15', 'rented' => 80, 'returned' => 79, 'repairable' => 0, 'nonRepairable' => 2, 'lost' => 1, 'condition' => 'used', 'value' => 450, 'pcts' => ['repairable' => 40, 'nonRepairable' => 70, 'lost' => 70], 'amounts' => ['repairable' => 0, 'nonRepairable' => 630, 'lost' => 315], 'total' => 945, 'description' => 'Two chairs with cracked legs (cannot be repaired); one chair was not returned.'],
                ['productId' => 'p16', 'rented' => 10, 'returned' => 10, 'repairable' => 0, 'nonRepairable' => 0, 'lost' => 0, 'condition' => 'used', 'value' => 3200, 'pcts' => ['repairable' => 40, 'nonRepairable' => 70, 'lost' => 70], 'amounts' => ['repairable' => 0, 'nonRepairable' => 0, 'lost' => 0], 'total' => 0, 'description' => ''],
                ['productId' => 'p17', 'rented' => 1, 'returned' => 1, 'repairable' => 0, 'nonRepairable' => 0, 'lost' => 0, 'condition' => 'good_as_new', 'value' => 45000, 'pcts' => ['repairable' => 40, 'nonRepairable' => 70, 'lost' => 100], 'amounts' => ['repairable' => 0, 'nonRepairable' => 0, 'lost' => 0], 'total' => 0, 'description' => ''],
            ], 'notes' => 'Two chairs broken beyond repair; one chair not returned.', 'photos' => [], 'compensation' => 945, 'settlement' => 'deducted', 'settledAt' => $this->ts(-15)->toIso8601String()];
        $rows = [
            ['PR-0001', 'u1', 's1', "Sofia's 7th Birthday", 'Birthday', 6, 5, 7, 'delivery', $A['maria'], 'Please set up the tables in the garden. Gate code 2468.', [$L('l1', 'item', 'p1', $days($D(5), [30, 20, 40])), $L('l2', 'item', 'p2', $days($D(5), [5, 8, 5]))], 'down', 'bank', 'accepted', -6, -4, [['pending', -6, 'u1'], ['accepted', -4, 'u2', 'Confirmed. We will deliver at 8 AM on day one.']], 'Deliver 8 AM day one. Customer will keep chairs on site; quantity changes daily as per schedule.', []],
            ['PR-0002', 'u9', 's1', 'Aquino Family Reunion', 'Reunion', 5, 5, 5, 'pickup', null, '', [$L('l1', 'package', 'k1', $days($D(5), [1]))], 'full', 'debit', 'accepted', -10, -9, [['pending', -10, 'u9'], ['accepted', -9, 'u2']], 'Pickup at 7 AM.', []],
            ['PR-0003', 'u7', 's1', 'Barangay Fiesta Booth', 'Community', 5, 5, 5, 'delivery', $A['jose'], 'Need chairs by 6 AM.', [$L('l1', 'item', 'p1', $days($D(5), [40])), $L('l2', 'item', 'p3', $days($D(5), [3]))], 'full', 'cod', 'pending', -1, null, [['pending', -1, 'u7']], '', []],
            ['PR-0004', 'u8', 's1', "Baby Liam's Christening", 'Christening', 9, 9, 9, 'delivery', $A['bea'], '', [$L('l1', 'item', 'p2', $days($D(9), [6])), $L('l2', 'item', 'p8', $days($D(9), [6]))], 'reservation', 'bank', 'pending', 0, null, [['pending', 0, 'u8']], '', []],
            ['PR-0005', 'u1', 's2', "Camille's Debut", 'Debut', 21, 21, 21, 'delivery', ['Valle Verde Clubhouse', 'Ugong', '137403000'], 'Ingress allowed from 2 PM only.', [$L('l1', 'package', 'k4', $days($D(21), [1])), $L('l2', 'item', 'p12', $days($D(21), [1]))], 'down', 'bank', 'pending', -1, null, [['pending', -1, 'u1']], '', []],
            ['PR-0006', 'u1', 's3', 'Santos Company Outing', 'Corporate', -19, -20, -18, 'pickup', null, '', [$L('l1', 'item', 'p15', $days($D(-20), [80, 80, 60])), $L('l2', 'item', 'p16', $days($D(-20), [10, 10, 8])), $L('l3', 'item', 'p17', $days($D(-20), [1, 1, 1]))], 'down', 'cod', 'completed', -35, -33, [['pending', -35, 'u1'], ['accepted', -33, 'u4'], ['in_progress', -21, 'u4'], ['ready_pickup', -21, 'u4'], ['ongoing', -20, 'u4'], ['return_pending', -18, 'u4'], ['inspection', -17, 'u4'], ['completed', -15, 'u4', 'Settled. 2 chairs broken, 1 missing; charged from deposit.']], 'Picked up by Mr. Rodel Santos.',
                ['fulfillment_log' => ['releaseDate' => $D(-20), 'releaseQty' => '80 chairs, 10 tables, 1 tent', 'receivedBy' => 'Rodel Santos', 'returnDate' => $D(-17), 'returnedQty' => '79 chairs, 10 tables, 1 tent', 'method' => 'pickup'], 'inspection' => $inspection6, 'completed_at' => $D(-15)]],
            ['PR-0007', 'u1', 's5', 'Office Christmas Party', 'Corporate', -30, -30, -30, 'delivery', ['V. Mapa St.', 'Sta. Mesa', '133900000'], '', [$L('l1', 'item', 'p32', $days($D(-30), [2]))], 'full', 'cod', 'declined', -40, null, [['pending', -40, 'u1'], ['declined', -39, 'u6', 'Popcorn machines are already booked for that date. Sorry!']], '', ['decline_reason' => 'Popcorn machines are already booked for that date. Sorry!']],
            ['PR-0008', 'u7', 's4', 'Garcia–Lopez Wedding', 'Wedding', 70, 70, 70, 'delivery', ['Santuario de San Antonio, McKinley Rd.', 'Forbes Park', '137602000'], 'Styling crew should arrive by 1 PM.', [
                $L('l1', 'package', 'k3', $days($D(70), [1]), ['time' => ['start' => '13:00', 'end' => '22:00'], 'destId' => 'd2', 'sourceId' => 'L1']),
                $L('l2', 'item', 'p25', $days($D(70), [1]), ['time' => ['start' => '13:00', 'end' => '22:00'], 'destId' => 'd2', 'sourceId' => 'L1']),
                $L('l3', 'item', 'p33', $days($D(70), [20]), ['time' => ['start' => '07:00', 'end' => '11:00'], 'destId' => 'd1', 'sourceId' => 'L1']),
                $L('l4', 'item', 'p33', $days($D(70), [20]), ['time' => ['start' => '13:00', 'end' => '22:00'], 'destId' => 'd2', 'sourceId' => 'L2']),
            ], 'installment', 'bank', 'accepted', -20, -18, [['pending', -20, 'u7'], ['accepted', -18, 'u5']], 'Installment schedule approved: 3 payments before the wedding. Church décor comes from the Main Warehouse, reception décor from Branch A.',
                ['destinations' => [$dest('d1', 'Church – Wedding Ceremony', 'Santuario de San Antonio, McKinley Rd., Forbes Park, Makati City', 'z2', '06:00'), $dest('d2', 'Reception – The Glass Garden', 'The Glass Garden, Ortigas Ave., Pasig City', 'z2', '11:30')],
                    'allocation' => ['status' => 'approved', 'at' => $this->ts(-18)->toIso8601String(), 'by' => 'u5'], 'installment_split' => [$D(10), $D(40), $D(69)]]],
            ['PR-0009', 'u2', 's5', "Juan's Son's 5th Birthday", 'Birthday', -45, -45, -45, 'delivery', $A['juan'], '', [$L('l1', 'package', 'k5', $days($D(-45), [1]))], 'full', 'cod', 'completed', -55, -54, [['pending', -55, 'u2'], ['accepted', -54, 'u6'], ['out_delivery', -45, 'u6'], ['ongoing', -45, 'u6'], ['return_pending', -44, 'u6'], ['inspection', -44, 'u6'], ['completed', -44, 'u6', 'All items returned in good condition. Deposit refunded.']], '',
                ['fulfillment_log' => ['releaseDate' => $D(-45), 'releaseQty' => 'Full package', 'receivedBy' => 'Juan Dela Cruz', 'returnDate' => $D(-44), 'returnedQty' => 'Full package', 'method' => 'delivery'],
                    'inspection' => ['at' => $this->ts(-44)->toIso8601String(), 'by' => 'u6', 'returnDate' => $D(-44), 'confirmed' => true, 'confirmedAt' => $this->ts(-44)->toIso8601String(), 'items' => [], 'notes' => 'All good.', 'photos' => [], 'compensation' => 0, 'settlement' => 'settled', 'settledAt' => $this->ts(-44)->toIso8601String()], 'completed_at' => $D(-44)]],
            ['PR-0010', 'u9', 's3', 'Aquino Corp. Team Building', 'Corporate', 0, -1, 1, 'pickup', null, '', [$L('l1', 'item', 'p17', $days($D(-1), [2, 2, 2])), $L('l2', 'item', 'p19', $days($D(-1), [1, 1, 1])), $L('l3', 'item', 'p15', $days($D(-1), [60, 120, 60]))], 'down', 'cod', 'ongoing', -12, -11, [['pending', -12, 'u9'], ['accepted', -11, 'u4'], ['in_progress', -2, 'u4'], ['ready_pickup', -2, 'u4'], ['ongoing', -1, 'u4']], '', ['fulfillment_log' => ['releaseDate' => $D(-1), 'releaseQty' => '2 tents, 1 sound system, 120 chairs', 'receivedBy' => 'Kristine Aquino', 'method' => 'pickup']]],
            ['PR-0011', 'u7', 's1', 'Garcia Housewarming', 'Birthday', -3, -3, -2, 'delivery', $A['jose'], '', [$L('l1', 'item', 'p1', $days($D(-3), [25, 25])), $L('l2', 'item', 'p3', $days($D(-3), [3, 3])), $L('l3', 'item', 'p6', $days($D(-3), [1, 1]))], 'full', 'bank', 'return_pending', -14, -13, [['pending', -14, 'u7'], ['accepted', -13, 'u2'], ['in_progress', -4, 'u2'], ['out_delivery', -3, 'u2'], ['ongoing', -3, 'u2'], ['return_pending', -2, 'u2']], 'Pick up items on the morning after the event.', ['fulfillment_log' => ['releaseDate' => $D(-3), 'releaseQty' => '25 chairs, 3 tables, 1 speaker', 'receivedBy' => 'Jose Garcia', 'method' => 'delivery']]],
            ['PR-0012', 'u1', 's5', 'Mini Movie Night', 'Birthday', 2, 2, 2, 'pickup', null, '', [$L('l1', 'item', 'p32', $days($D(2), [1]))], 'full', 'cod', 'cancelled', -8, -7, [['pending', -8, 'u1'], ['accepted', -7, 'u6'], ['cancelled', -5, 'u1', 'Event moved to next month.']], '', ['cancel_reason' => 'Event moved to next month.', 'cancel_free' => true]],
            ['PR-0013', 'u9', 's2', "Kristine's 30th", 'Birthday', 3, 3, 3, 'delivery', $A['kristine'], '', [$L('l1', 'item', 'p10', $days($D(3), [30])), $L('l2', 'item', 'p11', $days($D(3), [2])), $L('l3', 'item', 'p13', $days($D(3), [1]))], 'full', 'bank', 'in_progress', -4, -2, [['pending', -4, 'u9'], ['accepted', -2, 'u3'], ['in_progress', 0, 'u3', 'Preparing items for delivery.']], '', []],
        ];
        foreach ($rows as [$code, $cust, $sup, $name, $type, $ev, $st, $en, $ful, $addr, $instr, $lines, $arr, $method, $status, $created, $accepted, $hist, $notes, $extra]) {
            $c = User::find($this->U[$cust]);
            $x = $RQ[$code] ?? [];
            if (isset($extra['inspection'])) {
                $extra['inspection']['by'] = $this->U[$extra['inspection']['by']];
                foreach ($extra['inspection']['items'] as &$ii) {
                    $ii['productId'] = $this->P[$ii['productId']];
                }
                unset($ii);
            }
            $destinations = $extra['destinations'] ?? ($ful === 'delivery' ? ($x['dest'] ?? [$dest('d1', 'Event venue', '')]) : []);
            $firstLoc = $this->L[$sup.'|L1'];
            $lines = array_map(function ($l) use ($x, $destinations, $sup, $firstLoc) {
                if (isset($x['time'])) {
                    $l['time'] = $x['time'];
                }
                $l['destId'] ??= $destinations ? $destinations[0]['id'] : null;
                $src = $l['sourceId'] ?? ($x['src'] ?? null);
                $l['sourceId'] = $src ? $this->L[$sup.'|'.$src] : $firstLoc;

                return $l;
            }, $lines);
            $split = $extra['installment_split'] ?? null;
            unset($extra['destinations'], $extra['installment_split']);
            if (isset($extra['allocation']['by'])) {
                $extra['allocation']['by'] = $this->U[$extra['allocation']['by']];
            }
            $r = new RentalRequest(array_merge($this->addr($addr), [
                'code' => $code, 'customer_id' => $c->id, 'supplier_id' => $this->S[$sup], 'event_name' => $name, 'event_type' => $type,
                'event_date' => $D($ev), 'start_date' => $D($st), 'end_date' => $D($en), 'fulfillment' => $ful,
                'contact_name' => $c->name, 'contact_phone' => $c->phone, 'instructions' => $instr, 'lines' => $lines,
                'arrangement' => $arr, 'plan' => $PLAN[$arr], 'method' => $method, 'status' => $status, 'supplier_notes' => $notes,
                'destinations' => $destinations, 'services' => $x['services'] ?? [],
                'allocation' => $extra['allocation'] ?? ($status === 'pending' ? ['status' => 'proposed'] : ['status' => 'approved', 'at' => $accepted !== null ? $D($accepted) : null]),
                'accepted_at' => $accepted !== null ? $D($accepted) : null,
            ], array_diff_key($extra, ['allocation' => 1])));
            $r->setRelation('supplier', Supplier::find($this->S[$sup]));
            $r->pricing = Pricing::quoteRequest($r);
            if ($split) {
                $items = Finance::suggestInstallments($r->pricing['finalBalance'], count($split), $split[0], end($split));
                foreach ($items as $i => &$it) {
                    $it['due'] = $split[$i];
                }
                unset($it);
                $r->installment_plan = ['status' => 'approved', 'items' => $items, 'proposedAt' => $this->ts(-20)->toIso8601String(), 'reviewedAt' => $this->ts(-18)->toIso8601String(), 'reviewedBy' => $this->U['u5']];
            }
            $r->created_at = $this->ts($created, ['PR-0003' => 15, 'PR-0004' => 9, 'PR-0005' => 11][$code] ?? 10);
            $r->save();
            foreach ($hist as $h) {
                RequestEvent::create(['rental_request_id' => $r->id, 'status' => $h[0], 'user_id' => $this->U[$h[2]], 'note' => $h[3] ?? null, 'created_at' => $this->ts($h[1])]);
            }
            $this->R[$code] = $r->id;
        }
        \App\Domain\BookingDays::syncAll();
    }

    /** Payments: only "verified" ones count. Stage amounts come from each request's payment schedule. */
    protected function payments(): void
    {
        $pay = function ($code, $req, $type, $method, $amount, $n, $status, $ref, $extra = []) {
            $r = RentalRequest::with('supplier')->findOrFail($this->R[$req]);
            if (isset($extra['stage'])) {
                $amount = collect(Finance::schedule($r))->firstWhere('key', $extra['stage'])['amount'] ?? 0;
                unset($extra['stage']);
            }
            // verified by the shop's cashier if it has one, else the supplier owner
            $cashier = ShopStaff::where('supplier_id', $r->supplier_id)->where('role', 'cashier')->value('user_id');
            Payment::create(array_merge([
                'code' => $code, 'rental_request_id' => $r->id, 'type' => $type, 'method' => $method, 'amount' => $amount ?? 0,
                'date' => $this->D($n), 'submitted_at' => $this->ts($n, 13), 'reference' => $ref, 'status' => $status, 'recorded_by' => 'customer',
            ], $status !== 'pending' ? ['verified_by' => $cashier ?: $r->supplier->owner_id, 'verified_at' => $this->ts($n, 16)] : [],
                $status === 'verified' ? ['receipt_no' => 'OR-'.substr($code, 4)] : [], $extra));
        };
        $st = fn ($k) => ['stage' => $k];
        $pay('TXN-10002', 'PR-0001', 'down', 'bank', null, -3, 'verified', 'BDO-884377', $st('down'));
        $pay('TXN-10003', 'PR-0001', 'balance', 'bank', 1000, 0, 'pending', 'BPI-551093', ['proof_name' => 'bpi-transfer-receipt.jpg', 'notes' => 'Partial final balance payment']);
        $pay('TXN-10004', 'PR-0002', 'full', 'debit', null, -9, 'verified', 'DC-7781-0021', $st('full'));
        $pay('TXN-10005', 'PR-0002', 'deposit', 'debit', 1000, -9, 'verified', 'DC-7781-0022');
        $pay('TXN-10024', 'PR-0006', 'down', 'cash', null, -30, 'verified', 'CASH-0931', $st('down'));
        $pay('TXN-10007', 'PR-0006', 'balance', 'cash', null, -20, 'verified', 'CASH-0957', $st('bal'));
        $pay('TXN-10008', 'PR-0006', 'deposit', 'cash', 2000, -20, 'verified', 'CASH-0958');
        $pay('TXN-10009', 'PR-0006', 'deposit_refund', 'cash', 1055, -15, 'verified', 'RF-0031', ['notes' => '₱945 damage compensation deducted from ₱2,000 deposit']);
        $pay('TXN-10023', 'PR-0008', 'down', 'bank', null, -8, 'verified', 'UB-220377', $st('down'));
        $pay('TXN-10011', 'PR-0008', 'installment', 'bank', 6000, -6, 'rejected', 'UB-22O1A9', ['proof_name' => 'screenshot.png', 'reject_reason' => 'Reference number not found in bank statement. Please resubmit a clear receipt.']);
        $pay('TXN-10012', 'PR-0009', 'full', 'cash', null, -45, 'verified', 'CASH-0810', $st('full'));
        $pay('TXN-10013', 'PR-0009', 'deposit', 'cash', 500, -45, 'verified', 'CASH-0811');
        $pay('TXN-10014', 'PR-0009', 'deposit_refund', 'cash', 500, -44, 'verified', 'RF-0022');
        $pay('TXN-10025', 'PR-0010', 'down', 'bank', null, -9, 'verified', 'MBTC-11950', $st('down'));
        $pay('TXN-10022', 'PR-0010', 'balance', 'cash', null, -1, 'verified', 'CASH-0998', $st('bal'));
        $pay('TXN-10016', 'PR-0010', 'deposit', 'cash', 2000, -1, 'verified', 'CASH-0999');
        $pay('TXN-10017', 'PR-0011', 'full', 'bank', null, -12, 'verified', 'GCASH-BANK-77810', $st('full'));
        $pay('TXN-10018', 'PR-0011', 'deposit', 'bank', 1000, -12, 'verified', 'GCASH-BANK-77811');
        $pay('TXN-10019', 'PR-0013', 'full', 'bank', null, -1, 'pending', 'BDO-901233', $st('full') + ['proof_name' => 'bdo-online-receipt.png']);
        $pay('TXN-10020', 'PR-0012', 'full', 'cash', null, -7, 'verified', 'CASH-0977', $st('full'));
        $pay('TXN-10021', 'PR-0012', 'refund', 'cash', null, -5, 'verified', 'RF-0035', $st('full') + ['notes' => 'Cancelled within free cancellation window']);
    }

    /** Platform payouts (Xendit) for the completed rentals; older ones already sent. */
    protected function payouts(): void
    {
        $accounts = [
            's1' => ['gcash', null, 'Happy Moments Party Rentals', '0918 555 0202'],
            's2' => ['bank', 'BDO', 'Celebration Hub Rentals', '0012 3456 7890'],
            's3' => ['bank', 'BPI', 'Dream Events Supplies', '3141 5926 53'],
        ];
        foreach ($accounts as $k => [$m, $bank, $name, $no]) {
            Supplier::whereKey($this->S[$k])->update(['payout_method' => $m, 'payout_bank' => $bank, 'payout_account_name' => $name, 'payout_account_no' => $no]);
        }
        foreach (RentalRequest::where('status', 'completed')->with(['payments', 'supplier'])->get() as $r) {
            $b = \App\Domain\Payouts::breakdown($r);
            if ($b['net'] <= 0) {
                continue;
            }
            $done = Carbon::parse($r->completed_at);
            $sent = $done->lt(Carbon::parse($this->D(-20)));
            \App\Models\Payout::create([
                'code' => \App\Domain\Payouts::nextCode(), 'supplier_id' => $r->supplier_id, 'rental_request_id' => $r->id,
                'income' => $b['income'], 'commission_pct' => $b['pct'], 'commission' => $b['commission'], 'compensation' => $b['compensation'], 'net' => $b['net'],
                'delivery' => $b['delivery'], 'voucher' => $b['voucher'],
                'status' => $sent ? 'paid' : 'pending', 'created_at' => $done->copy()->setTime(18, 0), 'updated_at' => $done->copy()->setTime(18, 0),
            ] + ($sent ? ['method' => $r->supplier->payout_method ?: 'bank', 'reference' => 'GC'.str_pad((string) ($r->id * 7919), 8, '0', STR_PAD_LEFT), 'paid_at' => $done->copy()->addDays(2)->setTime(10, 0), 'paid_by' => $this->U['u12']] : []));
        }
    }

    protected function reviewsAndComplaints(): void
    {
        $reviews = [
            ['s1', 'p1', 'u9', 5, 'Chairs were spotless and delivered early. Super bait ng crew!', -30],
            ['s1', 'p5', 'u1', 5, 'The pink garland made the whole party. My daughter loved it.', -60],
            ['s1', 'p6', 'u7', 4, 'Speaker was loud and clear, one mic had a weak battery.', -25],
            ['s2', 'p9', 'u9', 5, 'Gold Tiffany chairs looked so elegant for our debut.', -50],
            ['s3', 'p17', 'u1', 4, 'Big tent, easy pickup. Wish they offered delivery.', -14],
            ['s4', 'p22', 'u7', 5, 'Backdrop was exactly like the photos. Worth it!', -70],
            ['s5', 'p30', 'u2', 4, 'Kids had so much fun with the bubble machine.', -43],
        ];
        foreach ($reviews as [$s, $p, $c, $rating, $comment, $d]) {
            $rv = new Review(['supplier_id' => $this->S[$s], 'product_id' => $this->P[$p], 'customer_id' => $this->U[$c], 'rating' => $rating, 'comment' => $comment]);
            $rv->created_at = $this->ts($d);
            $rv->save();
        }
        // Ratings stay as seeded (reference seed.js); real reviews recalculate them from then on.
        $complaints = [
            ['CMP-001', 'u7', 'Payment rejected but money was deducted', 's4', 'PR-0008', 'My bank transfer for the wedding installment was rejected but the amount left my account. Please help check.', 'open', 'high', -5, []],
            ['CMP-002', 'u9', 'Listing photos look different', 's3', null, 'The tent listing photo shows side walls but the tent we got had none.', 'in_review', 'normal', -9, [['at' => $this->ts(-8)->toIso8601String(), 'by' => $this->U['u11'], 'note' => 'Contacted supplier for clarification.']]],
            ['CMP-003', 'u1', 'Late delivery', 's5', null, 'Delivery arrived 2 hours late during a past rental.', 'resolved', 'low', -50, [['at' => $this->ts(-48)->toIso8601String(), 'by' => $this->U['u11'], 'note' => 'Supplier apologized and issued a discount voucher.']]],
        ];
        foreach ($complaints as [$code, $rep, $subj, $against, $req, $body, $status, $prio, $d, $updates]) {
            $c = new Complaint(['code' => $code, 'reporter_id' => $this->U[$rep], 'subject' => $subj, 'against_supplier_id' => $this->S[$against], 'rental_request_id' => $req ? $this->R[$req] : null, 'body' => $body, 'status' => $status, 'priority' => $prio, 'updates' => $updates]);
            $c->created_at = $this->ts($d);
            $c->save();
        }
    }

    protected function notifications(): void
    {
        $rent = fn ($c) => route('rentals.show', $c, false);
        $sreq = fn ($c) => route('supplier.requests.show', $c, false);
        $spay = route('supplier.payments', ['tab' => 'verify'], false);
        $rows = [
            ['u1', 'customer', 'Request accepted', "Happy Moments Party Rentals accepted PR-0001 (Sofia's 7th Birthday). Down payment is now due.", $rent('PR-0001'), -4, true],
            ['u1', 'customer', 'Payment verified', 'Your down payment for PR-0001 was verified. Your booking is now reserved and the down payment is credited to your total. Receipt OR-10002 is ready.', $rent('PR-0001'), -3, false],
            ['u1', 'customer', 'Request submitted', "PR-0005 (Camille's Debut) was sent to Celebration Hub Rentals and is pending review.", $rent('PR-0005'), -1, false],
            ['u2', 'supplier', 'New rental request', 'Jose Garcia requested 40 Monoblock Chairs for '.fmt_date($this->D(5)).'. Check for inventory conflicts.', $sreq('PR-0003'), -1, false],
            ['u2', 'supplier', 'New rental request', "Bea Mendoza sent PR-0004 (Baby Liam's Christening).", $sreq('PR-0004'), 0, false],
            ['u2', 'supplier', 'Return due', 'PR-0011 (Garcia Housewarming) items are due for return inspection.', $sreq('PR-0011'), -2, false],
            ['u2', 'supplier', 'Payment submitted', 'Maria Santos submitted ₱1,000.00 for PR-0001. Review it in Payments → Verification queue.', $spay, 0, false],
            ['u2', 'customer', 'Rental completed', 'PR-0009 is complete and your ₱500 deposit was refunded.', $rent('PR-0009'), -44, true],
            ['u7', 'customer', 'Payment rejected', 'Your ₱6,000.00 installment for PR-0008 was rejected: reference number not found.', $rent('PR-0008'), -6, false],
            ['u3', 'supplier', 'New rental request', "Maria Santos sent PR-0005 (Camille's Debut).", $sreq('PR-0005'), -1, false],
            ['u10', 'cashier', 'Installment schedule approved', 'PR-0008 (Garcia–Lopez Wedding) now has 3 scheduled installments. Watch for the first one.', route('cashier.balances', [], false), -18, false],
            ['u3', 'supplier', 'Payment submitted', 'Kristine Aquino submitted a full payment (BDO-901233) for PR-0013. Review it in Payments → Verification queue.', $spay, -1, false],
            ['admin', 'admin', 'New supplier application', "Bea Mendoza applied as an Individual Supplier (Bea's Balloon Corner).", route('admin.applications', [], false), -2, false],
            ['admin', 'admin', 'New organization application', 'Kristine Aquino applied for Aquino Events Corp. as an Organization / Company Supplier.', route('admin.applications', [], false), -1, false],
            ['admin', 'admin', 'New complaint', 'Jose Garcia reported a payment issue on PR-0008.', route('admin.complaints', [], false), -5, false],
            ['superadmin', 'superadmin', 'Suspicious account activity', 'Account miguel@demo.ph was suspended after 6 failed payment proofs.', route('super.users', [], false), -3, false],
        ];
        foreach ($rows as $i => [$to, $scope, $title, $body, $link, $d, $read]) {
            $isRole = in_array($to, ['admin', 'superadmin'], true);
            AppNotification::create([
                'user_id' => $isRole ? null : $this->U[$to], 'role' => $isRole ? $to : null, 'scope' => $scope, 'title' => $title, 'body' => $body,
                'link' => $link, 'read_by' => $read && ! $isRole ? [$this->U[$to]] : [], 'created_at' => $this->ts($d, 9 + ($i % 8)),
            ]);
        }
    }

    protected function audit(): void
    {
        $rows = [
            ['u12', 'superadmin', 'Created admin account', 'Paolo Cruz (paolo.admin@partyrent.ph)', -100],
            ['u12', 'superadmin', 'Updated permissions', 'Paolo Cruz: removed suppliers.manage, applications.review', -99],
            ['u11', 'admin', 'Approved supplier application', 'Party Essentials Shop', -219],
            ['u11', 'admin', 'Suspended account', 'Miguel Torres (miguel@demo.ph) — repeated invalid payment proofs', -3],
            ['u2', 'supplier', 'Verified payment', 'TXN-10002 · down payment · PR-0001', -3],
            ['u10', 'cashier', 'Rejected payment', 'TXN-10011 · ₱6,000.00 · PR-0008 — reference not found', -6],
            ['u5', 'supplier', 'Approved installment schedule', 'PR-0008 · 3 installments until the event date', -18],
            ['u2', 'supplier', 'Accepted rental request', 'PR-0001 · Happy Moments Party Rentals', -4],
            ['u4', 'supplier', 'Completed return inspection', 'PR-0006 · ₱945.00 compensation deducted from deposit', -17],
            ['u12', 'superadmin', 'Changed platform setting', 'Overdue grace period set to 0 days', -30],
        ];
        foreach ($rows as [$actor, $role, $action, $details, $d]) {
            AuditLog::create(['actor_id' => $this->U[$actor], 'role' => $role, 'action' => $action, 'details' => $details, 'created_at' => $this->ts($d, 11)]);
        }
    }

    protected function cart(): void
    {
        $d = fn ($start, $qtys) => array_combine(array_map(fn ($i) => add_days($start, $i), array_keys($qtys)), $qtys);
        foreach ([['s2', 'p9', [60, 60], '14:00', '22:00'], ['s1', 'p5', [1], '10:00', '18:00'], ['s4', 'p21', [50], '08:00', '20:00']] as [$s, $p, $q, $a, $b]) {
            CartLine::create(['user_id' => $this->U['u1'], 'supplier_id' => $this->S[$s], 'type' => 'item', 'ref_id' => $this->P[$p], 'days' => $d($this->D(12), $q), 'time' => ['start' => $a, 'end' => $b], 'selected' => true]);
        }
    }
}
