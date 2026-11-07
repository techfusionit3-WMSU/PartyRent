@extends('layouts.market', ['title' => $r->event_name, 'nav' => 'rentals'])

@section('content')
<div class="crumbs"><a href="{{ route('rentals.index') }}">My Rentals</a> / {{ $r->code }}</div>
<div class="page-head"><div><h1>{{ $r->event_name }}</h1><p>{{ $r->code }} · {{ $r->event_type }} · submitted {{ fmt_datetime($r->created_at) }}</p></div><div class="row wrap">@include('partials.badges', ['status' => $r->status])@include('partials.badges', ['pay' => $f['status']])</div></div>
<div class="card mb-2">@include('partials.progress')</div>
@if($f['isOverdue'])<div class="alert alert-danger mb-2">{{ icon('alert') }}<div><b>Payment overdue.</b> Outstanding: {{ money($f['overdueAmount']) }}. Please settle as soon as possible to keep your reservation. The supplier will review overdue accounts according to their policy.</div></div>@endif
@if($r->status === 'pending')<div class="alert alert-info mb-2">{{ icon('clock') }}<div>Waiting for <b>{{ $sup->name }}</b> to review your request. Items are not reserved until it is accepted.</div></div>@endif

<div class="grid grid-side">
  <div class="stack">
    <div class="card"><div class="card-head"><h3>Items & daily quantities</h3><span class="small muted">{{ fmt_range($r->start_date, $r->end_date) }}</span></div>@include('partials.lines-table', ['pricing' => $r->pricing])</div>
    <div class="card"><div class="card-head"><h3>Payment history</h3>@if($payTypes)<button class="btn btn-primary btn-sm" data-modal="payModal">{{ icon('card') }}Make a payment</button>@endif</div>@include('partials.payment-history')</div>
    @include('partials.inspection-report')
    <div class="card"><h3>Activity</h3>@include('partials.timeline')</div>
  </div>
  <aside class="stack">
    <div class="card">@include('partials.payment-summary')</div>
    <div class="card"><h3>{{ $r->fulfillment === 'delivery' ? 'Delivery' : 'Pickup' }} details</h3>
      <dl class="kv">
        <dt>Supplier</dt><dd><a href="{{ route('suppliers.show', $sup) }}">{{ $sup->name }}</a><div class="small muted">{{ $sup->phone }}</div></dd>
        @if($r->fulfillment === 'delivery')<dt>Deliver to</dt><dd>{{ $r->address }}</dd>@else<dt>Pickup at</dt><dd>{{ $sup->address }}<div class="small muted">{{ $sup->hours }}</div></dd>@endif
        <dt>Contact</dt><dd>{{ $r->contact_name }} · {{ $r->contact_phone }}</dd>
        <dt>Payment</dt><dd>{{ \App\Models\Payment::ARR_LABEL[$r->arrangement] }} · {{ \App\Models\Payment::METHOD_LABEL[$r->method] ?? $r->method }}</dd>
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
    @if($r->status === 'completed' && ! $review)
      <form class="card" method="POST" action="{{ route('rentals.review', $r) }}">@csrf<h3>Rate your rental</h3>
        <div class="row gap-sm mb-1">@for($i = 1; $i <= 5; $i++)<label class="chip {{ $i === 5 ? 'active' : '' }}"><input type="radio" name="rating" value="{{ $i }}" @checked($i === 5) hidden>{{ $i }}★</label>@endfor</div>
        <div class="field"><textarea class="input" name="comment" required placeholder="How were the items and service?"></textarea></div>
        <button class="btn btn-primary btn-sm">Submit review</button>
      </form>
    @elseif($review)
      <div class="card"><h3>Your review</h3><div class="stars">{{ stars($review->rating) }}</div><p class="mb-0">{{ $review->comment }}</p></div>
    @endif
  </aside>
</div>

@if($payTypes)
<div class="modal-backdrop hidden" id="payModal"><div class="modal" role="dialog" aria-modal="true" aria-label="Make a payment">
  <div class="modal-head"><h3>Make a payment · {{ $r->code }}</h3><button class="icon-btn" data-close aria-label="Close">{{ icon('x') }}</button></div>
  <form method="POST" action="{{ route('rentals.pay', $r) }}" enctype="multipart/form-data" id="pmf">
    @csrf
    <div class="modal-body">
      <div class="field"><label>What are you paying for?</label><select class="input" name="type" id="payKind">@foreach($payTypes as $t)<option value="{{ $t[0] }}" data-amount="{{ $t[2] }}">{{ $t[1] }} — {{ money($t[2]) }}</option>@endforeach</select></div>
      <div class="form-grid"><div class="field"><label>Amount (₱)</label><input class="input" type="number" step="0.01" min="1" name="amount" id="payAmount" required value="{{ old('amount', $payTypes[0][2]) }}"></div>
        <div class="field"><label>Payment method</label><select class="input" name="method" id="payMethod">@foreach($sup->methods as $m)<option value="{{ $m }}">{{ \App\Models\Payment::METHOD_LABEL[$m] ?? $m }}</option>@endforeach</select></div></div>
      <div id="bankBox">
        <div class="alert alert-info mb-2">{{ icon('info') }}<div class="small">Transfer to <b>{{ $sup->name }}</b> using the account details they provided, then enter the reference number and upload your receipt.</div></div>
        <div class="form-grid"><div class="field"><label>Reference number</label><input class="input" name="reference" placeholder="e.g. BDO-123456" maxlength="80"></div><div class="field"><label>Payment date</label><input class="input" type="date" name="date" value="{{ today_iso() }}" max="{{ today_iso() }}"></div></div>
        <div class="field"><label>Payment proof</label><label class="upload-box" for="proof">{{ icon('upload') }}<div class="small">Upload a screenshot or photo of the receipt (JPG/PNG, up to 8 MB)</div></label><input type="file" id="proof" name="proof" accept="image/*" hidden><div class="small muted" id="proofName"></div></div>
      </div>
      <div id="codBox" class="alert alert-warn hidden">{{ icon('cash') }}<div class="small"><b>Cash on Delivery:</b> pay the supplier when the items arrive. The supplier will record it and our cashier will verify it. You can also log it here with the amount you handed over.</div></div>
      <div class="field"><label>Notes (optional)</label><input class="input" name="notes" maxlength="255"></div>
      <p class="small muted mb-0">Your payment will show as <b>Pending Verification</b> until our cashier confirms it. It does not reduce your balance until verified.</p>
    </div>
    <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary" type="submit" data-busy="Submitting…">Submit payment</button></div>
  </form>
</div></div>
@push('scripts')
<script>
(function () {
  var k = document.getElementById('payKind'), a = document.getElementById('payAmount'), m = document.getElementById('payMethod');
  var sync = function () { var bank = m.value === 'bank' || m.value === 'debit'; document.getElementById('bankBox').classList.toggle('hidden', !bank); document.getElementById('codBox').classList.toggle('hidden', bank); };
  k.addEventListener('change', function () { a.value = k.selectedOptions[0].dataset.amount; });
  m.addEventListener('change', sync); sync();
  document.getElementById('proof').addEventListener('change', function (e) { document.getElementById('proofName').textContent = e.target.files[0] ? 'Selected: ' + e.target.files[0].name : ''; });
})();
</script>
@endpush
@endif
@endsection
