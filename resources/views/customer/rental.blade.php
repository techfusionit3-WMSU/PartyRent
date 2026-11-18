@extends('layouts.market', ['title' => $r->event_name, 'nav' => 'rentals'])

@section('content')
<div data-leave-warn="dirty" data-leave-msg="You started filling in a form on this rental (for example a payment) that was not sent. Leave anyway?" hidden></div>
<div class="crumbs"><a href="{{ route('rentals.index') }}">My Rentals</a> / {{ $r->code }}</div>
<div class="page-head"><div><h1>{{ $r->event_name }}</h1><p>{{ $r->code }}@if($r->event_type && $r->event_type !== 'Other') · {{ $r->event_type }}@endif · submitted {{ fmt_datetime($r->created_at) }}</p></div><div class="row wrap">@include('partials.badges', ['status' => $r->status])@include('partials.badges', ['pay' => $f['status']])</div></div>
@include('partials.case-alert', ['r' => $r])
<div class="card mb-2">@include('partials.progress')</div>
@if($f['isOverdue'])<div class="alert alert-danger mb-2">{{ icon('alert') }}<div><b>Payment overdue.</b> Outstanding: {{ money($f['overdueAmount']) }}. Please settle as soon as possible to keep your reservation. The supplier will review overdue accounts according to their policy.</div></div>@endif
@if($r->status === 'pending')<div class="alert alert-info mb-2">{{ icon('clock') }}<div>Waiting for <b>{{ $sup->name }}</b> to review your request. Items are not reserved until it is accepted.</div></div>@endif

<div class="grid grid-side">
  <div class="stack">
    <div class="card"><div class="card-head"><h3>Items & daily quantities</h3><span class="small muted">{{ fmt_range($r->start_date, $r->end_date) }}</span></div>@include('partials.lines-table', ['pricing' => $r->pricing])</div>
    @if($r->fulfillment === 'delivery' && $r->destinations)<div class="card"><h3>{{ count($r->destinations) > 1 ? 'Delivery & event locations' : 'Delivery location' }}</h3>@include('partials.destinations')</div>@endif
    @if($r->plan === 'installment')@include('partials.installment-card')@endif
    <div class="card"><div class="card-head"><h3>Payment history</h3>@if($payTypes)<button class="btn btn-primary btn-sm" data-modal="payModal">{{ icon('card') }}Make a payment</button>@endif</div>@include('partials.payment-history')</div>
    @include('partials.inspection-report')
    <div class="card"><h3>Activity</h3>@include('partials.timeline')</div>
  </div>
  <aside class="stack">
    <div class="card">@include('partials.payment-summary')</div>
    @if($r->fulfillment === 'pickup')<div class="card"><h3>{{ icon('map', 'width:18px;vertical-align:-3px') }} Pickup location</h3>@include('partials.supplier-location', ['s' => $sup, 'reveal' => $r->revealsPickupLocation()])</div>@endif
    <div class="card"><h3>{{ $r->fulfillment === 'delivery' ? 'Delivery' : 'Pickup' }} details</h3>
      <dl class="kv">
        <dt>Supplier</dt><dd><a href="{{ route('suppliers.show', $sup) }}">{{ $sup->name }}</a><div class="small muted">{{ $sup->phone }}</div></dd>
        @if($r->fulfillment === 'delivery')<dt>Deliver to</dt><dd>{{ count($r->destinations ?? []) > 1 ? count($r->destinations).' locations (see left)' : $r->address }}</dd>@else<dt>Pickup at</dt><dd>{{ $r->revealsPickupLocation() ? $sup->address : $sup->publicAddress() }}<div class="small muted">{{ $sup->hours }}</div></dd>@endif
        <dt>Contact</dt><dd>{{ $r->contact_name }} · {{ $r->contact_phone }}</dd>
        <dt>Payment</dt><dd>{{ \App\Domain\Finance::PLAN_LABEL[$r->plan] ?? '' }} · {{ \App\Domain\Finance::METHOD_LABEL[$r->method] ?? $r->method }}</dd>
        @if(! empty($r->fulfillment_log['releaseDate']))<dt>Released</dt><dd>{{ fmt_date($r->fulfillment_log['releaseDate']) }} to {{ $r->fulfillment_log['receivedBy'] ?? '' }}</dd>@endif
        @if(! empty($r->fulfillment_log['returnDate']))<dt>Returned</dt><dd>{{ fmt_date($r->fulfillment_log['returnDate']) }}</dd>@endif
        <dt>Return status</dt><dd>{{ $r->status === 'completed' ? 'Returned & closed' : ($r->inspection ? 'Inspected' : ($r->status === 'return_pending' ? 'Awaiting return' : ($r->status === 'ongoing' ? 'Due '.fmt_date(add_days($r->end_date, 1)) : '—'))) }}</dd>
      </dl>
      @if($r->instructions)<p class="small mt-1 mb-0"><b>Your instructions:</b> {{ $r->instructions }}</p>@endif
      @if($r->supplier_notes)<div class="alert alert-info mt-1">{{ icon('message') }}<div class="small"><b>Supplier notes:</b> {{ $r->supplier_notes }}</div></div>@endif
    </div>
    @if($policy['allowed'] && ! in_array($r->status, ['declined', 'cancelled', 'completed'], true))
      <form class="card" method="POST" action="{{ route('rentals.cancel', $r) }}" data-confirm="Cancel {{ $r->code }}? {{ $policy['note'] }}" data-confirm-title="Cancel request?" data-confirm-ok="Cancel request" data-danger>
        @csrf
        <h3>Need to cancel?</h3><p class="small muted">{{ $policy['note'] }}</p>
        <div class="field"><label for="reason">Reason for cancelling</label><textarea class="input" id="reason" name="reason" required maxlength="500"></textarea></div>
        <button class="btn btn-danger btn-sm">Cancel request</button>
      </form>
    @endif
  </aside>
</div>

@if($reviewables)
  @php
      $done = array_filter($reviewables, fn ($x) => $x['review']);
      $todo = array_filter($reviewables, fn ($x) => ! $x['review']);
      $RV = \App\Domain\Reviews::class;
  @endphp
  <section class="section" id="rate">
    @if($todo)
      <form class="card" method="POST" action="{{ route('rentals.review', $r) }}" enctype="multipart/form-data" data-review-form>@csrf
        <h2 class="mb-0">Rate your rental</h2>
        <p class="small muted">Rate each item you rented. Your stars and comments are shown on {{ $sup->name }}'s shop and item pages with a <b>Verified rental</b> badge. You can add up to {{ $RV::MAX_PHOTOS }} photos and 1 short video per item. Skip any item you don't want to rate.</p>
        @error('reviews')<div class="alert alert-danger mb-2">{{ icon('alert') }}<div>{{ $message }}</div></div>@enderror
        <div class="stack">
        @foreach($todo as $key => $it)
          @php($old = old("reviews.$key", []))
          <fieldset class="review-item card card-flat">
            <legend class="strong">{{ $it['type'] === 'package' ? '📦 ' : '' }}{{ $it['name'] }}</legend>
            <div class="field {{ $errors->has("reviews.$key.rating") ? 'invalid' : '' }}">
              <span class="label" id="lbl-{{ $key }}">Your rating</span>
              <div class="star-input" role="radiogroup" aria-labelledby="lbl-{{ $key }}">
                @for($i = 5; $i >= 1; $i--)
                  <input type="radio" id="st-{{ $key }}-{{ $i }}" name="reviews[{{ $key }}][rating]" value="{{ $i }}" @checked((int) ($old['rating'] ?? 0) === $i)>
                  <label for="st-{{ $key }}-{{ $i }}" title="{{ ['', 'Poor', 'Fair', 'Good', 'Very good', 'Excellent'][$i] }}">★<span class="sr-only">{{ $i }} star{{ $i > 1 ? 's' : '' }}</span></label>
                @endfor
              </div>
              @error("reviews.$key.rating")<div class="error-text" style="display:block">{{ $message }}</div>@enderror
            </div>
            <div class="field {{ $errors->has("reviews.$key.comment") ? 'invalid' : '' }}">
              <label for="cm-{{ $key }}">What did you think?</label>
              <textarea class="input" id="cm-{{ $key }}" name="reviews[{{ $key }}][comment]" rows="3" maxlength="1000" placeholder="Condition, cleanliness, did it look like the photos, delivery/setup…">{{ $old['comment'] ?? '' }}</textarea>
              @error("reviews.$key.comment")<div class="error-text" style="display:block">{{ $message }}</div>@enderror
            </div>
            <div class="form-grid">
              <div class="field {{ $errors->has("reviews.$key.photos") || $errors->has("reviews.$key.photos.*") ? 'invalid' : '' }}">
                <label for="ph-{{ $key }}">{{ icon('image', 'width:15px;height:15px') }} Photos <span class="muted">(up to {{ $RV::MAX_PHOTOS }}, 5 MB each)</span></label>
                <input class="input" type="file" id="ph-{{ $key }}" name="reviews[{{ $key }}][photos][]" accept="image/jpeg,image/png,image/webp" multiple data-media-input="image" data-max="{{ $RV::MAX_PHOTOS }}" data-max-mb="5">
                @error("reviews.$key.photos")<div class="error-text" style="display:block">{{ $message }}</div>@enderror
                @error("reviews.$key.photos.*")<div class="error-text" style="display:block">{{ $message }}</div>@enderror
              </div>
              <div class="field {{ $errors->has("reviews.$key.video") ? 'invalid' : '' }}">
                <label for="vd-{{ $key }}">{{ icon('video', 'width:15px;height:15px') }} Video <span class="muted">(1 clip, up to 20 MB)</span></label>
                <input class="input" type="file" id="vd-{{ $key }}" name="reviews[{{ $key }}][video]" accept="video/mp4,video/webm,video/quicktime" data-media-input="video" data-max="1" data-max-mb="20">
                @error("reviews.$key.video")<div class="error-text" style="display:block">{{ $message }}</div>@enderror
              </div>
            </div>
            <div class="media-preview" data-media-preview></div>
          </fieldset>
        @endforeach
        </div>
        <div class="row between wrap mt-2"><span class="small muted">If the form shows an error, please re-attach your photos/videos — browsers can't keep files after a page reload.</span><button class="btn btn-primary">{{ icon('star') }}Submit review{{ count($todo) > 1 ? 's' : '' }}</button></div>
      </form>
    @endif
    @if($done)
      <h2 class="{{ $todo ? 'mt-3' : '' }}">Your reviews</h2>
      <div class="stack">@foreach($done as $it)@include('partials.review', ['rv' => $it['review']->load(['customer', 'product', 'package', 'supplier'])])@endforeach</div>
      @include('partials.media-lightbox')
    @endif
  </section>
@endif

@if($payTypes)
<div class="modal-backdrop hidden" id="payModal"><div class="modal" role="dialog" aria-modal="true" aria-label="Make a payment">
  <div class="modal-head"><h3>Make a payment · {{ $r->code }}</h3><button class="icon-btn" data-close aria-label="Close">{{ icon('x') }}</button></div>
  <form method="POST" action="{{ route('rentals.pay', $r) }}" enctype="multipart/form-data" id="pmf">
    @csrf
    <div class="modal-body">
      <div class="field"><label>What are you paying for?</label><select class="input" name="type" id="payKind">@foreach($payTypes as $t)<option value="{{ $t[0] }}" data-amount="{{ $t[2] }}">{{ $t[1] }} — {{ money($t[2]) }}</option>@endforeach</select></div>
      <div class="form-grid"><div class="field"><label>Amount (₱)</label><input class="input" type="number" step="0.01" min="1" name="amount" id="payAmount" required value="{{ old('amount', $payTypes[0][2]) }}"></div>
        <div class="field"><label>Payment method</label><select class="input" name="method" id="payMethod">@if($online)<option value="online">Pay online: GCash, Maya, card (instant)</option>@endif @foreach($sup->methods as $m)<option value="{{ $m }}" @selected(old('method') === $m)>{{ \App\Models\Payment::METHOD_LABEL[$m] ?? $m }}</option>@endforeach</select></div></div>
      @if($online)
      <div id="onlineBox" class="alert alert-info mb-2">{{ icon('card') }}<div class="small">@if(\App\Domain\OnlinePay::practice())<b>Practice mode:</b> you'll go to a practice checkout page that stands in for the real one. No real money is charged.@else You'll go to <b>{{ \App\Domain\OnlinePay::name() }}'s secure checkout</b> to pay with GCash, Maya, card and more.@endif Your payment is <b>verified automatically</b>, so there's no need to upload a receipt.@unless(\App\Domain\OnlinePay::practice()) {{ \App\Domain\OnlinePay::name() }} emails you a receipt too.@endunless</div></div>
      @endif
      <div id="bankBox">
        <div class="pay-accounts mb-2">
          <div class="small mb-1"><b>Send your payment to PartyRent</b> (not to {{ $sup->name }}), then pick the account you used, enter the reference number and upload your receipt.</div>
          @forelse($accounts as $i => $a)
            <label class="pay-account" data-room="{{ $a->room() ?? '' }}">
              <input type="radio" name="pay_account" value="{{ $a->id }}" @checked((string) old('pay_account') === (string) $a->id || (! old('pay_account') && $i === 0))>
              <span class="badge b-sky plain">{{ $a->label }}</span>
              <div>@if($a->account_no)<b>{{ $a->account_no }}</b>@endif @if($a->account_name)<div class="small muted">{{ $a->account_name }}</div>@endif
                <div class="small muted pay-full hidden">Can't take this amount this month. Use another account.</div>
                @if($a->qr_path)<div class="pay-qr"><img src="{{ route('payments.qr', $a) }}" alt="{{ $a->label }} QR code" loading="lazy"><div class="small muted">Scan with GCash, Maya or your banking app.</div></div>@endif</div>
              @if($a->account_no)<button type="button" class="btn btn-xs btn-ghost" data-copy="{{ $a->account_no }}">Copy</button>@endif
            </label>
          @empty
            <div class="small muted">PartyRent's accounts aren't available right now. Contact {{ setting('supportPhone') }} or {{ setting('supportEmail') }} before paying.</div>
          @endforelse
        </div>
        <div class="form-grid"><div class="field"><label>Reference number</label><input class="input" name="reference" value="{{ old('reference') }}" placeholder="e.g. 1012 345 678901" maxlength="80"><div class="hint">Each reference number can be used only once.</div></div><div class="field"><label>Payment date</label><input class="input" type="date" name="date" value="{{ today_iso() }}" max="{{ today_iso() }}"></div></div>
        <div class="field"><label>Payment proof</label><label class="upload-box" for="proof">{{ icon('upload') }}<div class="small">Upload a screenshot or photo of the receipt (JPG/PNG, up to 8 MB)</div></label><input type="file" id="proof" name="proof" accept="image/*" hidden><div class="small muted" id="proofName"></div></div>
      </div>
      <div id="codBox" class="alert alert-warn hidden">{{ icon('cash') }}<div class="small"><b>Cash on Delivery:</b> hand the cash to the supplier's crew when the items arrive. They collect it for PartyRent and our cashier verifies it. You can also log it here with the amount you handed over.</div></div>
      <div class="field"><label>Notes (optional)</label><input class="input" name="notes" maxlength="255"></div>
      <p class="small muted mb-0">You pay PartyRent, not the supplier directly. We hold your payment and only release it to the supplier after your rental is completed. Transfers and cash show as <b>Pending Verification</b> until our cashier confirms them, and don't reduce your balance until then.</p>
    </div>
    <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary" type="submit" id="paySubmit" data-busy="Submitting…">Submit payment</button></div>
  </form>
</div></div>
@push('scripts')
<script>
(function () {
  var k = document.getElementById('payKind'), a = document.getElementById('payAmount'), m = document.getElementById('payMethod');
  var ob = document.getElementById('onlineBox'), btn = document.getElementById('paySubmit');
  var sync = function () {
    var bank = m.value === 'bank' || m.value === 'debit', online = m.value === 'online';
    document.getElementById('bankBox').classList.toggle('hidden', !bank);
    document.getElementById('codBox').classList.toggle('hidden', bank || online);
    if (ob) ob.classList.toggle('hidden', !online);
    btn.textContent = online ? 'Continue to payment' : 'Submit payment';
    btn.dataset.busy = online ? 'Opening checkout…' : 'Submitting…';
  };
  // hide accounts that can't take the amount this month (monthly limit)
  var rooms = function () {
    var amt = parseFloat(a.value) || 0, firstOk = null, chosenOk = false;
    document.querySelectorAll('.pay-account[data-room]').forEach(function (el) {
      var room = el.dataset.room === '' ? Infinity : parseFloat(el.dataset.room), ok = amt <= room + 0.001, r = el.querySelector('input');
      el.classList.toggle('is-full', !ok); r.disabled = !ok; el.querySelector('.pay-full').classList.toggle('hidden', ok);
      if (ok && !firstOk) firstOk = r; if (ok && r.checked) chosenOk = true;
    });
    if (!chosenOk && firstOk) firstOk.checked = true;
  };
  a.addEventListener('input', rooms); k.addEventListener('change', rooms); rooms();
  document.querySelectorAll('[data-copy]').forEach(function (b) { b.addEventListener('click', function () { try { navigator.clipboard.writeText(b.dataset.copy); b.textContent = 'Copied'; } catch (e) {} }); });
  k.addEventListener('change', function () { a.value = k.selectedOptions[0].dataset.amount; });
  m.addEventListener('change', sync); sync();
  // arriving from checkout (?pay=1): open the payment form right away
  if (new URLSearchParams(location.search).get('pay') === '1') { var opener = document.querySelector('[data-modal=payModal]'); if (opener) setTimeout(function () { opener.click(); }, 50); }
  document.getElementById('proof').addEventListener('change', function (e) { document.getElementById('proofName').textContent = e.target.files[0] ? 'Selected: ' + e.target.files[0].name : ''; });
})();
</script>
@endpush
@endif
@endsection
