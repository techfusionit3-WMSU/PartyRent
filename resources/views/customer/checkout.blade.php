@extends('layouts.market', ['title' => 'Checkout', 'nav' => 'cart'])

{{-- Port of C.checkout (reference js/pages/customer-rent.js). State lives in public/js/checkout.js;
     prices and validation always come from the server (POST checkout.quote → Rentals::prepareOrder). --}}
@section('content')
<div data-leave-warn="always" data-leave-msg="Your rental request has not been sent yet. If you leave now, nothing is requested (your Rental List items stay saved)." hidden></div>
@php($direct = $direct ?? false)
@if(! $orders)
  @include('partials.empty', ['emoji' => '🧾', 'title' => 'Nothing to check out', 'text' => 'Select items in your Rental List first.', 'action' => '<a class="btn btn-primary" href="'.route('cart').'">Go to Rental List</a>'])
@else
@if($direct)
<div class="crumbs"><a href="javascript:history.back()">Back to the item</a> / Checkout</div>
<div class="page-head"><div><h1>Checkout</h1><p>Requesting <b>{{ $orders[0]['lines'][0]['name'] }}</b> from {{ $orders[0]['shop']['name'] }} only. Your Rental List is not changed.</p></div></div>
@else
<div class="crumbs"><a href="{{ route('cart') }}">Rental List</a> / Checkout</div>
<div class="page-head"><div><h1>Checkout</h1><p>{{ collect($orders)->sum(fn ($o) => count($o['lines'])) }} selected item(s) from your Rental List, <b>{{ count($orders) }} shop{{ count($orders) > 1 ? 's' : '' }}</b>. Each shop gets its own rental order, payment plan and payment schedule.</p></div></div>
@endif
{{-- Rental period = the dates of the items being checked out; changing it here moves every item (stock checked first). --}}
@php($pMulti = $period && $period['end'] > $period['start'])
<div class="card date-bar mb-2 co-dates" id="coDates" data-url="{{ route('checkout.dates') }}" data-today="{{ today_iso() }}">
  <span class="db-label">{{ icon('calendar') }}Your rental period</span>
  <div class="db-summary"><span data-plabel>{{ $period ? ($pMulti ? fmt_range($period['start'], $period['end']) : fmt_date($period['start'])) : '—' }}</span><span class="db-sep">|</span><span class="badge b-purple plain" data-pdays>{{ $period ? \App\Support\RentalPeriod::durationLabel(diff_days($period['start'], $period['end']) + 1) : '' }}</span></div>
  <button type="button" class="btn btn-sm" data-pedit>{{ icon('edit') }}Change dates</button>
  @unless($sameDates)<p class="small muted mb-0 full">Your items have different dates. Changing the period puts every item on the new dates.</p>@endunless
  <div class="co-dates-form hidden" data-pform>
    <label class="switch"><input type="checkbox" data-pmulti @checked($pMulti)><span class="sw"></span>Multiple days</label>
    <div class="field mb-0"><label for="co-ps"><span data-plbl-one @if($pMulti) hidden @endif>Date</span><span data-plbl-many @unless($pMulti) hidden @endunless>Start date</span></label><input class="input" type="date" id="co-ps" data-pstart min="{{ today_iso() }}" value="{{ $period['start'] ?? '' }}"></div>
    <div class="field mb-0" data-pendwrap @unless($pMulti) hidden @endunless><label for="co-pe">End date</label><input class="input" type="date" id="co-pe" data-pend min="{{ today_iso() }}" value="{{ $period['end'] ?? '' }}"></div>
    <div class="row" style="gap:6px"><button type="button" class="btn btn-primary btn-sm" data-papply>Apply dates</button><button type="button" class="btn btn-ghost btn-sm" data-pcancel>Cancel</button></div>
    <p class="small muted mb-0 full">Quantities stay the same for each day. We check the stock for the new dates first; if something is not available, nothing changes.</p>
    <div class="alert alert-danger full hidden" data-perr role="alert"></div>
  </div>
</div>
@php($saved = array_intersect_key((array) ($u->address_parts ?? []), array_flip(['regionCode', 'provinceCode', 'cityCode', 'barangay', 'street'])))
@php($state = ['orders' => $orders, 'shared' => ['contactName' => $u->name, 'contactPhone' => $u->phone, 'instructions' => ''], 'saved' => $saved ?: null])
<form id="cf" novalidate class="grid grid-side"
  data-checkout='@json($state)'
  data-quote="{{ route('checkout.quote') }}" data-qty="{{ route('checkout.qty') }}" data-submit="{{ route('checkout.submit') }}" data-today="{{ today_iso() }}">
  <div class="stack">
    {{-- one section per shop (its items, delivery or pickup, payment plan), then the delivery information once --}}
    <div class="stack" id="shops"></div>
    {{-- ONE delivery section for the whole checkout (more locations only when asked): the customer enters the address;
         the server finds each store's delivery zone, fee and branch (shown read-only under the address). --}}
    <div class="card deliv-card" id="delivCard">
      <h3 class="mb-1">{{ icon('map') }} Delivery information</h3>
      <div id="delivWrap">
        <p class="small muted mt-0">Search for your address or move the pin on the map; they follow each other. One address serves every store set to <b>Delivery</b>, and we work out each store's delivery zone, fee and branch from it.</p>
        <div id="deliv"></div>
      </div>
      <div class="form-grid mt-2">
        <div class="field"><label for="ev-cn">Contact person</label><input class="input" id="ev-cn" data-s="contactName" required value="{{ $u->name }}"></div>
        <div class="field"><label for="ev-cp">Contact number</label><input class="input" id="ev-cp" data-s="contactPhone" data-phone required value="{{ $u->phone }}"></div>
        <div class="field full"><label for="ev-in">Special Instructions <span class="muted small">(optional)</span></label><textarea class="input" id="ev-in" data-s="instructions" rows="3" maxlength="1000" placeholder="Provide any additional information or instructions for your delivery (e.g., nearby landmarks, gate details, or directions)."></textarea><div class="hint">Shared with every store in this checkout.</div></div>
      </div>
    </div>
    {{-- damage fees: not part of the price; only if something comes back damaged or missing --}}
    @php($dmgOrders = array_values(array_filter($orders, fn ($o) => ! empty($o['damage']['rows']))))
    @if($dmgOrders)
      <details class="card dmg-card"><summary><span>{{ icon('shield', 'width:16px;vertical-align:-3px') }} <b>If something gets damaged</b></span><span class="small muted">damage fees, not part of the price</span></summary>
        @foreach($dmgOrders as $o)
          <div class="mt-2">@if(count($dmgOrders) > 1)<div class="label mb-1">{{ $o['shop']['name'] }}</div>@endif
            @include('partials.damage-fees', ['rows' => $o['damage']['rows'], 'personal' => $o['damage']['personal']])</div>
        @endforeach
      </details>
    @endif
  </div>
  <aside class="card sticky-side"><h3>Order summary</h3><div id="sum"><div class="page-loading"><div class="spinner"></div></div></div>
    <label class="check mt-2"><input type="checkbox" id="agree"> I agree to each shop's cancellation, return and rental policies{{ collect($orders)->contains(fn ($o) => ! empty($o['damage'])) ? ', and the damage terms' : '' }}.</label>
    <button type="button" class="btn btn-primary btn-lg btn-block mt-1" id="submitBtn">Submit {{ count($orders) > 1 ? count($orders).' rental requests' : 'rental request' }}</button>
    <p class="small muted mt-1 mb-0">Requests stay <b>Pending</b> until each supplier reviews them. Payment deadlines start when a supplier accepts. {{ $direct ? '' : 'Unselected items stay in your Rental List.' }}</p>
  </aside>
</form>
@include('partials.leaflet')
<script src="{{ asset('js/geo.js') }}?v={{ filemtime(public_path('js/geo.js')) }}" defer></script>
<script src="{{ asset('js/checkout.js') }}?v={{ filemtime(public_path('js/checkout.js')) }}" defer></script>
@endif
@endsection
