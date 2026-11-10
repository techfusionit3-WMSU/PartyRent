@extends('layouts.market', ['title' => 'Rental request', 'nav' => 'rentals'])

@php($q = $quote)
@section('content')
<div class="crumbs"><a href="{{ route('cart') }}">Rental List</a> / Checkout</div>
<div class="page-head"><div><h1>Rental request</h1><p>Request to <b>{{ $s->name }}</b> · {{ fmt_range($dates[0], end($dates)) }}</p></div></div>

{{-- Changing delivery/arrangement reloads the quote from the server (GET), keeping what was typed via old() on submit errors. --}}
<form method="POST" action="{{ route('checkout.submit', $s) }}" class="grid grid-side" id="cf">
  @csrf
  <div class="stack">
    <div class="card"><h3>1 · Event details</h3><div class="form-grid">
      <div class="field full @error('eventName') invalid @enderror"><label>Event name</label><input class="input" name="eventName" required minlength="3" placeholder="e.g. Sofia's 7th Birthday" value="{{ old('eventName') }}">@error('eventName')<div class="error-text">{{ $message }}</div>@enderror</div>
      <div class="field @error('eventType') invalid @enderror"><label>Event type</label><select class="input" name="eventType" required><option value="">Select…</option>@foreach($eventTypes as $e)<option @selected(old('eventType') === $e)>{{ $e }}</option>@endforeach</select>@error('eventType')<div class="error-text">{{ $message }}</div>@enderror</div>
      <div class="field"><label>Event date</label><input class="input" type="date" name="eventDate" required min="{{ $dates[0] }}" max="{{ end($dates) }}" value="{{ old('eventDate', $dates[0]) }}"><div class="hint">Within your rental dates ({{ fmt_range($dates[0], end($dates)) }})</div></div>
    </div></div>

    <div class="card"><h3>2 · Delivery or pickup</h3>
      <div class="row wrap gap-sm mb-2">
        @foreach(['delivery', 'pickup'] as $fo)
          @php($ok = $fo === 'delivery' ? $s->offersDelivery() : $s->offersPickup())
          <a class="chip {{ $st['fulfillment'] === $fo ? 'active' : '' }}" style="{{ $ok ? '' : 'opacity:.45;pointer-events:none' }}" href="{{ route('checkout', array_filter(['supplier' => $s->id, 'fulfillment' => $fo, 'arrangement' => $st['arrangement'], 'city' => $city])) }}">{{ icon($fo === 'delivery' ? 'truck' : 'store', 'width:16px') }}{{ $fo === 'delivery' ? 'Delivery' : ($s->location_type === 'home' ? 'Pickup (home-based seller)' : 'Pickup at shop') }}</a>
        @endforeach
        <input type="hidden" name="fulfillment" value="{{ $st['fulfillment'] }}">
      </div>
      @if($st['fulfillment'] === 'delivery')
        <div class="label mb-1">Delivery address</div>
        @include('partials.address-fields', ['key' => 'addr', 'value' => $addr, 'full' => true, 'required' => true])
        <div id="dq" class="mb-2">@include('customer._delivery-quote', ['d' => $q['delivery'], 's' => $s])</div>
      @else
        <div class="alert alert-info">{{ icon('map') }}<div>Pickup at <b>{{ $s->publicAddress() }}</b> · {{ $s->hours }}@if(! $s->isPublicLocation())<div class="small">The exact address and map pin are shared once {{ $s->name }} accepts your request.</div>@elseif($s->hasPin()) · <a href="{{ $s->directionsUrl() }}" target="_blank" rel="noopener">Directions</a>@endif</div></div>
      @endif
      <div class="form-grid">
        <div class="field @error('contactName') invalid @enderror"><label>Contact person</label><input class="input" name="contactName" required value="{{ old('contactName', $u->name) }}"></div>
        <div class="field @error('contactPhone') invalid @enderror"><label>Contact number</label><input class="input" name="contactPhone" required value="{{ old('contactPhone', $u->phone) }}">@error('contactPhone')<div class="error-text">{{ $message }}</div>@enderror</div>
      </div>
      <div class="field"><label>Special instructions (optional)</label><textarea class="input" name="instructions" rows="2" placeholder="Setup location, gate codes, preferred delivery time…">{{ old('instructions') }}</textarea></div>
    </div>

    <div class="card"><h3>3 · Payment arrangement</h3>
      <p class="small muted">{{ $s->name }} accepts the options below. You pay the first payment now; {{ $s->name }} accepts your request once PartyRent has verified it.</p>
      <div class="stack-sm">
        @foreach($s->arrangements as $a)
          <a class="card card-flat row" style="padding:12px 14px;cursor:pointer;color:inherit;text-decoration:none;{{ $a === $st['arrangement'] ? 'border-color:var(--brand)' : '' }}" href="{{ route('checkout', array_filter(['supplier' => $s->id, 'fulfillment' => $st['fulfillment'], 'arrangement' => $a, 'city' => $city])) }}">
            <input type="radio" @checked($a === $st['arrangement']) style="accent-color:var(--brand);width:18px;height:18px" tabindex="-1" aria-hidden="true">
            <div class="grow"><b>{{ \App\Models\Payment::ARR_LABEL[$a] }}</b><div class="small muted">{{ [
              'full' => 'Pay the whole amount before the rental.',
              'down' => 'Pay '.$s->downLabel().' first, balance on the rental date.'.($s->pol('reservationFee') ? ' Reservation fee '.($s->pol('reservationCredited') ? 'is credited to the down payment.' : 'is charged on top.') : ''),
              'installment' => ($s->pol('reservationFee') ? 'Reservation fee, then ' : '').$s->pol('installments').' installments before the rental date.',
              'reservation' => 'Pay a '.money($s->pol('reservationFee', 0)).' reservation fee to confirm, balance on the rental date.',
            ][$a] }}</div></div>
          </a>
        @endforeach
        <input type="hidden" name="arrangement" value="{{ $st['arrangement'] }}">
      </div>
      <div class="field mt-2"><label>Payment method for the rest</label><select class="input" name="method" id="ckMethod">@foreach($s->methods as $m)<option value="{{ $m }}" @selected(old('method') === $m)>{{ \App\Models\Payment::METHOD_LABEL[$m] ?? $m }}</option>@endforeach</select></div>
    </div>

    <div class="card"><h3>4 · Review items & daily quantities</h3>
      @if(! $check['ok'])<div class="alert alert-danger mb-2">{{ icon('alert') }}<div>Stock changed: {{ collect($check['bad'])->map(fn ($b) => inv()->product($b['product_id'])->name.' on '.fmt_date($b['date']).' (only '.$b['available'].' left)')->implode('; ') }}. <a href="{{ route('cart') }}">Edit in Rental List</a></div></div>@endif
      @include('partials.lines-table', ['pricing' => $q])
    </div>
  </div>

  @php($deliveryBlocked = $st['fulfillment'] === 'delivery' && ! ($q['delivery']['ok'] ?? false))
  <aside class="card sticky-side"><h3>Order summary</h3>
    <div id="sumBox">@include('customer._checkout-summary', ['q' => $q, 'schedule' => $schedule, 'due' => $due])</div>
    <div class="field mt-2"><label>Pay now with</label>
      <div class="stack-sm">
        @if($online)<label class="card card-flat row" style="padding:10px 12px;gap:8px"><input type="radio" name="payWith" value="online" @checked(old('payWith', 'online') === 'online')><div><b>Pay online</b><div class="small muted">{{ \App\Domain\PayMongo::practice() ? 'Practice checkout (no real money)' : 'GCash, Maya, card or QR Ph. Verified instantly.' }}</div></div></label>@endif
        <label class="card card-flat row" style="padding:10px 12px;gap:8px"><input type="radio" name="payWith" value="transfer" @checked(! $online || old('payWith') === 'transfer')><div><b>Bank / GCash transfer</b><div class="small muted">Send to a PartyRent account and upload the receipt. Verified by our cashier.</div></div></label>
      </div>
    </div>
    <label class="check mt-2"><input type="checkbox" name="agree" value="1" required> I agree to {{ $s->name }}'s cancellation, return and rental policies.</label>
    @error('agree')<div class="error-text" style="display:block">{{ $message }}</div>@enderror
    <button class="btn btn-primary btn-lg btn-block mt-1" type="submit" id="submitReq" data-busy="Placing request…" data-stock-ok="{{ $check['ok'] ? 1 : 0 }}" @disabled(! $check['ok'] || $deliveryBlocked)>Place request &amp; pay <span data-due-amount-btn>{{ money(reset($due)['amount']) }}</span></button>
    <p class="small mt-1 mb-0 {{ $deliveryBlocked ? '' : 'hidden' }}" id="blockedNote" style="color:var(--red)">Choose an address this supplier delivers to, or switch to pickup.</p>
    <p class="small muted mt-1 mb-0">Your request stays <b>Pending</b> until you pay and the supplier accepts it. Items are reserved once it is accepted.</p>
  </aside>
</form>
@push('scripts')
<script>
// Keep typed fields when the customer switches delivery/arrangement (those links reload the quote).
(function () {
  var f = document.getElementById('cf'), key = 'pr_checkout_{{ $s->id }}', keep = ['eventName', 'eventType', 'eventDate', 'addr[barangay]', 'addr[street]', 'addr[landmark]', 'contactName', 'contactPhone', 'instructions', 'method'];
  var el = function (k) { return f.elements[k]; };
  try { var saved = JSON.parse(sessionStorage.getItem(key) || 'null'); if (saved && !{{ $errors->any() ? 'true' : 'false' }}) keep.forEach(function (k) { if (el(k) && saved[k] != null && saved[k] !== '') el(k).value = saved[k]; }); } catch (e) {}
  document.querySelectorAll('#cf a.chip, #cf a.card').forEach(function (a) { a.addEventListener('click', function () {
    var o = {}; keep.forEach(function (k) { if (el(k)) o[k] = el(k).value; }); try { sessionStorage.setItem(key, JSON.stringify(o)); } catch (e) {}
    // carry the currently chosen city into the reloaded page
    var c = el('addr[cityCode]'); if (c && c.value) { var u = new URL(a.href); u.searchParams.set('city', c.value); a.href = u.toString(); }
  }); });
  f.addEventListener('submit', function () { try { sessionStorage.removeItem(key); } catch (e) {} });

  // "Pay now" amount follows the payment method (Cash on Delivery + full payment pays only a down payment now).
  var syncDue = function () {
    var box = document.querySelector('[data-due-box]'), m = document.getElementById('ckMethod');
    if (!box || !m) return;
    var v = (box.getAttribute('data-due-' + m.value) || '').split('|');
    if (v.length < 2) return;
    box.querySelector('[data-due-label]').textContent = v[0];
    box.querySelector('[data-due-amount]').textContent = v[1];
    var b = document.querySelector('[data-due-amount-btn]'); if (b) b.textContent = v[1];
  };
  document.getElementById('ckMethod').addEventListener('change', syncDue); syncDue();
  f.addEventListener('pr:quoted', syncDue);

  // Live delivery re-quote when the city changes.
  var seq = 0, btn = document.getElementById('submitReq'), note = document.getElementById('blockedNote');
  f.addEventListener('pr:city', function (e) {
    var mine = ++seq, url = new URL(@json(route('checkout.quote', $s)));
    url.searchParams.set('fulfillment', @json($st['fulfillment'])); url.searchParams.set('arrangement', @json($st['arrangement']));
    url.searchParams.set('city', e.detail.cityCode || '');
    window.PRUI.api(url.toString()).then(function (r) {
      if (mine !== seq) return;
      var dq = document.getElementById('dq'); if (dq) dq.innerHTML = r.deliveryHtml;
      document.getElementById('sumBox').innerHTML = r.summaryHtml;
      f.dispatchEvent(new Event('pr:quoted'));
      btn.disabled = !r.ok || btn.dataset.stockOk !== '1';
      note.classList.toggle('hidden', r.ok);
    }).catch(function () { window.PRUI.toast('Could not update the delivery fee. Please reload the page.', 'error'); });
  });
})();
</script>
@endpush
@endsection
