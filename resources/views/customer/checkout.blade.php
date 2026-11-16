@extends('layouts.market', ['title' => 'Checkout', 'nav' => 'cart'])

{{-- Port of C.checkout (reference js/pages/customer-rent.js). State lives in public/js/checkout.js;
     prices and validation always come from the server (POST checkout.quote → Rentals::prepareOrder). --}}
@section('content')
@if(! $orders)
  @include('partials.empty', ['emoji' => '🧾', 'title' => 'Nothing to check out', 'text' => 'Select items in your Rental List first.', 'action' => '<a class="btn btn-primary" href="'.route('cart').'">Go to Rental List</a>'])
@else
<div class="crumbs"><a href="{{ route('cart') }}">Rental List</a> / Checkout</div>
<div class="page-head"><div><h1>Checkout</h1><p>{{ collect($orders)->sum(fn ($o) => count($o['lines'])) }} selected item(s) from <b>{{ count($orders) }} shop{{ count($orders) > 1 ? 's' : '' }}</b>. Each shop gets its own rental order, payment plan and payment schedule.</p></div></div>
@include('partials.period-banner')
<form id="cf" novalidate class="grid grid-side"
  data-checkout='@json(['orders' => $orders, 'shared' => ['eventName' => '', 'eventType' => '', 'contactName' => $u->name, 'contactPhone' => $u->phone, 'instructions' => '']])'
  data-quote="{{ route('checkout.quote') }}" data-submit="{{ route('checkout.submit') }}" data-today="{{ today_iso() }}">
  <div class="stack">
    <div class="card"><h3>Event & contact details</h3><div class="form-grid">
      <div class="field full"><label for="ev-name">Event name</label><input class="input" id="ev-name" data-s="eventName" required minlength="3" placeholder="e.g. Sofia's 7th Birthday"><div class="error-text">Enter the event name (at least 3 characters).</div></div>
      <div class="field"><label for="ev-type">Event type</label><select class="input" id="ev-type" data-s="eventType" required><option value="">Select…</option>@foreach($eventTypes as $e)<option>{{ $e }}</option>@endforeach</select><div class="error-text">Choose the event type.</div></div>
      <div class="field"><label for="ev-cn">Contact person</label><input class="input" id="ev-cn" data-s="contactName" required value="{{ $u->name }}"></div>
      <div class="field"><label for="ev-cp">Contact number</label><input class="input" id="ev-cp" data-s="contactPhone" data-phone required value="{{ $u->phone }}"></div>
      <div class="field full"><label for="ev-in">Special instructions (optional)</label><textarea class="input" id="ev-in" data-s="instructions" rows="2" placeholder="Setup location, gate codes…"></textarea></div>
    </div></div>
    <div class="stack" id="shops"></div>
  </div>
  <aside class="card sticky-side"><h3>Order summary</h3><div id="sum"><div class="page-loading"><div class="spinner"></div></div></div>
    <label class="check mt-2"><input type="checkbox" id="agree"> I agree to each shop's cancellation, return and rental policies.</label>
    <button type="button" class="btn btn-primary btn-lg btn-block mt-1" id="submitBtn">Submit {{ count($orders) > 1 ? count($orders).' rental requests' : 'rental request' }}</button>
    <p class="small muted mt-1 mb-0">Requests stay <b>Pending</b> until each supplier reviews them. Payment deadlines start when a supplier accepts. Unselected items stay in your Rental List.</p>
  </aside>
</form>
<script src="{{ asset('js/checkout.js') }}?v={{ filemtime(public_path('js/checkout.js')) }}" defer></script>
@endif
@endsection
