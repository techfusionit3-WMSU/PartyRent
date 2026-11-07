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
          @php($ok = $s->fulfillment === 'both' || $s->fulfillment === $fo)
          <a class="chip {{ $st['fulfillment'] === $fo ? 'active' : '' }}" style="{{ $ok ? '' : 'opacity:.45;pointer-events:none' }}" href="{{ route('checkout', ['supplier' => $s->id, 'fulfillment' => $fo, 'arrangement' => $st['arrangement']]) }}">{{ icon($fo === 'delivery' ? 'truck' : 'store', 'width:16px') }}{{ $fo === 'delivery' ? 'Delivery (+'.money($s->delivery_fee).')' : 'Pickup at shop' }}</a>
        @endforeach
        <input type="hidden" name="fulfillment" value="{{ $st['fulfillment'] }}">
      </div>
      @if($st['fulfillment'] === 'delivery')
        <div class="field @error('address') invalid @enderror"><label>Delivery address</label><textarea class="input" name="address" rows="2" required placeholder="House no., street, barangay, city">{{ old('address', $u->address) }}</textarea>@error('address')<div class="error-text">{{ $message }}</div>@enderror</div>
      @else
        <div class="alert alert-info">{{ icon('map') }}<div>Pickup at <b>{{ $s->address }}</b> · {{ $s->hours }}</div></div>
      @endif
      <div class="form-grid">
        <div class="field @error('contactName') invalid @enderror"><label>Contact person</label><input class="input" name="contactName" required value="{{ old('contactName', $u->name) }}"></div>
        <div class="field @error('contactPhone') invalid @enderror"><label>Contact number</label><input class="input" name="contactPhone" required value="{{ old('contactPhone', $u->phone) }}">@error('contactPhone')<div class="error-text">{{ $message }}</div>@enderror</div>
      </div>
      <div class="field"><label>Special instructions (optional)</label><textarea class="input" name="instructions" rows="2" placeholder="Setup location, gate codes, preferred delivery time…">{{ old('instructions') }}</textarea></div>
    </div>

    <div class="card"><h3>3 · Payment arrangement</h3>
      <p class="small muted">{{ $s->name }} accepts the options below. You will pay after the supplier accepts your request.</p>
      <div class="stack-sm">
        @foreach($s->arrangements as $a)
          <a class="card card-flat row" style="padding:12px 14px;cursor:pointer;color:inherit;text-decoration:none;{{ $a === $st['arrangement'] ? 'border-color:var(--brand)' : '' }}" href="{{ route('checkout', ['supplier' => $s->id, 'fulfillment' => $st['fulfillment'], 'arrangement' => $a]) }}">
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
      <div class="field mt-2"><label>Payment method</label><select class="input" name="method">@foreach($s->methods as $m)<option value="{{ $m }}" @selected(old('method') === $m)>{{ \App\Models\Payment::METHOD_LABEL[$m] ?? $m }}</option>@endforeach</select></div>
    </div>

    <div class="card"><h3>4 · Review items & daily quantities</h3>
      @if(! $check['ok'])<div class="alert alert-danger mb-2">{{ icon('alert') }}<div>Stock changed: {{ collect($check['bad'])->map(fn ($b) => inv()->product($b['product_id'])->name.' on '.fmt_date($b['date']).' (only '.$b['available'].' left)')->implode('; ') }}. <a href="{{ route('cart') }}">Edit in Rental List</a></div></div>@endif
      @include('partials.lines-table', ['pricing' => $q])
    </div>
  </div>

  <aside class="card sticky-side"><h3>Order summary</h3>
    <div class="sum-line"><span class="muted">Items & packages</span><span>{{ money($q['itemsSubtotal']) }}</span></div>
    @if($q['deliveryFee'])<div class="sum-line"><span class="muted">Delivery fee</span><span>{{ money($q['deliveryFee']) }}</span></div>@endif
    <div class="sum-line total"><span>Total rental amount</span><span class="price">{{ money($q['total']) }}</span></div>
    @if($q['reservationFee'])<div class="sum-line"><span class="muted">Reservation fee</span><span>{{ money($q['reservationFee']) }}</span></div><div class="small muted mb-1">{!! $q['reservationCredited'] ? 'Credited toward your down payment / total — not charged twice.' : 'Charged in addition to the rental amount. Total payable: <b>'.e(money($q['payable'])).'</b>' !!}</div>@endif
    @if($q['arrangement'] === 'down')
      <div class="sum-line"><span class="muted">Down payment ({{ $q['downPct'] !== null ? $q['downPct'].'%' : 'fixed' }})</span><span>{{ money($q['downRequired']) }}</span></div>
      @if($q['reservationFee'] && $q['reservationCredited'])<div class="sum-line"><span class="muted">Remaining down payment</span><span>{{ money($q['downRequired'] - $q['reservationFee']) }}</span></div>@endif
      <div class="sum-line"><span class="muted">Balance after down payment</span><span>{{ money($q['payable'] - $q['downRequired'] - ($q['reservationCredited'] ? 0 : $q['reservationFee'])) }}</span></div>
    @endif
    <div class="sum-line"><span class="muted">Security deposit <span class="badge b-purple plain">refundable</span></span><span>{{ money($q['deposit']) }}</span></div>
    <div class="small muted">Collected separately from rental charges and returned after inspection.</div>
    <div class="label mt-2 mb-1">Expected schedule (if accepted today)</div>
    @foreach($schedule as $x)<div class="sum-line small"><span>{{ $x['label'] }}<br><span class="muted">by {{ fmt_date($x['due']) }}</span></span><b>{{ money($x['amount']) }}</b></div>@endforeach
    <label class="check mt-2"><input type="checkbox" name="agree" value="1" required> I agree to {{ $s->name }}'s cancellation, return and rental policies.</label>
    @error('agree')<div class="error-text" style="display:block">{{ $message }}</div>@enderror
    <button class="btn btn-primary btn-lg btn-block mt-1" type="submit" data-busy="Submitting…" @disabled(! $check['ok'])>Submit rental request</button>
    <p class="small muted mt-1 mb-0">Your request will be <b>Pending</b> until the supplier reviews it. No payment is collected yet.</p>
  </aside>
</form>
@push('scripts')
<script>
// Keep typed fields when the customer switches delivery/arrangement (those links reload the quote).
(function () {
  var f = document.getElementById('cf'), key = 'pr_checkout_{{ $s->id }}', keep = ['eventName', 'eventType', 'eventDate', 'address', 'contactName', 'contactPhone', 'instructions', 'method'];
  try { var saved = JSON.parse(sessionStorage.getItem(key) || 'null'); if (saved && !{{ $errors->any() ? 'true' : 'false' }}) keep.forEach(function (k) { if (f[k] && saved[k] != null && saved[k] !== '') f[k].value = saved[k]; }); } catch (e) {}
  document.querySelectorAll('#cf a.chip, #cf a.card').forEach(function (a) { a.addEventListener('click', function () { var o = {}; keep.forEach(function (k) { if (f[k]) o[k] = f[k].value; }); try { sessionStorage.setItem(key, JSON.stringify(o)); } catch (e) {} }); });
  f.addEventListener('submit', function () { try { sessionStorage.removeItem(key); } catch (e) {} });
})();
</script>
@endpush
@endsection
