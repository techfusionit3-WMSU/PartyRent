<?php

namespace App\Http\Controllers\Customer;

use App\Domain\Rentals;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class StartSellingController extends Controller
{
    public const CITIES = ['Quezon City', 'Manila', 'Makati City', 'Pasig City', 'Taguig City', 'Caloocan City', 'Marikina', 'Parañaque', 'Mandaluyong', 'Pasay', 'San Juan', 'Las Piñas', 'Muntinlupa', 'Valenzuela', 'Antipolo', 'Cavite', 'Laguna', 'Bulacan'];

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
            'existing' => $existing, 'cities' => self::CITIES, 'logos' => self::LOGOS,
            'd' => array_merge([
                'name' => '', 'description' => '', 'logo' => '🎈', 'contact_person' => $u->name, 'phone' => $u->phone, 'email' => $u->email,
                'address' => $u->address, 'city' => '', 'fulfillment' => 'both', 'delivery_fee' => 300, 'methods' => ['cod', 'bank'], 'arrangements' => ['down', 'full'],
                'hours' => 'Mon–Sat, 9:00 AM – 6:00 PM',
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
            'city' => 'required|string|max:80',
            'address' => 'required|string|min:8|max:255',
            'hours' => 'required|string|max:120',
            'fulfillment' => 'required|in:delivery,pickup,both',
            'delivery_fee' => 'nullable|numeric|min:0|max:100000',
            'methods' => 'required|array|min:1', 'methods.*' => 'in:cod,bank,debit',
            'arrangements' => 'required|array|min:1', 'arrangements.*' => 'in:full,down,installment,reservation',
            'reservationFee' => 'nullable|numeric|min:0',
            'reservationCredited' => 'required|in:0,1',
            'downType' => 'required|in:percent,fixed',
            'downValue' => 'nullable|numeric|min:0',
            'installments' => 'nullable|integer|min:2|max:6',
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
        return ['phone.regex' => 'Enter a valid PH mobile number (e.g. 0917 555 0101).', 'methods.required' => 'Select at least one payment method.', 'arrangements.required' => 'Select at least one payment arrangement.'];
    }

    /** Map validated input to supplier attributes, enforcing the policy rules from the prototype. */
    public static function toAttributes(array $v): array
    {
        $policy = [
            'reservationFee' => (float) ($v['reservationFee'] ?? 0), 'reservationCredited' => $v['reservationCredited'] === '1',
            'downType' => $v['downType'], 'downValue' => (float) ($v['downValue'] ?? 0), 'installments' => (int) ($v['installments'] ?? 2),
            'depositType' => $v['depositType'], 'depositValue' => (float) ($v['depositValue'] ?? 0), 'cancelDays' => (int) ($v['cancelDays'] ?? 0),
            'cancellation' => $v['cancellation'], 'returns' => $v['returns'], 'conditions' => $v['conditions'],
        ];
        $errors = [];
        if (in_array('reservation', $v['arrangements'], true) && ! $policy['reservationFee']) {
            $errors['reservationFee'] = 'Set a reservation fee to offer "Reservation Fee + Balance".';
        }
        if ($policy['downType'] === 'percent' && $policy['downValue'] > 100) {
            $errors['downValue'] = 'Down payment percentage cannot exceed 100%.';
        }
        if (in_array('down', $v['arrangements'], true) && ! $policy['downValue']) {
            $errors['downValue'] = 'Set a down payment value to offer "Down Payment".';
        }
        if ($policy['depositType'] === 'percent' && $policy['depositValue'] > 100) {
            $errors['depositValue'] = 'Deposit percentage cannot exceed 100%.';
        }
        if ($errors) {
            throw \Illuminate\Validation\ValidationException::withMessages($errors);
        }

        return [
            'name' => trim($v['name']), 'description' => trim($v['description']), 'logo' => $v['logo'], 'contact_person' => $v['contact_person'],
            'phone' => $v['phone'], 'email' => $v['email'], 'city' => $v['city'], 'address' => $v['address'], 'hours' => $v['hours'],
            'fulfillment' => $v['fulfillment'], 'delivery_fee' => $v['fulfillment'] === 'pickup' ? 0 : (float) ($v['delivery_fee'] ?? 0),
            'methods' => array_values(array_unique($v['methods'])), 'arrangements' => array_values(array_unique($v['arrangements'])), 'policy' => $policy,
        ];
    }
}
