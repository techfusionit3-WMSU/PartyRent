<?php

namespace Database\Seeders;

use App\Models\InventoryLocation;
use App\Models\RentalRequest;
use App\Models\Supplier;
use App\Models\User;
use App\Support\AddressInput;
use Illuminate\Database\Seeder;

/**
 * Moves the demo shops (and the demo people who rent from them) to Zamboanga City.
 *
 * DemoSeeder runs this at the end of every normal seed, so a fresh demo is in Zamboanga. The automated tests keep the
 * original Metro Manila demo, because many of them check distances and places there. It only touches the demo
 * records it knows by shop name, branch name and e-mail; anything else (real shops, real accounts) is left alone.
 * Safe to run again: `php artisan db:seed --class=ZamboangaDemoSeeder`.
 */
class ZamboangaDemoSeeder extends Seeder
{
    public const CITY = '097332000'; // Zamboanga City

    /** shop => [street, barangay, lat, lng, description, delivery zone names (in order)] */
    private const SHOPS = [
        'Happy Moments Party Rentals' => ['88 Gov. Lim Ave.', 'Sta. Maria', 6.9290, 122.0700,
            'Family-run party rentals serving Zamboanga City since 2016. Birthday setups, kiddie parties, and backyard celebrations made easy.',
            ['Sta. Maria / Tetuan', 'Putik / Lunzuran', 'Ayala / Recodo']],
        'Celebration Hub Rentals' => ['12 Mayor Jaldon St.', 'Canelar', 6.9150, 122.0730,
            'Elegant Tiffany chairs, lights, and floral backdrops for debuts, weddings and milestone events around the Zamboanga City proper.',
            ['Canelar / Zone IV', 'Tumaga / Pasonanca']],
        'Dream Events Supplies' => ['401 Veterans Ave.', 'Tumaga', 6.9400, 122.0780,
            'High-volume tents, banquet tables and pro sound for corporate events and big gatherings. Warehouse pickup in Tumaga.', []],
        'Elegant Occasion Rentals' => ['5 Pilar St.', 'Zone IV', 6.9060, 122.0760,
            'Premium chiavari chairs, gold-and-white backdrops and lounge furniture for weddings and formal receptions across Zamboanga City.',
            ['City proper', 'East coast (Putik / Talon-Talon)']],
        'Party Essentials Shop' => ['1530 Pres. Corazon Aquino Ave.', 'Guiwan', 6.9260, 122.0920,
            'Affordable kiddie party must-haves in Zamboanga City: small chairs, kiddie tables, bubble machines, photo booth props and more.',
            ['Zamboanga City']],
        "Bea's Balloon Corner" => ['19 Don Alfaro St.', 'Tetuan', 6.9200, 122.0850,
            'Custom balloon garlands, arches and pastel party décor in Tetuan and nearby barangays.', []],
        'Aquino Events Corp.' => ['120 Maria Clara Lorenzo Lobregat Hwy.', 'Divisoria', 6.9530, 122.1050,
            'Corporate event production company with two warehouses, offering staging, tents, lights and furniture for company events in Zamboanga City.', []],
        'Grand Fiesta Events Co.' => ['88 Nuñez St.', 'Camino Nuevo', 6.9110, 122.0690, null, []],
    ];

    /** [shop, old branch name] => [new name, street, barangay, lat, lng] */
    private const BRANCHES = [
        ['Happy Moments Party Rentals', 'Main Warehouse', 'Main Warehouse', '88 Gov. Lim Ave.', 'Sta. Maria', 6.9290, 122.0700],
        ['Happy Moments Party Rentals', 'Branch A – Marikina', 'Branch A – Putik', '7 Lunzuran Rd.', 'Putik', 6.9420, 122.1010],
        ['Celebration Hub Rentals', 'Pasig Showroom', 'Canelar Showroom', '12 Mayor Jaldon St.', 'Canelar', 6.9150, 122.0730],
        ['Dream Events Supplies', 'Makati Warehouse', 'Tumaga Warehouse', '401 Veterans Ave.', 'Tumaga', 6.9400, 122.0780],
        ['Elegant Occasion Rentals', 'Main Warehouse', 'Main Warehouse', 'Lot 4, Pilar St.', 'Zone IV', 6.9060, 122.0760],
        ['Elegant Occasion Rentals', 'Branch A – Pasig', 'Branch A – Tetuan', 'Don Alfaro St.', 'Tetuan', 6.9170, 122.0880],
        ['Elegant Occasion Rentals', 'Branch B – Makati', 'Branch B – Baliwasan', 'San Jose Rd.', 'Baliwasan', 6.9140, 122.0610],
        ['Party Essentials Shop', 'Sta. Cruz Shop', 'Guiwan Shop', '1530 Pres. Corazon Aquino Ave.', 'Guiwan', 6.9260, 122.0920],
        ["Bea's Balloon Corner", 'Home studio', 'Home studio', '19 Don Alfaro St.', 'Tetuan', 6.9200, 122.0850],
        ['Aquino Events Corp.', 'Parañaque Warehouse', 'Divisoria Warehouse', '120 Maria Clara Lorenzo Lobregat Hwy.', 'Divisoria', 6.9530, 122.1050],
        ['Aquino Events Corp.', 'Las Piñas Warehouse', 'Ayala Warehouse', 'Ayala Highway', 'Ayala', 6.9750, 121.9480],
        ['Grand Fiesta Events Co.', 'Main branch', 'Main branch', '88 Nuñez St.', 'Camino Nuevo', 6.9110, 122.0690],
    ];

    /** demo e-mail => [street, barangay] */
    private const PEOPLE = [
        'maria@demo.ph' => ['23 Mabini St.', 'Tetuan'], 'juan@demo.ph' => ['88 Gov. Lim Ave.', 'Sta. Maria'],
        'ana@demo.ph' => ['12 Mayor Jaldon St.', 'Canelar'], 'carlo@demo.ph' => ['401 Veterans Ave.', 'Tumaga'],
        'liza@demo.ph' => ['5 Pilar St.', 'Zone IV'], 'ramon@demo.ph' => ['1530 Pres. Corazon Aquino Ave.', 'Guiwan'],
        'jose@demo.ph' => ['7 Sampaguita St.', 'Putik'], 'bea@demo.ph' => ['19 Don Alfaro St.', 'Tetuan'],
        'kristine@demo.ph' => ['33 San Jose Rd.', 'Baliwasan'], 'miguel@demo.ph' => ['2 Gen. Luna St.', 'Camino Nuevo'],
        'events@demo.ph' => ['88 Nuñez St.', 'Camino Nuevo'],
        'cashier@elegantoccasion.ph' => ['5 Pilar St.', 'Zone IV'], 'inventory@elegantoccasion.ph' => ['5 Pilar St.', 'Zone IV'],
        'staff@elegantoccasion.ph' => ['Don Alfaro St.', 'Tetuan'], 'desk@elegantoccasion.ph' => ['5 Pilar St.', 'Zone IV'],
        'coordinator@grandfiesta.ph' => ['88 Nuñez St.', 'Camino Nuevo'], 'finance@grandfiesta.ph' => ['88 Nuñez St.', 'Camino Nuevo'],
        'crew@grandfiesta.ph' => ['88 Nuñez St.', 'Camino Nuevo'],
        'admin@partyrent.ph' => ['PartyRent HQ, Nuñez St.', 'Camino Nuevo'], 'paolo.admin@partyrent.ph' => ['PartyRent HQ, Nuñez St.', 'Camino Nuevo'],
        'super@partyrent.ph' => ['PartyRent HQ, Nuñez St.', 'Camino Nuevo'], 'cashier@partyrent.ph' => ['PartyRent HQ, Nuñez St.', 'Camino Nuevo'],
    ];

    /** Metro Manila words in demo rental addresses => Zamboanga words (open rentals read sensibly). */
    private const WORDS = [
        'Brgy. Kamuning, Quezon City' => 'Brgy. Tetuan, Zamboanga City', 'Loyola Heights, Quezon City' => 'Sta. Maria, Zamboanga City',
        'Quezon City' => 'Zamboanga City', 'Marikina' => 'Putik, Zamboanga City', 'Pasig City' => 'Canelar, Zamboanga City', 'Pasig' => 'Canelar',
        'Makati City' => 'Tumaga, Zamboanga City', 'Makati' => 'Tumaga', 'Bonifacio Global City, Taguig' => 'Zone IV, Zamboanga City',
        'Taguig City' => 'Zamboanga City', 'Taguig' => 'Zone IV', 'Metro Manila' => 'Zamboanga City', 'Manila' => 'Zamboanga City',
        'Caloocan' => 'Tetuan', 'Parañaque' => 'Divisoria', 'Las Piñas' => 'Ayala', 'Mandaluyong' => 'Tumaga',
    ];

    private function addr(string $street, string $barangay): array
    {
        return AddressInput::attributes(['cityCode' => self::CITY, 'barangay' => $barangay, 'street' => $street, 'landmark' => '']);
    }

    public function run(): void
    {
        foreach (self::SHOPS as $name => [$street, $brgy, $lat, $lng, $desc, $zones]) {
            $s = Supplier::where('name', $name)->first();
            if (! $s) {
                continue;
            }
            $a = $this->addr($street, $brgy);
            $z = (array) $s->delivery_zones;
            foreach ($zones as $i => $zn) {
                if (isset($z[$i])) {
                    $z[$i]['name'] = $zn;
                }
            }
            $data = ['address' => $a['address'], 'address_parts' => $a['address_parts'], 'city_code' => self::CITY, 'city' => 'Zamboanga City', 'lat' => $lat, 'lng' => $lng, 'delivery_zones' => $z ?: $s->delivery_zones]
                + ($desc ? ['description' => $desc] : []);
            if ($s->isOrganization()) { // event company: where it works
                $prof = (array) $s->business_profile;
                if (isset($prof['settings'])) {
                    $prof['settings']['serviceAreas'] = ['Zamboanga City'];
                    $prof['settings']['travelNote'] = 'Free within Zamboanga City';
                    $data['business_profile'] = $prof;
                }
            }
            $s->forceFill($data)->saveQuietly();
        }
        foreach (self::BRANCHES as [$shop, $old, $new, $street, $brgy, $lat, $lng]) {
            $s = Supplier::where('name', $shop)->first();
            $b = $s ? InventoryLocation::where('supplier_id', $s->id)->whereIn('name', array_unique([$old, $new]))->first() : null;
            if (! $b) {
                continue;
            }
            $a = $this->addr($street, $brgy);
            $b->forceFill(['name' => $new, 'address' => $a['address'], 'address_parts' => $a['address_parts'], 'city_code' => self::CITY, 'lat' => $lat, 'lng' => $lng])->saveQuietly();
        }
        foreach (self::PEOPLE as $email => [$street, $brgy]) {
            $u = User::where('email', $email)->first();
            if ($u) {
                $a = $this->addr($street, $brgy);
                $u->forceFill(['address' => $a['address'], 'address_parts' => $a['address_parts'], 'city_code' => self::CITY, 'near' => null])->saveQuietly();
            }
        }
        // demo rentals: addresses and delivery destinations read as Zamboanga places
        $swap = fn (?string $t) => $t === null ? null : strtr($t, self::WORDS);
        foreach (RentalRequest::whereIn('supplier_id', Supplier::whereIn('name', array_keys(self::SHOPS))->pluck('id'))->get() as $r) {
            $dest = array_map(fn ($d) => is_array($d) && isset($d['address']) ? array_merge($d, ['address' => $swap($d['address'])]) : $d, (array) $r->destinations);
            $r->forceFill(['address' => $swap($r->address), 'destinations' => $dest, 'city_code' => $r->city_code ? self::CITY : null])->saveQuietly();
        }
        // the event company's bookings: venues in Zamboanga City
        foreach (\App\Models\EventBooking::whereIn('supplier_id', Supplier::whereIn('name', array_keys(self::SHOPS))->pluck('id'))->get() as $e) {
            $e->forceFill(['venue' => $swap($e->venue), 'city' => 'Zamboanga City'])->saveQuietly();
        }
        inv()->flush();
    }
}
