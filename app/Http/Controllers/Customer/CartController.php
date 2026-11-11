<?php

namespace App\Http\Controllers\Customer;

use App\Domain\Ai;
use App\Domain\Finance;
use App\Domain\Locations;
use App\Domain\OnlinePay;
use App\Domain\Pricing;
use App\Domain\Rentals;
use App\Http\Controllers\Controller;
use App\Models\CartLine;
use App\Models\RentalRequest;
use App\Models\Supplier;
use App\Support\AddressInput;
use Illuminate\Http\Request;

class CartController extends Controller
{
    public function index(Request $request)
    {
        $u = $request->user();
        $city = customer_city(); // estimate delivery to the customer's saved / chosen city
        $groups = [];
        foreach (Rentals::cartGroups($u) as $sid => $lines) {
            $s = Supplier::find($sid);
            if (! $s) {
                continue;
            }
            $ful = $s->offersDelivery() ? 'delivery' : 'pickup';
            $clean = array_map(fn ($l) => array_merge($l, ['days' => Rentals::cleanDays($l['days'])]), $lines);
            $groups[] = [
                'supplier' => $s, 'lines' => $lines,
                'quote' => $q = Pricing::quote($clean, $s, $ful, 'full', $city),
                'delivery' => $q['delivery'],
                'check' => inv()->check($lines),
                'compareQuote' => Pricing::quote($clean, $s, $ful, in_array('down', $s->arrangements, true) ? 'down' : $s->arrangements[0], $city),
            ];
        }

        return view('customer.cart', ['groups' => $groups, 'compare' => $request->boolean('compare'), 'city' => $city]);
    }

    public function add(Request $request)
    {
        $data = $request->validate(['type' => 'required|in:item,package', 'ref_id' => 'required|integer', 'days' => 'required|string|max:5000', 'sel' => 'nullable|string|max:5000', 'then' => 'nullable|in:list,checkout']);
        $sel = json_decode((string) ($data['sel'] ?? ''), true);
        $res = Rentals::addToCart($request->user(), $data['type'], (int) $data['ref_id'], (array) json_decode($data['days'], true), is_array($sel) ? $sel : null);
        if (! $res['ok']) {
            return back()->with('error', $res['error']);
        }
        if (($data['then'] ?? 'list') === 'checkout') {
            return redirect()->route('checkout', $res['supplier_id']);
        }

        return back()->with('success', $res['name'].' added to your Rental List.');
    }

    public function update(Request $request, CartLine $line)
    {
        abort_unless($line->user_id === $request->user()->id, 404);
        $res = Rentals::updateCartLine($request->user(), $line, (array) $request->input('days', []));

        return response()->json($res, $res['ok'] ? 200 : 422);
    }

    public function remove(Request $request, CartLine $line)
    {
        abort_unless($line->user_id === $request->user()->id, 404);
        $line->delete();

        return back()->with('success', 'Item removed from your Rental List.');
    }

    public function checkout(Request $request, Supplier $supplier)
    {
        $u = $request->user();
        if ($supplier->owner_id === $u->id) {
            return redirect()->route('cart')->with('error', 'You cannot rent from your own shop. Remove these items from your rental list.');
        }
        $c = $this->checkoutState($request, $supplier);
        if (is_string($c)) {
            return redirect()->route('cart')->with('warn', $c);
        }
        // Address shown in the form: what was typed (validation error) > a city picked before reloading > the saved address.
        $city = $c['city'];
        $addr = old('addr') ?: ($city && $city !== $u->city_code ? Locations::parentsOf($city) : ($u->address_parts ?: []));

        return view('customer.checkout', array_merge($c, [
            's' => $supplier, 'u' => $u, 'addr' => $addr,
            'eventTypes' => array_merge(array_keys(Ai::EVENTS), ['Community', 'Other']),
        ]));
    }

    /** Live re-quote while the customer changes the delivery city (checkout page). */
    public function quote(Request $request, Supplier $supplier)
    {
        $c = $this->checkoutState($request, $supplier);
        abort_if(is_string($c), 422, is_string($c) ? $c : '');
        $q = $c['quote'];

        return response()->json([
            'ok' => $c['st']['fulfillment'] !== 'delivery' || ($q['delivery']['ok'] ?? false),
            'deliveryHtml' => view('customer._delivery-quote', ['d' => $q['delivery'], 's' => $supplier])->render(),
            'summaryHtml' => view('customer._checkout-summary', ['q' => $q, 'schedule' => $c['schedule'], 'due' => $c['due']])->render(),
        ]);
    }

    /** Quote + payment schedule for the checkout page, or a message when there is nothing to check out. */
    private function checkoutState(Request $request, Supplier $supplier): array|string
    {
        $lines = Rentals::cartLinesFor($request->user(), $supplier->id);
        if (! $lines) {
            return 'Your rental list has no items from this supplier.';
        }
        $dates = collect($lines)->flatMap(fn ($l) => array_keys(Rentals::cleanDays($l['days'])))->unique()->sort()->values()->all();
        if (! $dates) {
            return 'Set at least one quantity before checking out.';
        }
        $st = [
            'fulfillment' => old('fulfillment', $request->query('fulfillment', $supplier->offersDelivery() ? 'delivery' : 'pickup')),
            'arrangement' => old('arrangement', $request->query('arrangement', in_array('down', $supplier->arrangements, true) ? 'down' : $supplier->arrangements[0])),
        ];
        if (! in_array($st['arrangement'], $supplier->arrangements, true)) {
            $st['arrangement'] = $supplier->arrangements[0];
        }
        if ($st['fulfillment'] === 'delivery' && ! $supplier->offersDelivery()) {
            $st['fulfillment'] = 'pickup';
        }
        if ($st['fulfillment'] === 'pickup' && ! $supplier->offersPickup()) {
            $st['fulfillment'] = 'delivery';
        }
        // An explicit ?city= (even empty, from the live re-quote) wins over the saved city.
        $city = old('addr.cityCode') ?? ($request->has('city') ? $request->query('city') : customer_city());
        $city = Locations::isCity($city) ? $city : null;
        $clean = array_map(fn ($l) => array_merge($l, ['days' => Rentals::cleanDays($l['days'])]), $lines);
        $quote = Pricing::quote($clean, $supplier, $st['fulfillment'], $st['arrangement'], $city);

        $due = [];
        foreach ($supplier->methods as $m) {
            $probe = new RentalRequest(['start_date' => $dates[0], 'method' => $m, 'status' => 'pending']);
            $probe->setRelation('supplier', $supplier);
            $due[$m] = Finance::upfront($probe, $quote);
        }

        return [
            'dates' => $dates, 'st' => $st, 'quote' => $quote, 'city' => $city,
            'check' => inv()->check($clean),
            'schedule' => Finance::schedule($quote, today_iso(), $dates[0], $supplier->methods[0]),
            'due' => $due, 'online' => OnlinePay::enabled(),
        ];
    }

    public function submit(Request $request, Supplier $supplier)
    {
        $data = $request->validate([
            'eventName' => 'required|string|min:3|max:120',
            'eventType' => 'required|string|max:40',
            'eventDate' => 'required|date',
            'fulfillment' => 'required|in:delivery,pickup',
            ...AddressInput::rules('addr', true, $request->input('fulfillment') === 'delivery'),
            'contactName' => 'required|string|max:120',
            'contactPhone' => ['required', 'regex:/^(\+?63|0)9\d{2}[\s-]?\d{3}[\s-]?\d{4}$/'],
            'instructions' => 'nullable|string|max:1000',
            'arrangement' => 'required|in:full,down,installment,reservation',
            'method' => 'required|in:cod,bank,debit',
            'payWith' => 'nullable|in:online,transfer',
            'agree' => 'accepted',
        ], array_merge(AddressInput::messages(), ['contactPhone.regex' => 'Enter a valid PH mobile number (e.g. 0917 555 0101).', 'agree.accepted' => 'Please accept the policies.']));
        $dates = collect(Rentals::cartLinesFor($request->user(), $supplier->id))->flatMap(fn ($l) => array_keys(Rentals::cleanDays($l['days'])))->sort()->values();
        if ($dates->isNotEmpty() && ($data['eventDate'] < $dates->first() || $data['eventDate'] > $dates->last())) {
            return back()->withInput()->with('error', 'Event date should fall within your rental dates.');
        }
        $res = Rentals::submitRequest($request->user(), $supplier, $data);
        if (! $res['ok']) {
            return back()->withInput()->with('error', $res['error']);
        }
        if ($data['fulfillment'] === 'delivery' && ! $request->user()->city_code) {
            $request->user()->update(AddressInput::attributes($data['addr'])); // first delivery address becomes the saved one
        }

        // Like a marketplace checkout: go straight to paying the amount that confirms the request.
        $r = $res['request']->fresh(['payments', 'supplier', 'customer']);
        $up = $r->fin()['upfront'];
        if (($data['payWith'] ?? 'transfer') === 'online' && OnlinePay::enabled()) {
            $pay = OnlinePay::start($r, ['type' => $up['type'], 'amount' => $up['amount']]);
            if ($pay['ok']) {
                return redirect()->away($pay['url']);
            }

            return redirect()->route('rentals.show', ['rentalRequest' => $r, 'pay' => 1])->with('warn', 'Request '.$r->code.' saved, but online payment could not start: '.$pay['error']);
        }

        return redirect()->route('rentals.show', ['rentalRequest' => $r, 'pay' => 1])
            ->with('success', 'Request '.$r->code.' saved. Pay the '.strtolower($up['label']).' of '.money($up['amount']).' now to confirm it.');
    }
}
