<?php

namespace App\Http\Controllers\Customer;

use App\Domain\Ai;
use App\Domain\Finance;
use App\Domain\Pricing;
use App\Domain\Rentals;
use App\Http\Controllers\Controller;
use App\Models\CartLine;
use App\Models\Supplier;
use Illuminate\Http\Request;

class CartController extends Controller
{
    public function index(Request $request)
    {
        $u = $request->user();
        $groups = [];
        foreach (Rentals::cartGroups($u) as $sid => $lines) {
            $s = Supplier::find($sid);
            if (! $s) {
                continue;
            }
            $groups[] = [
                'supplier' => $s, 'lines' => $lines,
                'quote' => Pricing::quote($lines, $s, $s->fulfillment === 'pickup' ? 'pickup' : 'delivery', 'full'),
                'check' => inv()->check($lines),
                'compareQuote' => Pricing::quote($lines, $s, $s->fulfillment === 'pickup' ? 'pickup' : 'delivery', in_array('down', $s->arrangements, true) ? 'down' : $s->arrangements[0]),
            ];
        }

        return view('customer.cart', ['groups' => $groups, 'compare' => $request->boolean('compare')]);
    }

    public function add(Request $request)
    {
        $data = $request->validate(['type' => 'required|in:item,package', 'ref_id' => 'required|integer', 'days' => 'required|string|max:5000', 'then' => 'nullable|in:list,checkout']);
        $res = Rentals::addToCart($request->user(), $data['type'], (int) $data['ref_id'], (array) json_decode($data['days'], true));
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
        $lines = Rentals::cartLinesFor($u, $supplier->id);
        if (! $lines) {
            return redirect()->route('cart')->with('warn', 'Your rental list has no items from this supplier.');
        }
        if ($supplier->owner_id === $u->id) {
            return redirect()->route('cart')->with('error', 'You cannot rent from your own shop. Remove these items from your rental list.');
        }
        $dates = collect($lines)->flatMap(fn ($l) => array_keys(Rentals::cleanDays($l['days'])))->unique()->sort()->values()->all();
        if (! $dates) {
            return redirect()->route('cart')->with('warn', 'Set at least one quantity before checking out.');
        }
        $st = [
            'fulfillment' => old('fulfillment', $request->query('fulfillment', $supplier->fulfillment === 'pickup' ? 'pickup' : 'delivery')),
            'arrangement' => old('arrangement', $request->query('arrangement', in_array('down', $supplier->arrangements, true) ? 'down' : $supplier->arrangements[0])),
        ];
        if (! in_array($st['arrangement'], $supplier->arrangements, true)) {
            $st['arrangement'] = $supplier->arrangements[0];
        }
        if (($st['fulfillment'] === 'delivery' && $supplier->fulfillment === 'pickup') || ($st['fulfillment'] === 'pickup' && $supplier->fulfillment === 'delivery')) {
            $st['fulfillment'] = $supplier->fulfillment;
        }
        $clean = array_map(fn ($l) => array_merge($l, ['days' => Rentals::cleanDays($l['days'])]), $lines);
        $quote = Pricing::quote($clean, $supplier, $st['fulfillment'], $st['arrangement']);

        return view('customer.checkout', [
            's' => $supplier, 'u' => $u, 'dates' => $dates, 'st' => $st, 'quote' => $quote,
            'check' => inv()->check($clean),
            'schedule' => Finance::schedule($quote, today_iso(), $dates[0], $supplier->methods[0]),
            'eventTypes' => array_merge(array_keys(Ai::EVENTS), ['Community', 'Other']),
        ]);
    }

    public function submit(Request $request, Supplier $supplier)
    {
        $data = $request->validate([
            'eventName' => 'required|string|min:3|max:120',
            'eventType' => 'required|string|max:40',
            'eventDate' => 'required|date',
            'fulfillment' => 'required|in:delivery,pickup',
            'address' => 'required_if:fulfillment,delivery|nullable|string|max:255',
            'contactName' => 'required|string|max:120',
            'contactPhone' => ['required', 'regex:/^(\+?63|0)9\d{2}[\s-]?\d{3}[\s-]?\d{4}$/'],
            'instructions' => 'nullable|string|max:1000',
            'arrangement' => 'required|in:full,down,installment,reservation',
            'method' => 'required|in:cod,bank,debit',
            'agree' => 'accepted',
        ], ['contactPhone.regex' => 'Enter a valid PH mobile number (e.g. 0917 555 0101).', 'agree.accepted' => 'Please accept the policies.', 'address.required_if' => 'Enter the delivery address.']);
        $dates = collect(Rentals::cartLinesFor($request->user(), $supplier->id))->flatMap(fn ($l) => array_keys(Rentals::cleanDays($l['days'])))->sort()->values();
        if ($dates->isNotEmpty() && ($data['eventDate'] < $dates->first() || $data['eventDate'] > $dates->last())) {
            return back()->withInput()->with('error', 'Event date should fall within your rental dates.');
        }
        $res = Rentals::submitRequest($request->user(), $supplier, $data);
        if (! $res['ok']) {
            return back()->withInput()->with('error', $res['error']);
        }

        return redirect()->route('rentals.confirmation', $res['request']);
    }
}
