<?php

namespace App\Http\Controllers\Customer;

use App\Domain\Applications;
use App\Domain\Finance;
use App\Domain\Locations;
use App\Domain\Rentals;
use App\Http\Controllers\Controller;
use App\Support\AddressInput;
use App\Support\DeliveryInput;
use Illuminate\Http\Request;

class StartSellingController extends Controller
{
    public const LOGOS = ['🎈', '🎉', '✨', '🎪', '💍', '🥳', '🎀', '🎂', '👑', '🌸', '🪩', '🎵'];

    public const STEPS = ['Supplier type', 'Applicant', 'Documents', 'Shop details', 'Delivery & payments', 'Payout', 'Policies', 'Review'];

    public function show(Request $request)
    {
        $u = $request->user();
        $existing = $u->supplier;
        if (! $existing && $u->staffOf) {
            return redirect()->route('profile')->with('error', 'You work for '.$u->staffOf->supplier->name.'. Ask them to remove you before opening your own shop.');
        }
        // Reference: an existing profile shows its status; rejected / revision ones can be edited (?edit=1) and resubmitted.
        if ($existing && (! in_array($existing->status, ['rejected', 'revision'], true) || ! $request->boolean('edit'))) {
            return view('customer.application-status', ['sp' => $existing]);
        }
        $app = array_replace_recursive([
            'type' => $existing?->type ?? '',
            'personal' => ['fullName' => $u->name, 'birthDate' => '', 'address' => $u->address, 'phone' => $u->phone, 'email' => $u->email],
            'identity' => ['idType' => Applications::ID_TYPES[0], 'idNumber' => ''],
            'business' => ['shopName' => '', 'address' => $u->address, 'productTypes' => '', 'phone' => $u->phone],
            'company' => ['legalName' => '', 'companyType' => 'corporation', 'address' => '', 'email' => '', 'phone' => '', 'natureOfBusiness' => 'Party and event equipment rental', 'description' => '', 'branches' => 1, 'website' => ''],
            'representative' => ['name' => $u->name, 'position' => '', 'idType' => Applications::ID_TYPES[0], 'email' => $u->email, 'phone' => $u->phone],
            'docs' => [], 'applicable' => [],
            'payout' => ['method' => 'bank', 'provider' => '', 'accountName' => '', 'accountNumber' => '', 'financeContact' => ''],
        ], (array) ($existing?->application ?? []));
        // Documents uploaded on a submit that failed validation are kept (see submit()).
        $app['docs'] = array_merge((array) ($app['docs'] ?? []), (array) session('apply_docs', []));

        return view('customer.start-selling', [
            'existing' => $existing, 'logos' => self::LOGOS, 'steps' => self::STEPS, 'app' => $app,
            'errorStep' => self::errorStep(array_keys(session('errors')?->getMessages() ?? [])),
            'd' => array_merge([
                'name' => '', 'description' => '', 'logo' => '🎈', 'phone' => $u->phone, 'email' => $u->email,
                'address_parts' => $u->address_parts, 'location_type' => 'shop', 'lat' => null, 'lng' => null, 'fulfillment' => 'both', 'methods' => ['cod', 'bank'],
                'hours' => 'Mon–Sat, 9:00 AM – 6:00 PM',
                'policy' => ['depositType' => 'fixed', 'depositValue' => 500, 'cancelDays' => 3, 'cancellation' => 'Free cancellation up to 3 days before the rental start date. The down payment is non-refundable.', 'returns' => 'Items must be returned clean and complete on the agreed date.', 'conditions' => 'Renter is responsible for damaged or missing items, charged according to our damage policy.'],
            ], $existing?->toArray() ?? []),
        ]);
    }

    /** Wizard step (0-based) that holds a field; keys like "methods.0" or "methods.*" belong to "methods". */
    public static function stepOfKey(string $k): int
    {
        $k = preg_replace('/\.(\*|\d+)$/', '', $k);

        return match (true) {
            $k === 'app.type' => 0,
            str_starts_with($k, 'app.personal'), str_starts_with($k, 'app.identity'), str_starts_with($k, 'app.company'), str_starts_with($k, 'app.representative') => 1,
            str_starts_with($k, 'docs'), str_starts_with($k, 'app.applicable') => 2,
            str_starts_with($k, 'app.payout') => 5,
            in_array($k, ['fulfillment', 'methods'], true) => 4,
            in_array($k, ['cancelDays', 'cancellation', 'returns', 'conditions'], true) => 6,
            default => 3,
        };
    }

    /** Wizard step that holds the first field with an error. */
    private static function errorStep(array $keys): int
    {
        return $keys ? min(array_map(self::stepOfKey(...), $keys)) : 7;
    }

    /** Validation rules and messages for the whole application (the final submit and each step's check use the same ones). */
    private static function applicationRules(Request $request): array
    {
        $phone = ['required', 'regex:/^(\+?63|0)9\d{2}[\s-]?\d{3}[\s-]?\d{4}$/'];
        $type = $request->input('app.type');
        $rules = [
            'app.type' => 'required|in:individual,organization',
            'name' => 'required|string|min:3|max:120', 'description' => 'required|string|min:20|max:2000', 'logo' => 'required|string|max:16',
            'phone' => $phone, 'email' => 'required|email|max:180', 'hours' => 'required|string|max:120',
            'location_type' => 'required|in:shop,home,none',
            ...AddressInput::rules('shop_addr', $request->input('location_type', 'shop') !== 'none'),
            'lat' => 'nullable|numeric|between:-90,90', 'lng' => 'nullable|numeric|between:-180,180|required_with:lat',
            'fulfillment' => 'required|in:delivery,pickup,both',
            'methods' => 'required|array|min:1', 'methods.*' => 'in:cod,bank,debit',
            'cancelDays' => 'nullable|integer|min:0|max:30', 'cancellation' => 'required|string|max:1000', 'returns' => 'required|string|max:1000', 'conditions' => 'required|string|max:1000',
            'app.applicable' => 'nullable|array', 'app.applicable.*' => 'in:'.implode(',', Applications::allDocKeys()),
            'docs' => 'nullable|array', 'docs.*' => 'file|mimes:jpg,jpeg,png,webp,pdf|max:10240',
            ...($type === 'organization' ? [
                'app.company.legalName' => 'required|string|min:3|max:180', 'app.company.companyType' => 'required|in:'.implode(',', array_keys(Applications::COMPANY_TYPES)),
                'app.company.branches' => 'required|integer|min:1|max:500', 'app.company.address' => 'required|string|min:8|max:300', 'app.company.email' => 'required|email|max:180',
                'app.company.phone' => $phone, 'app.company.natureOfBusiness' => 'required|string|max:180', 'app.company.description' => 'required|string|min:20|max:2000', 'app.company.website' => 'nullable|string|max:255',
                'app.representative.name' => 'required|string|max:120', 'app.representative.position' => 'required|string|max:120', 'app.representative.idType' => 'required|in:'.implode(',', Applications::ID_TYPES),
                'app.representative.email' => 'required|email|max:180', 'app.representative.phone' => $phone, 'app.payout.financeContact' => 'required|string|max:120',
            ] : [
                'app.personal.fullName' => 'required|string|min:3|max:120', 'app.personal.birthDate' => 'required|date|before_or_equal:'.add_days(today_iso(), -365 * 18),
                'app.personal.address' => 'required|string|min:8|max:300', 'app.personal.phone' => $phone, 'app.personal.email' => 'required|email|max:180',
                'app.identity.idType' => 'required|in:'.implode(',', Applications::ID_TYPES), 'app.identity.idNumber' => 'required|string|max:60',
                'app.business.productTypes' => 'required|string|max:300',
            ]),
            'app.payout.method' => 'required|in:bank,ewallet', 'app.payout.provider' => 'required|string|max:80', 'app.payout.accountName' => 'required|string|max:120', 'app.payout.accountNumber' => 'required|string|max:40',
        ];
        $badPhone = 'Enter a valid PH mobile number (e.g. 0917 555 0101).';
        $messages = [
            'app.type.required' => 'Choose Individual Supplier or Organization / Company Supplier.', 'methods.required' => 'Select at least one payment method.',
            'app.personal.birthDate.before_or_equal' => 'You must be at least 18 years old.',
            'phone.regex' => $badPhone, 'app.personal.phone.regex' => $badPhone, 'app.company.phone.regex' => $badPhone, 'app.representative.phone.regex' => $badPhone,
            ...AddressInput::messages('shop_addr'),
        ];

        return [$rules, $messages];
    }

    /** Checks that need more than one field (pickup pin). Keys point at the step to fix. */
    private static function crossChecks(Request $request): array
    {
        $errors = [];
        $in = $request->only(['location_type', 'fulfillment', 'lat', 'lng']) + ['location_type' => 'shop', 'fulfillment' => 'both', 'lat' => null, 'lng' => null];
        $errors += self::locationErrors($in, AddressInput::attributes((array) $request->input('shop_addr', []))['city_code']);

        return $errors;
    }

    /**
     * "Continue" in the wizard: validate only the current step's fields with the same rules as the final submit,
     * so a wrong number is caught on its own step instead of after the last one.
     */
    public function check(Request $request)
    {
        $step = (int) $request->input('step', 0);
        [$rules, $messages] = self::applicationRules($request);
        $rules = array_filter($rules, fn ($k) => self::stepOfKey($k) === $step && ! str_starts_with($k, 'docs'), ARRAY_FILTER_USE_KEY);
        $errors = \Illuminate\Support\Facades\Validator::make($request->all(), $rules, $messages)->errors()->toArray();
        // Pin and deposit rules depend on other steps; check them once the steps they need are filled in.
        // A missing pin is only known to matter once Delivery/Pickup is chosen (step 4); a pin that is placed is checked right away.
        if (! $errors && $step >= 3) {
            $hasPin = filled($request->input('lat')) && filled($request->input('lng'));
            foreach (self::crossChecks($request) as $k => $m) {
                if (self::stepOfKey($k) <= $step && ! ($step === 3 && $k === 'lat' && ! $hasPin)) {
                    $errors[$k] = [$m];
                }
            }
        }

        return $errors
            ? response()->json(['errors' => $errors, 'step' => self::errorStep(array_keys($errors))], 422)
            : response()->json(['ok' => true]);
    }

    public function submit(Request $request)
    {
        $u = $request->user();
        if ($u->staffOf) {
            return back()->with('error', 'Staff of another shop cannot open their own shop.');
        }
        $existing = $u->supplier;
        $type = $request->input('app.type');
        // Keep uploaded documents even if another field fails below: a browser cannot refill file inputs after a reload.
        $request->validate(['docs' => 'nullable|array', 'docs.*' => 'file|mimes:jpg,jpeg,png,webp,pdf|max:10240']);
        $stash = (array) session('apply_docs', []);
        foreach ((array) $request->file('docs', []) as $key => $file) {
            $stash[$key] = ['name' => mb_substr($file->getClientOriginalName(), 0, 120), 'path' => $file->store('applications/'.$u->id, 'local'), 'size' => $file->getSize(), 'uploadedAt' => now()->toIso8601String()];
        }
        session(['apply_docs' => $stash]);
        [$rules, $messages] = self::applicationRules($request);
        $v = $request->validate($rules, $messages);

        $in = (array) $request->input('app', []);
        $old = (array) ($existing?->application ?? []);
        $org = $type === 'organization';
        $app = ['type' => $type, 'applicable' => array_values((array) ($in['applicable'] ?? [])), 'payout' => $in['payout']];
        if ($org) {
            $app += ['company' => $in['company'], 'representative' => $in['representative']];
            $app['company']['branches'] = (int) $app['company']['branches'];
        } else {
            $addr0 = AddressInput::attributes($v['shop_addr'] ?? []);
            $app += ['personal' => $in['personal'], 'identity' => $in['identity'],
                'business' => ['shopName' => trim($v['name']), 'address' => $addr0['address'] ?: $in['personal']['address'], 'productTypes' => trim($in['business']['productTypes']), 'phone' => $v['phone']]];
        }
        // Documents: keep earlier uploads of the same supplier type, add new ones (private disk).
        $docs = ($old['type'] ?? null) === $type ? (array) ($old['docs'] ?? []) : [];
        $allowed = array_column(Applications::requirements($type, $app['company']['companyType'] ?? null), 'key');
        $docs = array_merge($docs, $stash);
        $app['docs'] = array_intersect_key($docs, array_flip($allowed));
        if ($e = Applications::validate($app)) {
            return back()->withInput()->withErrors([str_contains($e, 'document') ? 'docs' : 'app.type' => $e]);
        }
        $app['submittedAt'] = now()->toIso8601String();
        $app['history'] = array_merge((array) ($old['history'] ?? []), $existing ? [['status' => $existing->status, 'reason' => $existing->reject_reason ?: $existing->revision_note, 'at' => $existing->reviewed_at?->toIso8601String()]] : []);

        $addr = AddressInput::attributes($v['shop_addr'] ?? []);
        if ($loc = self::locationErrors($v, $addr['city_code'])) {
            throw \Illuminate\Validation\ValidationException::withMessages($loc);
        }
        $pin = isset($v['lat'], $v['lng']) && $v['lat'] !== null ? [round((float) $v['lat'], 7), round((float) $v['lng'], 7)] : [null, null];
        // Payment plans and the security deposit are not asked in the application (terms differ per event and package);
        // a new shop starts with the default plans and no deposit, and sets its own after approval.
        $plans = [];
        foreach (Finance::PLANS as $k) {
            $plans[$k] = array_merge(Finance::DEFAULT_PLANS[$k], (array) ($existing?->payment_plans[$k] ?? []));
        }
        $oldPol = (array) ($existing?->policy ?? []);
        $provider = strtolower(trim((string) $app['payout']['provider']));
        $bank = collect(array_keys(\App\Domain\XenditPayouts::BANKS))->first(fn ($b) => strtolower($b) === $provider);
        $payout = [];
        if ($app['payout']['method'] === 'ewallet' && str_contains($provider, 'gcash')) {
            $payout = ['payout_method' => 'gcash', 'payout_bank' => null];
        } elseif ($app['payout']['method'] === 'bank' && $bank) {
            $payout = ['payout_method' => 'bank', 'payout_bank' => $bank];
        }
        if ($payout) {
            $payout += ['payout_account_name' => $app['payout']['accountName'], 'payout_account_no' => $app['payout']['accountNumber']];
        }
        $attrs = [
            'name' => trim($v['name']), 'description' => trim($v['description']), 'logo' => $v['logo'],
            'contact_person' => $org ? $app['representative']['name'] : $app['personal']['fullName'],
            'phone' => $v['phone'], 'email' => $v['email'], 'hours' => $v['hours'],
            'city' => Locations::name($addr['city_code']), ...$addr,
            'location_type' => $v['location_type'], 'lat' => $pin[0], 'lng' => $pin[1],
            'fulfillment' => $v['fulfillment'], 'delivery_fee' => (float) ($existing?->delivery_fee ?? 0), // not asked in the application: set in Shop Settings (zones + standard rate)
            'delivery' => $existing?->delivery ?? \App\Models\Supplier::DELIVERY_DEFAULTS,
            'methods' => array_values(array_unique($v['methods'])), 'payment_plans' => $plans, 'arrangements' => [], // legacy column (v4 uses payment_plans)
            'policy' => array_merge($oldPol, ['depositType' => $oldPol['depositType'] ?? 'fixed', 'depositValue' => (float) ($oldPol['depositValue'] ?? 0), 'cancelDays' => (int) ($v['cancelDays'] ?? 0), 'cancellation' => $v['cancellation'], 'returns' => $v['returns'], 'conditions' => $v['conditions']]),
            'time_settings' => $existing?->time_settings ?: \App\Domain\Slots::DEFAULT,
            'damage_policy' => $existing?->damage_policy ?: \App\Domain\Damage::DEFAULT,
            'type' => $type, 'company' => $org ? ['legalName' => $app['company']['legalName'], 'companyType' => $app['company']['companyType']] : null,
            'application' => $app, 'revision_note' => null,
            // app-only flags kept in step with the supplier type
            'account_type' => $org ? 'business' : 'personal', 'biz_status' => $org ? 'pending' : null,
        ] + $payout;
        $res = Rentals::submitApplication($u, $attrs);
        if (! $res['ok']) {
            return back()->withInput()->with('error', $res['error']);
        }
        session()->forget('apply_docs');

        return redirect()->route('start-selling')->with('success', $res['supplier']->status === 'approved' ? 'Your shop is approved!' : 'Application submitted for review.');
    }

    /**
     * Personal / Business choice and business documents → supplier attributes.
     * A business is checked by an admin (biz_status pending) before it can add staff.
     */
    public static function business(Request $request, ?\App\Models\Supplier $existing): array
    {
        $type = $request->input('account_type', 'personal');
        $hasDoc = (bool) $existing?->biz_doc_path;
        $v = $request->validate([
            'account_type' => 'nullable|in:personal,business',
            'biz_reg_type' => 'required_if:account_type,business|nullable|in:'.implode(',', array_keys(\App\Models\Supplier::REG_TYPES)),
            'biz_reg_no' => 'required_if:account_type,business|nullable|string|max:60',
            'biz_tin' => ['required_if:account_type,business', 'nullable', 'regex:/^\d{3}-?\d{3}-?\d{3}(-?\d{3,5})?$/'],
            'biz_doc' => [($type === 'business' && ! $hasDoc) ? 'required' : 'nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:8192'],
        ], [
            'biz_reg_type.required_if' => 'Choose where the business is registered.',
            'biz_reg_no.required_if' => 'Enter the registration number.',
            'biz_tin.required_if' => 'Enter the business TIN.',
            'biz_tin.regex' => 'Enter the TIN like 123-456-789-000.',
            'biz_doc.required' => 'Upload the business permit or registration certificate.',
        ]);
        if (($v['account_type'] ?? 'personal') !== 'business') {
            return ['account_type' => 'personal', 'biz_status' => null];
        }
        $doc = $request->hasFile('biz_doc') ? $request->file('biz_doc')->store('business-docs', 'local') : $existing?->biz_doc_path;

        return [
            'account_type' => 'business', 'biz_reg_type' => $v['biz_reg_type'], 'biz_reg_no' => trim($v['biz_reg_no']), 'biz_tin' => trim($v['biz_tin']),
            'biz_doc_path' => $doc, 'biz_status' => 'pending', 'biz_reject_reason' => null, 'biz_verified_at' => null,
        ];
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
    public static function locationErrors(array $v, ?string $cityCode): array
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
            'free_ship' => $v['fulfillment'] !== 'pickup' && ($v['free_ship'] ?? '0') === '1',
            'methods' => array_values(array_unique($v['methods'])), 'arrangements' => array_values(array_unique($v['arrangements'])), 'policy' => $policy,
        ];
    }
}
