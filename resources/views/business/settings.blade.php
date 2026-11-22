@extends('layouts.dash', ['title' => 'Bookings & Payment Settings', 'nav' => 'settings'])

@section('content')
@php($v = fn ($k) => old($k, $set[$k]))
<div class="page-head"><div><h1>Bookings & Payment Settings</h1><p>How customers can book {{ $s->name }}: where you work, how much notice you need, and how they pay.</p></div></div>
<form class="card" method="POST" action="{{ route('business.settings.update') }}" style="max-width:860px">
  @csrf @method('PUT')
  <h3>Where you work</h3>
  <p class="small muted mt-0">You set up on-site at the customer's venue. There is no delivery: travel is part of your quotation.</p>
  <div class="field @error('serviceAreas') invalid @enderror"><label>Cities and areas you serve</label>@include('partials.tag-input', ['name' => 'serviceAreas', 'values' => old('serviceAreas', $set['serviceAreas']), 'suggest' => array_values(array_filter([$s->city])), 'placeholder' => 'Type a city, e.g. Zamboanga City'])@error('serviceAreas')<div class="error-text">{{ $message }}</div>@enderror</div>
  <div class="field"><label>Travel note <span class="muted small">(optional)</span></label><input class="input" name="travelNote" maxlength="300" value="{{ $v('travelNote') }}" placeholder="e.g. Free within the city; ₱2,000 travel fee for nearby towns"></div>
  <div class="form-grid">
    <div class="field"><label>Setup time (hours before the event)</label><input class="input" type="number" min="0" max="48" step="0.5" name="setupHours" value="{{ $v('setupHours') }}"></div>
    <div class="field"><label>Teardown time (hours after)</label><input class="input" type="number" min="0" max="48" step="0.5" name="teardownHours" value="{{ $v('teardownHours') }}"></div>
    <div class="field"><label>Events you can handle in one day</label><input class="input" type="number" min="1" max="50" name="maxEventsPerDay" value="{{ $v('maxEventsPerDay') }}">@if(\App\Domain\EventTeams::uses($s))<div class="alert alert-info mt-1 mb-1 small">{{ icon('users') }}<div>You use <a href="{{ route('business.teams') }}">Teams</a>, so this number is not used: how many events you take at the same time comes from your active teams.</div></div>@endif<div class="hint">A date is closed to new bookings once this many are booked.</div></div>
    <div class="field"><label>Minimum notice (days)</label><input class="input" type="number" min="0" max="365" name="minNoticeDays" value="{{ $v('minNoticeDays') }}"><div class="hint">Customers can't ask for dates sooner than this.</div></div>
  </div>

  <h3 class="mt-2">Payment schedule</h3>
  <p class="small muted mt-0">Used for every quotation (you can change the percentages per quotation). Customers pay to your payout account and send proof; you confirm each payment.</p>
  <div class="form-grid">
    <div class="field"><label>Reservation fee (% of total)</label><input class="input" type="number" min="0" max="100" step="0.5" name="reservePct" value="{{ $v('reservePct') }}" data-sch><div class="hint">Paid to accept the quotation. The date is yours once it's confirmed.</div></div>
    <div class="field"><label>Pay the reservation fee within (hours)</label><input class="input" type="number" min="12" max="168" name="reserveHours" value="{{ $v('reserveHours') }}"><div class="hint">Unpaid by then, the date is released.</div></div>
    <div class="field @error('depositPct') invalid @enderror"><label>Deposit (% of total)</label><input class="input" type="number" min="0" max="100" step="0.5" name="depositPct" value="{{ $v('depositPct') }}" data-sch>@error('depositPct')<div class="error-text">{{ $message }}</div>@enderror</div>
    <div class="field"><label>Deposit due (days before the event)</label><input class="input" type="number" min="0" max="365" name="depositDays" value="{{ $v('depositDays') }}"></div>
    <div class="field @error('balanceDays') invalid @enderror"><label>Balance due (days before the event)</label><input class="input" type="number" min="0" max="365" name="balanceDays" value="{{ $v('balanceDays') }}">@error('balanceDays')<div class="error-text">{{ $message }}</div>@enderror</div>
    <div class="field"><label>Quotations are valid for (days)</label><input class="input" type="number" min="1" max="30" name="quoteDays" value="{{ $v('quoteDays') }}"></div>
  </div>
  <p class="small" id="schPrev"></p>

  <h3 class="mt-2">Policies</h3>
  <div class="field @error('cancellation') invalid @enderror"><label>Cancellation policy</label><textarea class="input" name="cancellation" required minlength="10">{{ old('cancellation', $pol['cancellation'] ?? 'The reservation fee is non-refundable. Cancel at least 30 days before the event for a refund of the deposit.') }}</textarea>@error('cancellation')<div class="error-text">{{ $message }}</div>@enderror</div>
  <div class="field @error('rescheduling') invalid @enderror"><label>Rescheduling policy</label><textarea class="input" name="rescheduling" required minlength="10">{{ old('rescheduling', $pol['rescheduling'] ?? 'One free date change if asked at least 14 days before the event, subject to availability.') }}</textarea>@error('rescheduling')<div class="error-text">{{ $message }}</div>@enderror</div>
  <button class="btn btn-primary">{{ icon('check') }}Save settings</button>
</form>
@push('scripts')
<script>
(function () {
  var f = document.querySelector('form.card'), out = document.getElementById('schPrev');
  function show() { var r = +f.reservePct.value || 0, d = +f.depositPct.value || 0; out.style.color = r + d > 100 ? 'var(--red)' : ''; out.textContent = r + d > 100 ? 'Reservation fee + deposit cannot be more than 100%.' : 'Example on ₱100,000: ₱' + (r * 1000).toLocaleString() + ' to reserve, ₱' + (d * 1000).toLocaleString() + ' deposit, ₱' + ((100 - r - d) * 1000).toLocaleString() + ' balance.'; }
  f.addEventListener('input', show); show();
})();
</script>
@endpush
@endsection
