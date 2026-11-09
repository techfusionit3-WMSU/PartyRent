<?php

namespace Database\Seeders;

use App\Domain\Locations;
use App\Domain\Pricing;
use App\Domain\Settings;
use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\CartLine;
use App\Models\Category;
use App\Models\Complaint;
use App\Models\Hold;
use App\Models\Package;
use App\Models\Payment;
use App\Models\Product;
use App\Models\RentalRequest;
use App\Models\RequestEvent;
use App\Models\Review;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\User;
use App\Support\AddressInput;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Demo data ported from the prototype's js/core/seed.js.
 * Dates are generated relative to "today" so the demo stays consistent whenever it is seeded.
 * All demo accounts use the password "demo123".
 */
class DatabaseSeeder extends Seeder
{
    private array $U = [];   // prototype user key => db id
    private array $S = [];   // supplier
    private array $P = [];   // product
    private array $K = [];   // package
    private array $R = [];   // request code => db id

    /** Demo addresses: [street, barangay, city code]. Codes come from database/data/locations-ph.php. */
    private const ADDR = [
        'maria' => ['23 Mabini St.', 'Kamuning', '137404000'],             // Quezon City
        'juan' => ['88 Katipunan Ave.', 'Loyola Heights', '137404000'],
        'ana' => ['12 Caruncho Ave.', 'Malinao', '137403000'],              // Pasig
        'carlo' => ['401 Chino Roces Ave.', 'Magallanes', '137602000'],     // Makati
        'liza' => ['5th Ave., Bonifacio Global City', 'Fort Bonifacio', '137607000'], // Taguig
        'ramon' => ['1530 Rizal Ave., Sta. Cruz', '310', '133900000'],     // Manila
        'jose' => ['7 Sampaguita St.', 'Marikina Heights', '137402000'],
        'bea' => ['19 Rizal Ave. Ext.', 'Grace Park East', '137501000'],    // Caloocan
        'kristine' => ['33 Aguirre Ave., BF Homes', 'BF Homes', '137604000'], // Parañaque
        'miguel' => ['2 Gen. Luna St.', 'Concepcion', '137502000'],         // Malabon
        'tess' => ['45 Osmeña Blvd.', 'Capitol Site', '072217000'],         // Cebu City
        'dodong' => ['12 Juan Luna Ave.', 'Mabolo', '072217000'],
        'liezl' => ['88 J.P. Laurel Ave.', 'Bajada', '112402000'],          // Davao City
        'nestor' => ['Jose Abad Santos Ave.', 'Dolores', '035416000'],      // City of San Fernando, Pampanga
        'marites' => ['21 Gen. Luna St.', 'San Rafael', '063022000'],       // Iloilo City
    ];

    /** [street, barangay, cityCode] → address, address_parts, city_code */
    private function addr(?array $a, string $landmark = ''): array
    {
        if (! $a) {
            return ['address' => '', 'address_parts' => null, 'city_code' => null];
        }

        return AddressInput::attributes(['cityCode' => $a[2], 'barangay' => $a[1], 'street' => $a[0], 'landmark' => $landmark]);
    }

    private function D(int $n): string
    {
        return add_days(today_iso(), $n);
    }

    private function ts(int $n, int $h = 10): Carbon
    {
        return Carbon::parse($this->D($n))->setTime($h, 15);
    }

    public function run(): void
    {
        $this->settings();
        $this->categories();
        $this->users();
        $this->suppliers();
        $this->products();
        $this->holds();
        $this->packages();
        $this->requests();
        $this->payments();
        $this->reviewsAndComplaints();
        $this->notifications();
        $this->audit();
        $this->cart();
    }

    private function settings(): void
    {
        foreach (Settings::DEFAULTS as $k => $v) {
            Setting::create(['key' => $k, 'value' => $v]);
        }
        Setting::create(['key' => 'permissions.admin', 'value' => array_keys(User::ADMIN_PERMS)]);
        Setting::create(['key' => 'permissions.cashier', 'value' => array_keys(User::CASHIER_PERMS)]);
        foreach (['seq.request' => 14, 'seq.payment' => 22, 'seq.receipt' => 10022, 'seq.complaint' => 3] as $k => $v) {
            Setting::create(['key' => $k, 'value' => $v]);
        }
        Settings::flush();
    }

    private function categories(): void
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

    private function users(): void
    {
        $pw = Hash::make('demo123'); // the "hashed" cast keeps an already-hashed value as-is
        $A = self::ADDR;
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
            // outside Metro Manila
            ['u15', 'Tess Villamor', 'tess@demo.ph', '0927 555 1515', $A['tess'], ['customer'], 'active', -50],
            ['u17', 'Dodong Cabahug', 'dodong@demo.ph', '0932 555 1717', $A['dodong'], ['customer'], 'active', -200],
            ['u18', 'Liezl Gonzaga', 'liezl@demo.ph', '0933 555 1818', $A['liezl'], ['customer'], 'active', -190],
            ['u19', 'Nestor Dizon', 'nestor@demo.ph', '0934 555 1919', $A['nestor'], ['customer'], 'active', -180],
            ['u20', 'Marites Jalandoni', 'marites@demo.ph', '0935 555 2020', $A['marites'], ['customer'], 'active', -170],
            ['u10', 'Grace Villanueva', 'cashier@partyrent.ph', '0917 800 1010', 'PartyRent HQ, Ortigas Center, Pasig', ['cashier'], 'active', -330],
            ['u11', 'Mark Reyes', 'admin@partyrent.ph', '0917 800 1111', 'PartyRent HQ, Ortigas Center, Pasig', ['admin'], 'active', -340],
            ['u13', 'Paolo Cruz', 'paolo.admin@partyrent.ph', '0917 800 1313', 'PartyRent HQ, Ortigas Center, Pasig', ['admin'], 'active', -100],
            ['u12', 'Patricia Lim', 'super@partyrent.ph', '0917 800 1212', 'PartyRent HQ, Ortigas Center, Pasig', ['superadmin'], 'active', -365],
        ];
        foreach ($rows as [$key, $name, $email, $phone, $addr, $roles, $status, $d]) {
            $u = new User(array_merge(['name' => $name, 'email' => $email, 'phone' => $phone, 'roles' => $roles, 'status' => $status, 'password' => $pw], is_array($addr) ? $this->addr($addr) : ['address' => $addr]));
            $u->created_at = $this->ts($d);
            $u->email_verified_at = $u->created_at; // demo accounts are already verified
            if ($key === 'u13') {
                $u->admin_perms = ['customers.view', 'suppliers.view', 'listings.view', 'transactions.view', 'complaints.manage', 'reports.view'];
            }
            $u->save();
            $this->U[$key] = $u->id;
        }
    }

    private function suppliers(): void
    {
        $base = [
            'cancellation' => 'Free cancellation up to 3 days before the rental start date. Cancellations within 3 days forfeit the reservation fee.',
            'returns' => 'Items must be returned clean and complete on the agreed return date. Late returns are charged one extra rental day.',
            'conditions' => 'Renter is responsible for items while in their care. Damaged or missing items are charged at repair or replacement cost.',
        ];
        $A = self::ADDR;
        // Delivery settings. Metro Manila fees match the seeded requests' original quotes so their payments still add up.
        $Z = fn (array $zones, array $x = []) => array_merge(Supplier::DELIVERY_DEFAULTS, ['mode' => 'zones', 'zones' => array_map(fn ($z) => ['level' => $z[0], 'code' => $z[1], 'fee' => $z[2]], $zones)], $x);
        $KM = fn (array $x) => array_merge(Supplier::DELIVERY_DEFAULTS, ['mode' => 'distance'], $x);
        [$MM, $RIZAL, $BULACAN, $CAVITE, $LAGUNA, $BATANGAS] = ['137000000', '045800000', '031400000', '042100000', '043400000', '041000000'];
        $rows = [
            ['s1', 'u2', 'Happy Moments Party Rentals', '🎈', '#E11D74', 'Family-run party rentals serving Quezon City since 2016. Birthday setups, kiddie parties, and backyard celebrations made easy.', $A['juan'], '0918 555 0202', 'hello@happymoments.ph', 'Mon–Sun, 8:00 AM – 7:00 PM', 'both',
                $Z([['province', $MM, 500], ['province', $RIZAL, 900], ['province', $BULACAN, 1000]], ['freeOver' => 15000]), ['cod', 'bank', 'debit'], ['reservation', 'down', 'installment', 'full'], ['reservationFee' => 500, 'reservationCredited' => true, 'downType' => 'percent', 'downValue' => 30, 'depositType' => 'fixed', 'depositValue' => 1000, 'installments' => 3, 'cancelDays' => 3], 4.8, 'approved', -300],
            ['s2', 'u3', 'Celebration Hub Rentals', '✨', '#7C3AED', 'Elegant Tiffany chairs, lights, and floral backdrops for debuts, weddings and milestone events in Pasig and the east of Metro Manila.', $A['ana'], '0919 555 0303', 'book@celebrationhub.ph', 'Mon–Sat, 9:00 AM – 6:00 PM', 'delivery',
                $Z([['province', $MM, 800], ['province', $RIZAL, 1100], ['city', '045802000', 1000]]), ['bank', 'debit'], ['reservation', 'down', 'full'], ['reservationFee' => 300, 'reservationCredited' => false, 'downType' => 'percent', 'downValue' => 50, 'depositType' => 'percent', 'depositValue' => 15, 'installments' => 2, 'cancelDays' => 5, 'cancellation' => 'Free cancellation up to 5 days before the event. The reservation fee is non-refundable.'], 4.6, 'approved', -280],
            ['s3', 'u4', 'Dream Events Supplies', '🎪', '#0D9488', 'High-volume tents, banquet tables and pro sound for corporate events and big gatherings. Warehouse pickup in Makati.', $A['carlo'], '0920 555 0404', 'ops@dreamevents.ph', 'Mon–Sat, 7:00 AM – 6:00 PM', 'pickup', $Z([]), ['cod', 'bank'], ['down', 'full'], ['reservationFee' => 0, 'reservationCredited' => true, 'downType' => 'percent', 'downValue' => 20, 'depositType' => 'fixed', 'depositValue' => 2000, 'installments' => 2, 'cancelDays' => 2], 4.5, 'approved', -260],
            ['s4', 'u5', 'Elegant Occasion Rentals', '💍', '#B45309', 'Premium chiavari chairs, gold-and-white backdrops and lounge furniture for weddings and formal receptions across BGC and the south.', $A['liza'], '0921 555 0505', 'events@elegantoccasion.ph', 'Tue–Sun, 10:00 AM – 7:00 PM', 'both',
                $Z([['province', $MM, 1200], ['province', $CAVITE, 2000], ['province', $LAGUNA, 2000], ['province', $BATANGAS, 3000], ['city', '042119000', 2500]], ['chargePerTrip' => true]), ['bank', 'debit'], ['reservation', 'down', 'installment', 'full'], ['reservationFee' => 1000, 'reservationCredited' => true, 'downType' => 'percent', 'downValue' => 40, 'depositType' => 'fixed', 'depositValue' => 3000, 'installments' => 3, 'cancelDays' => 7, 'cancellation' => 'Free cancellation up to 7 days before the event. Later cancellations forfeit the reservation fee and 20% of payments made.'], 4.9, 'approved', -240],
            ['s5', 'u6', 'Party Essentials Shop', '🥳', '#2563EB', 'Affordable kiddie party must-haves in Manila: small chairs, kiddie tables, bubble machines, photo booth props and more.', $A['ramon'], '0922 555 0606', 'partyessentials@demo.ph', 'Mon–Sun, 8:00 AM – 8:00 PM', 'both',
                $Z([['province', $MM, 350], ['province', $BULACAN, 700]], ['freeOver' => 5000]), ['cod', 'bank'], ['installment', 'full'], ['reservationFee' => 0, 'reservationCredited' => true, 'downType' => 'percent', 'downValue' => 0, 'depositType' => 'fixed', 'depositValue' => 500, 'installments' => 2, 'cancelDays' => 1], 4.4, 'approved', -220],
            ['s6', 'u8', "Bea's Balloon Corner", '🎀', '#DB2777', 'Custom balloon garlands, arches and pastel party décor in Caloocan and nearby cities.', $A['bea'], '0924 555 0808', 'bea.balloons@demo.ph', 'Mon–Sat, 9:00 AM – 5:00 PM', 'both',
                $Z([['province', $MM, 400], ['province', $BULACAN, 600]]), ['cod', 'bank'], ['down', 'full'], ['reservationFee' => 0, 'reservationCredited' => true, 'downType' => 'percent', 'downValue' => 50, 'depositType' => 'fixed', 'depositValue' => 500, 'installments' => 2, 'cancelDays' => 3], 0, 'pending', -2],
            // ---- Visayas, Mindanao and Central Luzon ----
            ['s7', 'u17', 'Sugbo Fiesta Rentals', '🌞', '#EA580C', 'Fiesta tents, monoblocks and bright backdrops for Cebuano celebrations, from Sinulog parties to barangay fiestas. We price delivery by distance from Mabolo.', $A['dodong'], '0932 555 1717', 'book@sugbofiesta.ph', 'Mon–Sat, 8:00 AM – 6:00 PM', 'both',
                $KM(['baseFee' => 400, 'perKm' => 25, 'freeKm' => 5, 'maxKm' => 60, 'freeOver' => 20000]), ['cod', 'bank'], ['down', 'full'], ['reservationFee' => 0, 'reservationCredited' => true, 'downType' => 'percent', 'downValue' => 30, 'depositType' => 'fixed', 'depositValue' => 1000, 'installments' => 2, 'cancelDays' => 3], 4.7, 'approved', -195],
            ['s8', 'u18', 'Davao Celebrations Co.', '🌺', '#059669', 'White Tiffany chairs, sound and kiddie party sets for Davao City and nearby provinces. Same-day setup within the city.', $A['liezl'], '0933 555 1818', 'hello@davaocelebrations.ph', 'Daily, 8:00 AM – 7:00 PM', 'both',
                $Z([['city', '112402000', 300], ['province', '112400000', 800], ['province', '112300000', 1200], ['region', '110000000', 2000]], ['chargePerTrip' => true]), ['cod', 'bank', 'debit'], ['reservation', 'full'], ['reservationFee' => 300, 'reservationCredited' => true, 'downType' => 'percent', 'downValue' => 0, 'depositType' => 'fixed', 'depositValue' => 800, 'installments' => 2, 'cancelDays' => 2], 4.6, 'approved', -185],
            ['s9', 'u19', 'Kapampangan Events Supply', '🎊', '#9333EA', 'Banquet tables, chafing dishes and stage platforms for Pampanga fiestas and reunions. We also deliver to the rest of Central Luzon and Metro Manila.', $A['nestor'], '0934 555 1919', 'events@kapampangan.ph', 'Mon–Sun, 7:00 AM – 6:00 PM', 'delivery',
                $Z([['province', '035400000', 500], ['region', '030000000', 1500], ['province', $MM, 2500]], ['roundTrip' => false]), ['cod', 'bank'], ['down', 'installment', 'full'], ['reservationFee' => 0, 'reservationCredited' => true, 'downType' => 'percent', 'downValue' => 40, 'depositType' => 'percent', 'depositValue' => 10, 'installments' => 3, 'cancelDays' => 5], 4.5, 'approved', -175],
            ['s10', 'u20', 'Iloilo Party Hub', '🏮', '#0EA5E9', 'Chiavari chairs and warm fairy lights for Ilonggo weddings and debuts. Delivery priced by distance from the city proper.', $A['marites'], '0935 555 2020', 'party@iloilohub.ph', 'Tue–Sun, 9:00 AM – 6:00 PM', 'both',
                $KM(['baseFee' => 300, 'perKm' => 20, 'freeKm' => 3, 'maxKm' => 50]), ['bank', 'debit'], ['down', 'full'], ['reservationFee' => 0, 'reservationCredited' => true, 'downType' => 'percent', 'downValue' => 50, 'depositType' => 'fixed', 'depositValue' => 1500, 'installments' => 2, 'cancelDays' => 7], 4.8, 'approved', -165],
        ];
        // Where each seller operates from: a public shop, a private home base, or no pickup point at all.
        $where = [
            's1' => ['home', 14.6390, 121.0775],   // family-run, from home in Loyola Heights
            's2' => ['none', null, null],           // delivery-only online seller
            's3' => ['shop', 14.5352, 121.0137],   // Makati warehouse
            's4' => ['shop', 14.5503, 121.0470],   // BGC showroom
            's5' => ['shop', 14.6175, 120.9830],   // Sta. Cruz store
            's6' => ['home', 14.6475, 120.9855],
            's7' => ['shop', 10.3190, 123.9050],   // Mabolo, Cebu City
            's8' => ['home', 7.0890, 125.6120],    // Bajada, Davao City
            's9' => ['none', 15.0330, 120.6850],   // delivery-only; pin = dispatch point for distances
            's10' => ['shop', 10.6990, 122.5590],  // Iloilo City proper
        ];
        foreach ($rows as [$key, $owner, $name, $logo, $color, $desc, $addr, $phone, $email, $hours, $ful, $delivery, $methods, $arr, $pol, $rating, $status, $d]) {
            $a = $this->addr($addr);
            [$a['location_type'], $a['lat'], $a['lng']] = $where[$key];
            $s = Supplier::create(array_merge($a, [
                'owner_id' => $this->U[$owner], 'name' => $name, 'logo' => $logo, 'color' => $color, 'description' => $desc, 'city' => Locations::name($a['city_code']),
                'phone' => $phone, 'email' => $email, 'contact_person' => User::find($this->U[$owner])->name, 'hours' => $hours,
                'fulfillment' => $ful, 'delivery' => $delivery, 'methods' => $methods, 'arrangements' => $arr, 'policy' => array_merge($base, $pol),
                'rating' => $rating, 'status' => $status, 'applied_at' => $this->ts($d), 'approved_at' => $status === 'approved' ? $this->ts($d + 2) : null,
            ]));
            $this->S[$key] = $s->id;
        }
    }

    private function products(): void
    {
        $P = fn ($key, $sup, $name, $cat, $stock, $tiers, $x = []) => [$key, array_merge([
            'supplier_id' => $this->S[$sup], 'name' => $name, 'category_id' => $cat, 'total_stock' => $stock, 'damaged' => 0, 'missing' => 0,
            'tiers' => array_map(fn ($t) => ['min' => $t[0], 'max' => $t[1], 'price' => $t[2]], $tiers),
            'unit' => 'piece / day', 'condition' => 'Excellent', 'colors' => [], 'themes' => [], 'events' => [], 'images' => [],
            'status' => 'published', 'rating' => 4.6, 'rent_count' => 0, 'delivery' => true, 'pickup' => true, 'description' => '',
        ], $x)];
        $list = [
            $P('p1', 's1', 'White Monoblock Chair', 'chairs', 100, [[1, 10, 15], [11, 30, 13], [31, 50, 11], [51, null, 10]], ['colors' => ['White'], 'themes' => ['Classic', 'Minimalist'], 'events' => ['Birthday', 'Christening', 'Reunion', 'Corporate'], 'rating' => 4.7, 'rent_count' => 412, 'description' => 'Sturdy, stackable white monoblock chairs. Cleaned and sanitized after every rental. Ideal for backyard parties, reunions and community events.', 'damaged' => 2]),
            $P('p2', 's1', 'Round Table (8-Seater)', 'tables', 20, [[1, 9, 150], [10, null, 130]], ['colors' => ['White'], 'themes' => ['Classic'], 'events' => ['Birthday', 'Wedding', 'Reunion', 'Christening'], 'rating' => 4.8, 'rent_count' => 188, 'description' => '60-inch round table that comfortably seats 8 guests. Pair with our round tablecloths for a polished look.']),
            $P('p3', 's1', 'Rectangular Table (6 ft)', 'tables', 15, [[1, null, 180]], ['colors' => ['White'], 'themes' => ['Classic'], 'events' => ['Birthday', 'Corporate', 'Reunion'], 'rating' => 4.6, 'rent_count' => 96, 'description' => 'Foldable 6-foot rectangular table for buffets, gift tables and registration areas.']),
            $P('p4', 's1', '10×10 ft Canopy Tent (White)', 'tents', 6, [[1, null, 1200]], ['colors' => ['White'], 'themes' => ['Outdoor', 'Garden'], 'events' => ['Birthday', 'Reunion', 'Christening'], 'rating' => 4.7, 'rent_count' => 64, 'unit' => 'unit / day', 'description' => 'Pop-up canopy with side walls, sandbags and setup included. Shelters about 20–25 seated guests.', 'handling_fee' => 200, 'handling_per' => 'unit']),
            $P('p5', 's1', 'Pink Balloon Garland Set', 'decorations', 8, [[1, null, 850]], ['colors' => ['Pink', 'White'], 'themes' => ['Pastel', 'Princess'], 'events' => ['Birthday', 'Debut', 'Baby Shower'], 'rating' => 4.9, 'rent_count' => 120, 'unit' => 'set / day', 'description' => '4-meter pastel pink and white balloon garland with frame stand. Reusable frame, fresh balloons per rental.']),
            $P('p6', 's1', 'Party Speaker 1000W with Mic', 'sound', 4, [[1, null, 1500]], ['colors' => ['Black'], 'themes' => ['Modern'], 'events' => ['Birthday', 'Reunion', 'Corporate', 'Debut'], 'rating' => 4.5, 'rent_count' => 77, 'unit' => 'unit / day', 'description' => 'Bluetooth party speaker with 2 wireless microphones and stand. Good for up to 80 guests.']),
            $P('p7', 's1', 'Kids Party Games Kit', 'games', 5, [[1, null, 600]], ['colors' => ['Multicolor'], 'themes' => ['Kids', 'Carnival'], 'events' => ['Birthday'], 'rating' => 4.6, 'rent_count' => 54, 'unit' => 'kit / day', 'description' => 'Bring-me game cards, sack race bags, pabitin frame and prize bowl. Host guide included.']),
            $P('p8', 's1', 'White Round Tablecloth', 'tableware', 40, [[1, 19, 60], [20, null, 50]], ['colors' => ['White'], 'themes' => ['Classic', 'Elegant'], 'events' => ['Birthday', 'Wedding', 'Christening'], 'rating' => 4.5, 'rent_count' => 140, 'description' => 'Floor-length polyester tablecloth for 60-inch round tables. Laundered and pressed.', 'missing' => 1]),
            $P('p9', 's2', 'Tiffany Chair (Gold)', 'chairs', 120, [[1, 50, 45], [51, null, 40]], ['colors' => ['Gold'], 'themes' => ['Elegant', 'Glam'], 'events' => ['Wedding', 'Debut', 'Anniversary'], 'rating' => 4.8, 'rent_count' => 356, 'description' => 'Classic gold Tiffany chairs with ivory seat cushions. Delivered and arranged by our crew.']),
            $P('p10', 's2', 'Tiffany Chair (Clear Ghost)', 'chairs', 60, [[1, null, 55]], ['colors' => ['Clear', 'White'], 'themes' => ['Modern', 'Minimalist'], 'events' => ['Wedding', 'Debut', 'Corporate'], 'rating' => 4.7, 'rent_count' => 141, 'description' => 'Transparent acrylic Tiffany chairs for a modern, airy setup.']),
            $P('p11', 's2', 'LED Fairy Light Curtain', 'lighting', 15, [[1, null, 450]], ['colors' => ['Warm White', 'Gold'], 'themes' => ['Elegant', 'Romantic', 'Garden'], 'events' => ['Wedding', 'Debut', 'Birthday'], 'rating' => 4.7, 'rent_count' => 98, 'unit' => 'panel / day', 'description' => '3m × 3m warm-white curtain light. Perfect behind backdrops or along stage edges.']),
            $P('p12', 's2', 'Moving Head Party Lights (Pair)', 'lighting', 6, [[1, null, 1800]], ['colors' => ['Multicolor'], 'themes' => ['Disco', 'Modern'], 'events' => ['Debut', 'Birthday', 'Corporate'], 'rating' => 4.5, 'rent_count' => 40, 'unit' => 'pair / day', 'description' => 'DMX moving head beam lights with controller. Operator available on request.', 'handling_fee' => 300, 'handling_per' => 'line']),
            $P('p13', 's2', 'Floral Arch Backdrop (Pink & White)', 'backdrops', 3, [[1, null, 2500]], ['colors' => ['Pink', 'White'], 'themes' => ['Romantic', 'Pastel', 'Garden'], 'events' => ['Debut', 'Wedding', 'Birthday'], 'rating' => 4.9, 'rent_count' => 37, 'unit' => 'set / day', 'description' => '2.4m round arch with silk florals in blush pink and white. Includes setup.']),
            $P('p14', 's2', 'Pastel Pink Centerpiece Set', 'decorations', 25, [[1, null, 180]], ['colors' => ['Pink'], 'themes' => ['Pastel', 'Romantic'], 'events' => ['Debut', 'Birthday', 'Baby Shower'], 'rating' => 4.6, 'rent_count' => 75, 'unit' => 'set / day', 'description' => 'Glass vase with silk peonies and votive candles, one set per table.']),
            $P('p15', 's3', 'White Monoblock Chair', 'chairs', 200, [[1, 50, 12], [51, null, 9]], ['colors' => ['White'], 'themes' => ['Classic'], 'events' => ['Corporate', 'Reunion', 'Birthday'], 'rating' => 4.4, 'rent_count' => 520, 'description' => 'Bulk monoblock chairs for large events. Warehouse pickup only.', 'delivery' => false]),
            $P('p16', 's3', 'Folding Banquet Table', 'tables', 30, [[1, null, 200]], ['colors' => ['White'], 'themes' => ['Classic'], 'events' => ['Corporate', 'Reunion'], 'rating' => 4.5, 'rent_count' => 210, 'description' => '8-foot banquet table, seats 8–10. Pickup only.', 'delivery' => false]),
            $P('p17', 's3', '20×20 ft Party Tent', 'tents', 4, [[1, null, 3500]], ['colors' => ['White'], 'themes' => ['Outdoor'], 'events' => ['Corporate', 'Reunion', 'Wedding', 'Birthday'], 'rating' => 4.6, 'rent_count' => 52, 'unit' => 'unit / day', 'description' => 'Large frame tent for up to 60 seated guests. Assembly crew available for an extra fee.', 'delivery' => false, 'handling_fee' => 500, 'handling_per' => 'unit']),
            $P('p18', 's3', 'Chafing Dish Set', 'catering', 20, [[1, null, 250]], ['colors' => ['Silver'], 'themes' => ['Classic'], 'events' => ['Corporate', 'Wedding', 'Reunion', 'Birthday'], 'rating' => 4.5, 'rent_count' => 160, 'unit' => 'set / day', 'description' => 'Stainless chafing dish with water pan, food pan and fuel holder.', 'delivery' => false]),
            $P('p19', 's3', 'Line Array Sound System', 'sound', 2, [[1, null, 6500]], ['colors' => ['Black'], 'themes' => ['Modern'], 'events' => ['Corporate', 'Wedding', 'Debut'], 'rating' => 4.8, 'rent_count' => 33, 'unit' => 'system / day', 'description' => 'Professional line array with mixer, 4 wireless mics and technician for up to 300 guests.', 'delivery' => false, 'handling_fee' => 1000, 'handling_per' => 'line']),
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
        ];
        $list = array_merge($list, [
            // Cebu City (distance-based delivery)
            $P('p33', 's7', 'White Monoblock Chair', 'chairs', 150, [[1, 49, 14], [50, null, 11]], ['colors' => ['White'], 'themes' => ['Classic'], 'events' => ['Birthday', 'Reunion', 'Corporate', 'Christening'], 'rating' => 4.6, 'rent_count' => 230, 'description' => 'Stackable monoblock chairs, washed after every fiesta.']),
            $P('p34', 's7', 'Round Table (8-Seater)', 'tables', 25, [[1, null, 140]], ['colors' => ['White'], 'themes' => ['Classic'], 'events' => ['Birthday', 'Wedding', 'Reunion'], 'rating' => 4.6, 'rent_count' => 110, 'description' => '60-inch round table for 8 guests.']),
            $P('p35', 's7', '20×20 ft Fiesta Tent', 'tents', 3, [[1, null, 3200]], ['colors' => ['White', 'Multicolor'], 'themes' => ['Outdoor', 'Carnival'], 'events' => ['Birthday', 'Reunion', 'Corporate'], 'rating' => 4.7, 'rent_count' => 41, 'unit' => 'unit / day', 'description' => 'Frame tent for about 60 seated guests. Our crew sets it up on delivery.', 'handling_fee' => 600, 'handling_per' => 'unit']),
            $P('p36', 's7', 'Sinulog Fiesta Backdrop', 'backdrops', 2, [[1, null, 2200]], ['colors' => ['Multicolor', 'Gold'], 'themes' => ['Fun', 'Carnival'], 'events' => ['Birthday', 'Debut', 'Corporate'], 'rating' => 4.8, 'rent_count' => 27, 'unit' => 'set / day', 'description' => '3m festive backdrop with sun motif, ribbons and a photo frame.']),
            // Davao City (zones)
            $P('p37', 's8', 'Tiffany Chair (White)', 'chairs', 100, [[1, null, 40]], ['colors' => ['White'], 'themes' => ['Elegant', 'Classic'], 'events' => ['Wedding', 'Debut', 'Christening'], 'rating' => 4.7, 'rent_count' => 150, 'description' => 'White Tiffany chairs with cushions.']),
            $P('p38', 's8', 'Party Speaker Set with 2 Mics', 'sound', 3, [[1, null, 1300]], ['colors' => ['Black'], 'themes' => ['Modern'], 'events' => ['Birthday', 'Reunion', 'Corporate'], 'rating' => 4.5, 'rent_count' => 60, 'unit' => 'set / day', 'description' => 'Two powered speakers, mixer and wireless mics for up to 120 guests.', 'handling_fee' => 200, 'handling_per' => 'line']),
            $P('p39', 's8', 'Kiddie Party Games Set', 'games', 4, [[1, null, 900]], ['colors' => ['Multicolor'], 'themes' => ['Kids', 'Carnival'], 'events' => ['Birthday'], 'rating' => 4.6, 'rent_count' => 35, 'unit' => 'set / day', 'description' => 'Pabitin frame, sack race bags, bring-me cards and prizes bowl.']),
            // City of San Fernando, Pampanga (zones, one-way fees)
            $P('p40', 's9', 'Banquet Table (8 ft)', 'tables', 40, [[1, null, 180]], ['colors' => ['White'], 'themes' => ['Classic'], 'events' => ['Reunion', 'Corporate', 'Wedding'], 'rating' => 4.5, 'rent_count' => 95, 'description' => 'Folding banquet table, seats 8–10.']),
            $P('p41', 's9', 'Chafing Dish Set', 'catering', 30, [[1, 9, 220], [10, null, 190]], ['colors' => ['Silver'], 'themes' => ['Classic'], 'events' => ['Reunion', 'Wedding', 'Corporate'], 'rating' => 4.6, 'rent_count' => 120, 'unit' => 'set / day', 'description' => 'Stainless chafing dish with fuel holders. Perfect for Kapampangan feasts.']),
            $P('p42', 's9', 'Stage Platform (4×8 m)', 'furniture', 2, [[1, null, 4500]], ['colors' => ['Black'], 'themes' => ['Modern'], 'events' => ['Wedding', 'Corporate', 'Debut'], 'rating' => 4.7, 'rent_count' => 18, 'unit' => 'set / day', 'description' => 'Modular stage with skirting and steps, assembled on site.', 'handling_fee' => 1500, 'handling_per' => 'line']),
            // Iloilo City (distance-based delivery)
            $P('p43', 's10', 'Gold Chiavari Chair', 'chairs', 80, [[1, 99, 60], [100, null, 50]], ['colors' => ['Gold'], 'themes' => ['Elegant', 'Glam'], 'events' => ['Wedding', 'Debut'], 'rating' => 4.8, 'rent_count' => 90, 'description' => 'Champagne-gold chiavari chairs with white cushions.']),
            $P('p44', 's10', 'LED Fairy Light Curtain', 'lighting', 10, [[1, null, 400]], ['colors' => ['Warm White'], 'themes' => ['Romantic', 'Garden'], 'events' => ['Wedding', 'Debut', 'Birthday'], 'rating' => 4.7, 'rent_count' => 52, 'unit' => 'panel / day', 'description' => '3m × 3m warm-white curtain light.']),
        ]);
        $recent = ['p13' => -3, 'p25' => -5, 'p30' => -6, 'p36' => -4];
        foreach ($list as $i => [$key, $attrs]) {
            $p = new Product($attrs);
            $p->created_at = $this->ts($recent[$key] ?? ($i < 32 ? -200 + $i * 6 : -160 + ($i - 32) * 8));
            $p->save();
            $this->P[$key] = $p->id;
        }
    }

    private function holds(): void
    {
        Hold::create(['product_id' => $this->P['p1'], 'type' => 'maintenance', 'qty' => 5, 'start_date' => $this->D(-2), 'end_date' => $this->D(10), 'note' => 'Repainting scuffed chairs']);
        Hold::create(['product_id' => $this->P['p9'], 'type' => 'setaside', 'qty' => 10, 'start_date' => $this->D(0), 'end_date' => null, 'note' => 'Reserved for showroom display']);
        Hold::create(['product_id' => $this->P['p17'], 'type' => 'maintenance', 'qty' => 1, 'start_date' => $this->D(-5), 'end_date' => $this->D(4), 'note' => 'Replacing torn tent panel']);
    }

    private function packages(): void
    {
        $it = fn (array $pairs) => array_map(fn ($x) => ['product_id' => $this->P[$x[0]], 'qty' => $x[1]], $pairs);
        $rows = [
            ['k1', 's1', 'Birthday Party Package', '🎂', 'Everything for a 50-guest birthday: chairs, round tables with cloths and a pink balloon garland.', [['p1', 50], ['p2', 5], ['p5', 1], ['p8', 5]], 3500, 'Price is per day. Setup within Quezon City included with delivery.', ['Birthday'], -150],
            ['k2', 's1', 'Outdoor Party Package', '⛺', 'Two canopy tents, 40 chairs, 4 long tables and a party speaker for garden or street parties.', [['p4', 2], ['p1', 40], ['p3', 4], ['p6', 1]], 5800, 'Price is per day. Requires a flat area of at least 6 × 3 m.', ['Birthday', 'Reunion', 'Christening'], -120],
            ['k3', 's4', 'Wedding Reception Package', '💒', 'An elegant white-and-gold reception for 100 guests: chiavari chairs, backdrop, crystal centerpieces, satin cloths and uplighting.', [['p21', 100], ['p22', 1], ['p23', 12], ['p24', 12], ['p26', 1]], 18500, 'Price is per day. Includes styling crew for 4 hours.', ['Wedding', 'Anniversary'], -140],
            ['k4', 's2', 'Debut Party Package', '👑', '80 gold Tiffany chairs, floral arch, fairy-light curtains and pastel centerpieces for a dreamy 18th.', [['p9', 80], ['p13', 1], ['p11', 4], ['p14', 10]], 9900, 'Price is per day. Delivery within Pasig, Mandaluyong and San Juan.', ['Debut'], -110],
            ['k5', 's5', "Kids' Party Package", '🧸', '30 kids chairs, 6 kiddie tables, a bubble machine, photo booth props and dinnerware for 30.', [['p27', 30], ['p28', 6], ['p30', 1], ['p29', 1], ['p31', 3]], 2200, 'Price is per day.', ['Birthday', 'Christening'], -100],
            ['k6', 's3', 'Corporate Event Package', '🏢', '100 chairs, 12 banquet tables, line array sound and 6 chafing dishes for company events.', [['p15', 100], ['p16', 12], ['p19', 1], ['p18', 6]], 12000, 'Price is per day. Warehouse pickup only.', ['Corporate'], -90],
        ];
        foreach ($rows as [$key, $sup, $name, $emoji, $desc, $items, $price, $cond, $events, $d]) {
            $k = new Package(['supplier_id' => $this->S[$sup], 'name' => $name, 'emoji' => $emoji, 'description' => $desc, 'items' => $it($items), 'price' => $price, 'conditions' => $cond, 'events' => $events, 'images' => [], 'active' => true]);
            $k->created_at = $this->ts($d);
            $k->save();
            $this->K[$key] = $k->id;
        }
    }

    private function requests(): void
    {
        $days = function (string $start, array $qtys) {
            $o = [];
            foreach ($qtys as $i => $q) {
                $o[add_days($start, $i)] = $q;
            }

            return $o;
        };
        $L = fn ($id, $type, $ref, $d) => ['id' => $id, 'type' => $type, 'ref_id' => $type === 'package' ? $this->K[$ref] : $this->P[$ref], 'days' => $d];
        $D = fn ($n) => $this->D($n);
        $A = self::ADDR;
        $rows = [
            ['PR-0001', 'u1', 's1', "Sofia's 7th Birthday", 'Birthday', 6, 5, 7, 'delivery', $A['maria'], 'Please set up the tables in the garden. Gate code 2468.', [$L('l1', 'item', 'p1', $days($D(5), [30, 20, 40])), $L('l2', 'item', 'p2', $days($D(5), [5, 8, 5]))], 'down', 'bank', 'accepted', -6, -4, [['pending', -6, 'u1'], ['accepted', -4, 'u2', 'Confirmed. We will deliver at 8 AM on day one.']], 'Deliver 8 AM day one. Customer will keep chairs on site; quantity changes daily as per schedule.', []],
            ['PR-0002', 'u9', 's1', 'Aquino Family Reunion', 'Reunion', 5, 5, 5, 'pickup', null, '', [$L('l1', 'package', 'k1', $days($D(5), [1]))], 'full', 'debit', 'accepted', -10, -9, [['pending', -10, 'u9'], ['accepted', -9, 'u2']], 'Pickup at 7 AM.', []],
            ['PR-0003', 'u7', 's1', 'Barangay Fiesta Booth', 'Community', 5, 5, 5, 'delivery', $A['jose'], 'Need chairs by 6 AM.', [$L('l1', 'item', 'p1', $days($D(5), [40])), $L('l2', 'item', 'p3', $days($D(5), [3]))], 'full', 'cod', 'pending', -1, null, [['pending', -1, 'u7']], '', []],
            ['PR-0004', 'u8', 's1', "Baby Liam's Christening", 'Christening', 9, 9, 9, 'delivery', $A['bea'], '', [$L('l1', 'item', 'p2', $days($D(9), [6])), $L('l2', 'item', 'p8', $days($D(9), [6]))], 'reservation', 'bank', 'pending', 0, null, [['pending', 0, 'u8']], '', []],
            ['PR-0005', 'u1', 's2', "Camille's Debut", 'Debut', 21, 21, 21, 'delivery', ['Valle Verde Clubhouse', 'Ugong', '137403000'], 'Ingress allowed from 2 PM only.', [$L('l1', 'package', 'k4', $days($D(21), [1])), $L('l2', 'item', 'p12', $days($D(21), [1]))], 'down', 'bank', 'pending', -1, null, [['pending', -1, 'u1']], '', []],
            ['PR-0006', 'u1', 's3', 'Santos Company Outing', 'Corporate', -19, -20, -18, 'pickup', null, '', [$L('l1', 'item', 'p15', $days($D(-20), [80, 80, 60])), $L('l2', 'item', 'p16', $days($D(-20), [10, 10, 8])), $L('l3', 'item', 'p17', $days($D(-20), [1, 1, 1]))], 'down', 'cod', 'completed', -35, -33, [['pending', -35, 'u1'], ['accepted', -33, 'u4'], ['in_progress', -21, 'u4'], ['ready_pickup', -21, 'u4'], ['ongoing', -20, 'u4'], ['return_pending', -18, 'u4'], ['inspection', -17, 'u4'], ['completed', -15, 'u4', 'Settled. 2 chairs broken, 1 missing; charged from deposit.']], 'Picked up by Mr. Rodel Santos.',
                ['fulfillment_log' => ['releaseDate' => $D(-20), 'releaseQty' => '80 chairs, 10 tables, 1 tent', 'receivedBy' => 'Rodel Santos', 'returnDate' => $D(-17), 'returnedQty' => '79 chairs, 10 tables, 1 tent', 'method' => 'pickup'],
                    'inspection' => ['at' => $this->ts(-17)->toIso8601String(), 'by' => 'u4', 'items' => [['productId' => $this->P['p15'], 'returned' => 79, 'good' => 77, 'minor' => 0, 'major' => 2, 'total' => 0, 'missing' => 1, 'repairFee' => 0, 'replaceFee' => 450], ['productId' => $this->P['p16'], 'returned' => 10, 'good' => 10, 'minor' => 0, 'major' => 0, 'total' => 0, 'missing' => 0, 'repairFee' => 0, 'replaceFee' => 0], ['productId' => $this->P['p17'], 'returned' => 1, 'good' => 1, 'minor' => 0, 'major' => 0, 'total' => 0, 'missing' => 0, 'repairFee' => 0, 'replaceFee' => 0]], 'notes' => 'Two chairs with cracked legs; one chair not returned.', 'photos' => [], 'compensation' => 450, 'settlement' => 'deducted', 'settledAt' => $this->ts(-15)->toIso8601String()], 'completed_at' => $D(-15)]],
            ['PR-0007', 'u1', 's5', 'Office Christmas Party', 'Corporate', -30, -30, -30, 'delivery', ['V. Mapa St.', 'Sta. Mesa', '133900000'], '', [$L('l1', 'item', 'p32', $days($D(-30), [2]))], 'full', 'cod', 'declined', -40, null, [['pending', -40, 'u1'], ['declined', -39, 'u6', 'Popcorn machines are already booked for that date. Sorry!']], '', ['decline_reason' => 'Popcorn machines are already booked for that date. Sorry!']],
            ['PR-0008', 'u7', 's4', 'Garcia–Lopez Wedding', 'Wedding', 14, 14, 14, 'delivery', ['The Glass Garden, Ortigas Ave. Ext.', 'Santolan', '137403000'], 'Styling crew should arrive by 1 PM.', [$L('l1', 'package', 'k3', $days($D(14), [1])), $L('l2', 'item', 'p25', $days($D(14), [1]))], 'installment', 'bank', 'accepted', -20, -18, [['pending', -20, 'u7'], ['accepted', -18, 'u5']], 'Installment plan: 3 payments before the wedding.', []],
            ['PR-0009', 'u2', 's5', "Juan's Son's 5th Birthday", 'Birthday', -45, -45, -45, 'delivery', $A['juan'], '', [$L('l1', 'package', 'k5', $days($D(-45), [1]))], 'full', 'cod', 'completed', -55, -54, [['pending', -55, 'u2'], ['accepted', -54, 'u6'], ['out_delivery', -45, 'u6'], ['ongoing', -45, 'u6'], ['return_pending', -44, 'u6'], ['inspection', -44, 'u6'], ['completed', -44, 'u6', 'All items returned in good condition. Deposit refunded.']], '',
                ['fulfillment_log' => ['releaseDate' => $D(-45), 'releaseQty' => 'Full package', 'receivedBy' => 'Juan Dela Cruz', 'returnDate' => $D(-44), 'returnedQty' => 'Full package', 'method' => 'delivery'],
                    'inspection' => ['at' => $this->ts(-44)->toIso8601String(), 'by' => 'u6', 'items' => [], 'notes' => 'All good.', 'photos' => [], 'compensation' => 0, 'settlement' => 'settled', 'settledAt' => $this->ts(-44)->toIso8601String()], 'completed_at' => $D(-44)]],
            ['PR-0010', 'u9', 's3', 'Aquino Corp. Team Building', 'Corporate', 0, -1, 1, 'pickup', null, '', [$L('l1', 'item', 'p17', $days($D(-1), [2, 2, 2])), $L('l2', 'item', 'p19', $days($D(-1), [1, 1, 1])), $L('l3', 'item', 'p15', $days($D(-1), [60, 120, 60]))], 'down', 'cod', 'ongoing', -12, -11, [['pending', -12, 'u9'], ['accepted', -11, 'u4'], ['in_progress', -2, 'u4'], ['ready_pickup', -2, 'u4'], ['ongoing', -1, 'u4']], '', ['fulfillment_log' => ['releaseDate' => $D(-1), 'releaseQty' => '2 tents, 1 sound system, 120 chairs', 'receivedBy' => 'Kristine Aquino', 'method' => 'pickup']]],
            ['PR-0011', 'u7', 's1', 'Garcia Housewarming', 'Birthday', -3, -3, -2, 'delivery', $A['jose'], '', [$L('l1', 'item', 'p1', $days($D(-3), [25, 25])), $L('l2', 'item', 'p3', $days($D(-3), [3, 3])), $L('l3', 'item', 'p6', $days($D(-3), [1, 1]))], 'full', 'bank', 'return_pending', -14, -13, [['pending', -14, 'u7'], ['accepted', -13, 'u2'], ['in_progress', -4, 'u2'], ['out_delivery', -3, 'u2'], ['ongoing', -3, 'u2'], ['return_pending', -2, 'u2']], 'Pick up items on the morning after the event.', ['fulfillment_log' => ['releaseDate' => $D(-3), 'releaseQty' => '25 chairs, 3 tables, 1 speaker', 'receivedBy' => 'Jose Garcia', 'method' => 'delivery']]],
            ['PR-0012', 'u1', 's5', 'Mini Movie Night', 'Birthday', 2, 2, 2, 'pickup', null, '', [$L('l1', 'item', 'p32', $days($D(2), [1]))], 'full', 'cod', 'cancelled', -8, -7, [['pending', -8, 'u1'], ['accepted', -7, 'u6'], ['cancelled', -5, 'u1', 'Event moved to next month.']], '', ['cancel_reason' => 'Event moved to next month.', 'cancel_free' => true]],
            ['PR-0013', 'u9', 's2', "Kristine's 30th", 'Birthday', 3, 3, 3, 'delivery', $A['kristine'], '', [$L('l1', 'item', 'p10', $days($D(3), [30])), $L('l2', 'item', 'p11', $days($D(3), [2])), $L('l3', 'item', 'p13', $days($D(3), [1]))], 'full', 'bank', 'in_progress', -4, -2, [['pending', -4, 'u9'], ['accepted', -2, 'u3'], ['in_progress', 0, 'u3', 'Preparing items for delivery.']], '', []],
            // Cebu customer → Cebu supplier (distance-based delivery + per-unit tent handling)
            ['PR-0014', 'u15', 's7', "Lola Nena's 80th", 'Birthday', 10, 10, 10, 'delivery', $A['tess'], 'Party is at the clubhouse; please call on arrival.', [$L('l1', 'item', 'p33', $days($D(10), [60])), $L('l2', 'item', 'p35', $days($D(10), [1]))], 'full', 'bank', 'pending', -1, null, [['pending', -1, 'u15']], '', []],
        ];
        foreach ($rows as [$code, $cust, $sup, $name, $type, $ev, $st, $en, $ful, $addr, $instr, $lines, $arr, $method, $status, $created, $accepted, $hist, $notes, $extra]) {
            $c = User::find($this->U[$cust]);
            if (isset($extra['inspection'])) {
                $extra['inspection']['by'] = $this->U[$extra['inspection']['by']];
            }
            $r = new RentalRequest(array_merge($this->addr($addr), [
                'code' => $code, 'customer_id' => $c->id, 'supplier_id' => $this->S[$sup], 'event_name' => $name, 'event_type' => $type,
                'event_date' => $D($ev), 'start_date' => $D($st), 'end_date' => $D($en), 'fulfillment' => $ful,
                'contact_name' => $c->name, 'contact_phone' => $c->phone, 'instructions' => $instr, 'lines' => $lines,
                'arrangement' => $arr, 'method' => $method, 'status' => $status, 'supplier_notes' => $notes,
                'accepted_at' => $accepted !== null ? $D($accepted) : null,
            ], $extra));
            $r->setRelation('supplier', Supplier::find($this->S[$sup]));
            $r->pricing = Pricing::quoteRequest($r); // includes the delivery breakdown snapshot
            $r->created_at = $this->ts($created, 11);
            $r->save();
            foreach ($hist as $h) {
                RequestEvent::create(['rental_request_id' => $r->id, 'status' => $h[0], 'user_id' => $this->U[$h[2]], 'note' => $h[3] ?? null, 'created_at' => $this->ts($h[1])]);
            }
            $this->R[$code] = $r->id;
        }
    }

    private function payments(): void
    {
        $pay = function ($code, $req, $type, $method, $amount, $n, $status, $ref, $extra = []) {
            Payment::create(array_merge([
                'code' => $code, 'rental_request_id' => $this->R[$req], 'type' => $type, 'method' => $method, 'amount' => $amount,
                'date' => $this->D($n), 'submitted_at' => $this->ts($n, 13), 'reference' => $ref, 'status' => $status, 'recorded_by' => 'customer',
            ], $status === 'verified' ? ['verified_by' => $this->U['u10'], 'verified_at' => $this->ts($n, 16), 'receipt_no' => 'OR-'.substr($code, 4)] : [], $extra));
        };
        $pay('TXN-10001', 'PR-0001', 'reservation', 'bank', 500, -4, 'verified', 'BDO-884120');
        $pay('TXN-10002', 'PR-0001', 'down', 'bank', 787, -3, 'verified', 'BDO-884377');
        $pay('TXN-10003', 'PR-0001', 'installment', 'bank', 2000, 0, 'pending', 'BPI-551093', ['proof_name' => 'bpi-transfer-receipt.jpg', 'notes' => 'Partial balance payment']);
        $pay('TXN-10004', 'PR-0002', 'full', 'debit', 3500, -9, 'verified', 'DC-7781-0021');
        $pay('TXN-10005', 'PR-0002', 'deposit', 'debit', 1000, -9, 'verified', 'DC-7781-0022');
        $pay('TXN-10006', 'PR-0006', 'down', 'cash', 3616, -33, 'verified', 'CASH-0912');
        $pay('TXN-10007', 'PR-0006', 'balance', 'cash', 14464, -20, 'verified', 'CASH-0957');
        $pay('TXN-10008', 'PR-0006', 'deposit', 'cash', 2000, -20, 'verified', 'CASH-0958');
        $pay('TXN-10009', 'PR-0006', 'deposit_refund', 'cash', 1550, -15, 'verified', 'RF-0031', ['notes' => '₱450 replacement fee deducted from ₱2,000 deposit']);
        $pay('TXN-10010', 'PR-0008', 'reservation', 'bank', 1000, -17, 'verified', 'UB-220145');
        $pay('TXN-10011', 'PR-0008', 'installment', 'bank', 6000, -6, 'rejected', 'UB-22O1A9', ['proof_name' => 'screenshot.png', 'reject_reason' => 'Reference number not found in bank statement. Please resubmit a clear receipt.', 'verified_by' => $this->U['u10'], 'verified_at' => $this->ts(-6, 16)]);
        $pay('TXN-10012', 'PR-0009', 'full', 'cash', 2550, -45, 'verified', 'CASH-0810');
        $pay('TXN-10013', 'PR-0009', 'deposit', 'cash', 500, -45, 'verified', 'CASH-0811');
        $pay('TXN-10014', 'PR-0009', 'deposit_refund', 'cash', 500, -44, 'verified', 'RF-0022');
        $pay('TXN-10015', 'PR-0010', 'down', 'bank', 8532, -10, 'verified', 'MBTC-11902');
        $pay('TXN-10022', 'PR-0010', 'balance', 'cash', 34128, -1, 'verified', 'CASH-0998');
        $pay('TXN-10016', 'PR-0010', 'deposit', 'cash', 2000, -1, 'verified', 'CASH-0999');
        $pay('TXN-10017', 'PR-0011', 'full', 'bank', 5230, -12, 'verified', 'GCASH-BANK-77810');
        $pay('TXN-10018', 'PR-0011', 'deposit', 'bank', 1000, -12, 'verified', 'GCASH-BANK-77811');
        $pay('TXN-10019', 'PR-0013', 'full', 'bank', 5850, -1, 'pending', 'BDO-901233', ['proof_name' => 'bdo-online-receipt.png']);
        $pay('TXN-10020', 'PR-0012', 'full', 'cash', 900, -7, 'verified', 'CASH-0977');
        $pay('TXN-10021', 'PR-0012', 'refund', 'cash', 900, -5, 'verified', 'RF-0035', ['notes' => 'Cancelled within free cancellation window']);
    }

    private function reviewsAndComplaints(): void
    {
        $reviews = [
            ['s1', 'p1', 'u9', 5, 'Chairs were spotless and delivered early. Super bait ng crew!', -30],
            ['s1', 'p5', 'u1', 5, 'The pink garland made the whole party. My daughter loved it.', -60],
            ['s1', 'p6', 'u7', 4, 'Speaker was loud and clear, one mic had a weak battery.', -25],
            ['s2', 'p9', 'u9', 5, 'Gold Tiffany chairs looked so elegant for our debut.', -50],
            ['s4', 'p22', 'u7', 5, 'Backdrop was exactly like the photos. Worth it!', -70],
        ];
        foreach ($reviews as [$s, $p, $c, $rating, $comment, $d]) {
            $rv = new Review(['supplier_id' => $this->S[$s], 'product_id' => $this->P[$p], 'customer_id' => $this->U[$c], 'rating' => $rating, 'comment' => $comment]);
            $rv->created_at = $this->ts($d);
            $rv->save();
        }
        // Verified reviews tied to the seeded completed rentals (one per rented item/package).
        $verified = [
            ['PR-0006', 's3', 'p15', null, 'u1', 4, 'Chairs were clean and complete. Two had small scratches but the crew swapped them right away.', -16, 'Thank you Maria! Sorry about the scratched chairs — we have retired those two.'],
            ['PR-0006', 's3', 'p16', null, 'u1', 5, 'Banquet tables were sturdy and pickup at the warehouse was quick.', -16, null],
            ['PR-0009', 's5', null, 'k5', 'u2', 4, 'Kids had so much fun with the party package. The bubble machine was the hit!', -43, null],
        ];
        foreach ($verified as [$code, $s, $p, $k, $c, $rating, $comment, $d, $reply]) {
            $rv = new Review([
                'supplier_id' => $this->S[$s], 'rental_request_id' => $this->R[$code], 'customer_id' => $this->U[$c],
                'product_id' => $p ? $this->P[$p] : null, 'package_id' => $k ? $this->K[$k] : null,
                'rating' => $rating, 'comment' => $comment, 'reply' => $reply, 'replied_at' => $reply ? $this->ts($d + 1) : null,
            ]);
            $rv->created_at = $this->ts($d);
            $rv->save();
        }
        // Ratings and review counts come only from the reviews above.
        \App\Domain\Reviews::recalcAll();
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

    private function notifications(): void
    {
        $rent = fn ($c) => route('rentals.show', $c, false);
        $sreq = fn ($c) => route('supplier.requests.show', $c, false);
        $rows = [
            ['u1', 'customer', 'Request accepted', "Happy Moments Party Rentals accepted PR-0001 (Sofia's 7th Birthday). Down payment is now due.", $rent('PR-0001'), -4, true],
            ['u1', 'customer', 'Payment verified', 'Your ₱787.00 down payment for PR-0001 was verified (₱500 reservation fee credited). Receipt OR-10002 is ready.', $rent('PR-0001'), -3, false],
            ['u1', 'customer', 'Request submitted', "PR-0005 (Camille's Debut) was sent to Celebration Hub Rentals and is pending review.", $rent('PR-0005'), -1, false],
            ['u2', 'supplier', 'New rental request', 'Jose Garcia requested 40 Monoblock Chairs for '.fmt_date($this->D(5)).'. Check for inventory conflicts.', $sreq('PR-0003'), -1, false],
            ['u2', 'supplier', 'New rental request', "Bea Mendoza sent PR-0004 (Baby Liam's Christening).", $sreq('PR-0004'), 0, false],
            ['u2', 'supplier', 'Return due', 'PR-0011 (Garcia Housewarming) items are due for return inspection.', $sreq('PR-0011'), -2, false],
            ['u2', 'supplier', 'Payment submitted', 'Maria Santos submitted ₱2,000.00 for PR-0001. Awaiting cashier verification.', $sreq('PR-0001'), 0, false],
            ['u2', 'customer', 'Rental completed', 'PR-0009 is complete and your ₱500 deposit was refunded.', $rent('PR-0009'), -44, true],
            ['u7', 'customer', 'Payment rejected', 'Your ₱6,000.00 installment for PR-0008 was rejected: reference number not found.', $rent('PR-0008'), -6, false],
            ['u3', 'supplier', 'New rental request', "Maria Santos sent PR-0005 (Camille's Debut).", $sreq('PR-0005'), -1, false],
            ['u17', 'supplier', 'New rental request', "Tess Villamor sent PR-0014 (Lola Nena's 80th) for delivery in Cebu City.", $sreq('PR-0014'), -1, false],
            ['cashier', 'cashier', 'New payment submission', 'Maria Santos submitted ₱2,000.00 (BPI-551093) for PR-0001.', route('cashier.verification', [], false), 0, false],
            ['cashier', 'cashier', 'New payment submission', 'Kristine Aquino submitted ₱5,850.00 (BDO-901233) for PR-0013.', route('cashier.verification', [], false), -1, false],
            ['admin', 'admin', 'New supplier application', "Bea Mendoza applied to open Bea's Balloon Corner.", '', -2, false],
            ['admin', 'admin', 'New complaint', 'Jose Garcia reported a payment issue on PR-0008.', '', -5, false],
            ['superadmin', 'superadmin', 'Suspicious account activity', 'Account miguel@demo.ph was suspended after 6 failed payment proofs.', '', -3, false],
        ];
        foreach ($rows as $i => [$to, $scope, $title, $body, $link, $d, $read]) {
            $isRole = in_array($to, ['admin', 'cashier', 'superadmin'], true);
            AppNotification::create([
                'user_id' => $isRole ? null : $this->U[$to], 'role' => $isRole ? $to : null, 'scope' => $scope, 'title' => $title, 'body' => $body,
                'link' => $link, 'read_by' => $read && ! $isRole ? [$this->U[$to]] : [], 'created_at' => $this->ts($d, 9 + ($i % 8)),
            ]);
        }
    }

    private function audit(): void
    {
        $rows = [
            ['u12', 'superadmin', 'Created admin account', 'Paolo Cruz (paolo.admin@partyrent.ph)', -100],
            ['u12', 'superadmin', 'Updated permissions', 'Paolo Cruz: removed suppliers.manage, applications.review', -99],
            ['u11', 'admin', 'Approved supplier application', 'Party Essentials Shop', -219],
            ['u11', 'admin', 'Suspended account', 'Miguel Torres (miguel@demo.ph) — repeated invalid payment proofs', -3],
            ['u10', 'cashier', 'Verified payment', 'TXN-10002 · ₱787.00 · PR-0001', -3],
            ['u10', 'cashier', 'Rejected payment', 'TXN-10011 · ₱6,000.00 · PR-0008 — reference not found', -6],
            ['u2', 'supplier', 'Accepted rental request', 'PR-0001 · Happy Moments Party Rentals', -4],
            ['u4', 'supplier', 'Completed return inspection', 'PR-0006 · ₱450.00 compensation deducted from deposit', -17],
            ['u12', 'superadmin', 'Changed platform setting', 'Overdue grace period set to 0 days', -30],
        ];
        foreach ($rows as [$actor, $role, $action, $details, $d]) {
            AuditLog::create(['actor_id' => $this->U[$actor], 'role' => $role, 'action' => $action, 'details' => $details, 'created_at' => $this->ts($d, 11)]);
        }
    }

    private function cart(): void
    {
        $d = fn ($start, $qtys) => array_combine(array_map(fn ($i) => add_days($start, $i), array_keys($qtys)), $qtys);
        CartLine::create(['user_id' => $this->U['u1'], 'supplier_id' => $this->S['s2'], 'type' => 'item', 'ref_id' => $this->P['p9'], 'days' => $d($this->D(12), [60, 60])]);
        CartLine::create(['user_id' => $this->U['u1'], 'supplier_id' => $this->S['s1'], 'type' => 'item', 'ref_id' => $this->P['p5'], 'days' => $d($this->D(12), [1])]);
    }
}
