<?php

namespace App\Http\Controllers\Customer;

use App\Domain\Locations;
use App\Domain\Rentals;
use App\Http\Controllers\Controller;
use App\Support\AddressInput;
use App\Support\DeliveryInput;
use Illuminate\Http\Request;

class StartSellingController extends Controller
{
    public const LOGOS = ['🎈', '🎉', '✨', '🎪', '💍', '🥳', '🎀', '🎂', '👑', '🌸', '🪩', '🎵'];

    public function show(Request $request)
    {
        $u = $request->user();
        $existing = $u->supplier;
        if ($existing && $existing->status !== 'rejected' && ! $request->boolean('edit')) {
            return view('customer.application-status', ['sp' => $existing]);
        }
        $d = $existing?->toArray() ?? [];

        return view('customer.start-selling', [
            'existing' => $existing, 'logos' => self::LOGOS, 'previewCity' => $u->city_code,
            'd' => array_merge([
                'name' => '', 'description' => '', 'logo' => '🎈', 'contact_person' => $u->name, 'phone' => $u->phone, 'email' => $u->email,
                'address_parts' => $u->address_parts, 'location_type' => 'shop', 'lat' => null, 'lng' => null, 'fulfillment' => 'both', 'methods' => ['cod', 'bank'], 'arrangements' => ['down', 'full'],
                'hours' => 'Mon–Sat, 9:00 AM – 6:00 PM',
                // A sensible first zone: the applicant's own city.
                'delivery' => array_merge(\App\Models\Supplier::DELIVERY_DEFAULTS, ['zones' => $u->city_code ? [['level' => 'city', 'code' => $u->city_code, 'fee' => 300]] : []]),
            ], $d),
            'p' => array_merge(['reservationFee' => 0, 'reservationCredited' => true, 'downType' => 'percent', 'downValue' => 30, 'depositType' => 'fixed', 'depositValue' => 500, 'installments' => 2, 'cancelDays' => 3, 'cancellation' => 'Free cancellation up to 3 days before the rental start date.', 'returns' => 'Items must be returned clean and complete on the agreed date.', 'conditions' => 'Renter is responsible for damaged or missing items.'], $existing->policy ?? []),
        ]);
    }

    public function submit(Request $request)
    {
        $v = $request->validate(self::rules(), self::messages());
        $res = Rentals::submitApplication($request->user(), self::toAttributes($v));
        if (! $res['ok']) {
            return back()->withInput()->with('error', $res['error']);
        }

        return redirect()->route('start-selling')->with('success', $res['supplier']->status === 'approved' ? 'Your shop is approved!' : 'Application submitted for review.');
    }

    /** Validation shared with the supplier's Shop Profile page. */
    public static function rules(): array
    {
        return [
            'name' => 'required|string|min:3|max:120',
            'description' => 'required|string|min:20|max:2000',
            'logo' => 'required|string|max:16',
            'contact_person' => 'required|string|max:120',
            'phone' => ['required', 'regex:/^(\+?63|0)9\d{2}[\s-]?\d{3}[\s-]?\d{4}$/'],
            'email' => 'required|email|max:180',
            'location_type' => 'required|in:shop,home,none',
            // Sellers without a pickup location only need to name their city.
            ...AddressInput::rules('shop_addr', request('location_type', 'shop') !== 'none'),
            'lat' => 'nullable|numeric|between:-90,90',
            'lng' => 'nullable|numeric|between:-180,180|required_with:lat',
            'hours' => 'required|string|max:120',
            'fulfillment' => 'required|in:delivery,pickup,both',
            ...DeliveryInput::rules(),
            'methods' => 'required|array|min:1', 'methods.*' => 'in:cod,bank,debit',
            'arrangements' => 'required|array|min:1', 'arrangements.*' => 'in:full,down,installment,reservation',
            'reservationFee' => 'nullable|numeric|min:0',
            'reservationCredited' => 'required|in:0,1',
            'downType' => 'required|in:percent,fixed',
            'downValue' => 'nullable|numeric|min:0',
            'installments' => 'nullable|integer|min:0', // 2–6 enforced in toAttributes() only when Installment is offered
            'depositType' => 'required|in:percent,fixed',
            'depositValue' => 'nullable|numeric|min:0',
            'cancelDays' => 'nullable|integer|min:0|max:30',
            'cancellation' => 'required|string|max:1000',
            'returns' => 'required|string|max:1000',
            'conditions' => 'required|string|max:1000',
        ];
    }

    public static function messages(): array
    {
        return array_merge(AddressInput::messages('shop_addr'), ['phone.regex' => 'Enter a valid PH mobile number (e.g. 0917 555 0101).', 'methods.required' => 'Select at least one payment method.', 'arrangements.required' => 'Select at least one payment arrangement.']);
    }

    /** Location type ↔ fulfillment ↔ pin rules. Not every seller has a shop, but anyone offering pickup needs a pin. */
    private static function locationErrors(array $v, ?string $cityCode): array
    {
        $errors = [];
        $type = $v['location_type'];
        $hasPin = isset($v['lat'], $v['lng']) && $v['lat'] !== null && $v['lng'] !== null;
        if ($type === 'none' && $v['fulfillment'] !== 'delivery') {
            $errors['fulfillment'] = 'Without a pickup location you can only offer delivery. Choose "Delivery only", or pick a different location type.';
        }
        if ($v['fulfillment'] !== 'delivery' && $type !== 'none' && ! $hasPin) {
            $errors['lat'] = 'Drop a pin on the map so customers who pick up can find you.';
        }
        if ($hasPin) {
            $lat = (float) $v['lat'];
            $lng = (float) $v['lng'];
            $max = (float) config('partyrent.map.max_km_from_city', 60);
            $centre = Locations::get($cityCode);
            if (! Locations::inBounds($lat, $lng)) {
                $errors['lat'] = 'That pin is outside the map area we serve. Move it to your location.';
            } elseif ($centre && $centre['lat'] !== null && Locations::straightKm([$lat, $lng], [$centre['lat'], $centre['lng']]) > $max) {
                $errors['lat'] = 'Your pin is more than '.(int) $max.' km from '.$centre['name'].'. Check the pin or the city you selected.';
            }
        }

        return $errors;
    }

    /** Map validated input to supplier attributes, enforcing the policy rules from the prototype. */
    public static function toAttributes(array $v): array
    {
        $offers = fn (string $a) => in_array($a, $v['arrangements'], true);
        $installments = (int) ($v['installments'] ?? 2);
        $policy = [
            // Pricing applies any reservation fee > 0 to down/installment bookings too, so only keep it while Reservation is offered.
            'reservationFee' => $offers('reservation') ? (float) ($v['reservationFee'] ?? 0) : 0.0, 'reservationCredited' => $v['reservationCredited'] === '1',
            'downType' => $v['downType'], 'downValue' => (float) ($v['downValue'] ?? 0), 'installments' => max(2, min(6, $installments)),
            'depositType' => $v['depositType'], 'depositValue' => (float) ($v['depositValue'] ?? 0), 'cancelDays' => (int) ($v['cancelDays'] ?? 0),
            'cancellation' => $v['cancellation'], 'returns' => $v['returns'], 'conditions' => $v['conditions'],
        ];
        $errors = [];
        if ($offers('reservation') && $policy['reservationFee'] <= 0) {
            $errors['reservationFee'] = 'Set a reservation fee to offer "Reservation Fee + Balance".';
        }
        if ($offers('down')) {
            if ($policy['downValue'] <= 0) {
                $errors['downValue'] = 'Set a down payment value to offer "Down Payment".';
            } elseif ($policy['downType'] === 'percent' && $policy['downValue'] > 100) {
                $errors['downValue'] = 'Down payment percentage cannot exceed 100%.';
            }
        } elseif ($policy['downType'] === 'percent') {
            $policy['downValue'] = min(100, $policy['downValue']);
        }
        if ($offers('installment') && ($installments < 2 || $installments > 6)) {
            $errors['installments'] = 'Choose between 2 and 6 installments.';
        }
        if ($policy['depositType'] === 'percent' && $policy['depositValue'] > 100) {
            $errors['depositValue'] = 'Deposit percentage cannot exceed 100%.';
        }
        $addr = AddressInput::attributes($v['shop_addr']);
        $errors += self::locationErrors($v, $addr['city_code']);
        if ($errors) {
            throw \Illuminate\Validation\ValidationException::withMessages($errors);
        }
        $pin = isset($v['lat'], $v['lng']) && $v['lat'] !== null ? [round((float) $v['lat'], 7), round((float) $v['lng'], 7)] : [null, null];

        return [
            'name' => trim($v['name']), 'description' => trim($v['description']), 'logo' => $v['logo'], 'contact_person' => $v['contact_person'],
            'phone' => $v['phone'], 'email' => $v['email'], 'hours' => $v['hours'],
            'city' => Locations::name($addr['city_code']), ...$addr,
            'location_type' => $v['location_type'], 'lat' => $pin[0], 'lng' => $pin[1],
            'fulfillment' => $v['fulfillment'], 'delivery' => DeliveryInput::toSettings($v['delivery'] ?? [], $v['fulfillment']),
            'methods' => array_values(array_unique($v['methods'])), 'arrangements' => array_values(array_unique($v['arrangements'])), 'policy' => $policy,
        ];
    }
}
