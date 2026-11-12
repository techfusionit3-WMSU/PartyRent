@extends('layouts.dash', ['title' => $r->code, 'nav' => 'requests'])

@php
    $hints = ['accepted' => 'Prepare the items once payments are in order.', 'in_progress' => $r->fulfillment === 'delivery' ? 'Send the items out for delivery.' : 'Notify the customer that items are ready for pickup.', 'ready_pickup' => 'Record who picked up the items.', 'out_delivery' => 'Record who received the delivery.', 'ongoing' => 'Mark the rental for return after the event ('.fmt_date($r->end_date).').', 'return_pending' => 'Count and inspect returned items.', 'inspection' => 'Settle the security deposit and complete the rental.'];
    $showAvail = in_array($r->status, ['pending', 'accepted', 'in_progress'], true);
    $settleDisabled = $r->inspection && ($r->inspection['settlement'] ?? '') !== 'pending' && $f['depositHeld'] <= 0;
@endphp

@section('content')
<div class="crumbs"><a href="{{ route('supplier.requests') }}">Rental Requests</a> / {{ $r->code }}</div>
<div class="page-head"><div><h1>{{ $r->event_name }}</h1><p>{{ $r->code }} · {{ $r->event_type }} · event {{ fmt_date($r->event_date) }} · submitted {{ fmt_datetime($r->created_at) }}</p></div><div class="row wrap">@include('partials.badges', ['status' => $r->status])@if($r->status !== 'pending')@include('partials.badges', ['pay' => $f['status']])@endif</div></div>
<div class="card mb-2">@include('partials.progress')</div>

{{-- next step --}}
<div class="card mb-2">
  <div class="row wrap between">
    <div><b>Next step</b><div class="small muted">
      @if($r->status === 'pending' && ! $review['upfrontPaid']) Waiting for the customer's {{ strtolower($review['upfront']['label']) }} ({{ money($review['upfront']['amount']) }}).{{ $review['pendingRental'] > 0 ? ' '.money($review['pendingRental']).' was submitted and is waiting for PartyRent cashier verification.' : ' Nothing paid yet.' }} You can accept once it is verified.
      @elseif($r->status === 'pending') {{ $review['upfront']['label'] }} received ({{ money($review['paidRental']) }} verified). Check the availability table, then accept or decline.
      @elseif($next || $r->status === 'inspection') {{ $hints[$r->status] ?? '' }}
      @else No further action. This request is {{ strtolower(\App\Models\RentalRequest::statusLabel($r->status)) }}.
      @endif
    </div></div>
    <div class="btn-group">
      @if($r->status === 'pending')
        <button class="btn btn-danger" data-modal="declineModal">{{ icon('x') }}Decline</button>
        <button class="btn btn-success" data-modal="acceptModal" @disabled(! $review['upfrontPaid']) @unless($review['upfrontPaid']) title="Available once the down payment is verified" @endunless>{{ icon('check') }}Accept request</button>
      @elseif($r->status === 'inspection')
        <button class="btn" data-modal="settleModal" @disabled($settleDisabled)>{{ icon('shield') }}Settle deposit</button>
        <form method="POST" action="{{ route('supplier.requests.complete', $r) }}">@csrf<button class="btn btn-primary">Complete rental</button></form>
      @else
        @foreach($next as $st)
          @if($st === 'ongoing')<button class="btn btn-primary" data-modal="releaseModal">{{ \App\Domain\Rentals::ACTION_LABEL[$st] }}</button>
          @elseif($st === 'inspection')<button class="btn btn-primary" data-modal="inspectModal">{{ \App\Domain\Rentals::ACTION_LABEL[$st] }}</button>
          @else
            <form method="POST" action="{{ route('supplier.requests.advance', $r) }}" data-confirm="Update {{ $r->code }} to {{ \App\Models\RentalRequest::statusLabel($st) }}? The customer will be notified.{{ $st === 'in_progress' && $f['paidRental'] <= 0 ? ' Note: no verified payment yet ('.$f['status'].').' : '' }}" data-confirm-title="{{ \App\Domain\Rentals::ACTION_LABEL[$st] }}?" data-confirm-ok="Update status">@csrf<input type="hidden" name="to" value="{{ $st }}"><button class="btn btn-primary">{{ \App\Domain\Rentals::ACTION_LABEL[$st] }}</button></form>
          @endif
        @endforeach
      @endif
    </div>
  </div>
  @if($checklist)<div class="row wrap mt-2 gap-sm">@foreach($checklist as $x)<span class="badge {{ $x['ok'] ? 'b-green' : 'b-gray' }}">{{ $x['ok'] ? '✓' : '○' }} {{ $x['label'] }}{{ $x['detail'] ? ' · '.$x['detail'] : '' }}</span>@endforeach</div>@endif
</div>
@if($f['isOverdue'])<div class="alert alert-danger mb-2">{{ icon('alert') }}<div><b>Overdue payment:</b> {{ money($f['overdueAmount']) }} outstanding. Review with the customer and apply your cancellation policy if needed — the rental is never cancelled automatically.</div></div>@endif

<div class="grid grid-side">
  <div class="stack">
    @if($showAvail)
      <div class="card"><div class="card-head"><h3>Date-by-date availability check</h3>
        @if($r->status === 'pending'){!! $conflicts ? '<span class="badge b-red">Cannot accept: insufficient stock</span>' : '<span class="badge b-green">All dates can be accommodated</span>' !!}@else<span class="badge b-sky">Booked</span>@endif</div>
        <p class="small muted">Available = total − other accepted reservations − damaged − missing − maintenance − set-aside. Other pending requests are shown as potential conflicts and do not consume stock.</p>
        <div class="table-wrap"><table class="table cards"><thead><tr><th>Date</th><th>Item</th><th class="num">Requested</th><th class="num">Available</th><th class="num">Other pending</th><th>Result</th></tr></thead><tbody>
          @foreach($availRows as $x)
            <tr class="{{ $x['qn'] > $x['b']['available'] ? 'row-bad' : '' }}"><td data-label="Date">{{ fmt_day($x['d']) }}</td><td data-label="Item">{{ inv()->product($x['pid'])->name }}</td><td data-label="Requested" class="num"><b>{{ $x['qn'] }}</b></td><td data-label="Available" class="num">{{ $x['b']['available'] }}</td><td data-label="Other pending" class="num">{!! $x['b']['pending'] ? '<span style="color:#B45309">'.$x['b']['pending'].'</span>' : '0' !!}</td>
              <td data-label="Result">@if($x['qn'] <= $x['b']['available'])@if($x['qn'] + $x['b']['pending'] > $x['b']['available'] && $r->status === 'pending')<span class="badge b-gold">OK · competing requests</span>@else<span class="badge b-green">OK</span>@endif @else<span class="badge b-red">Short by {{ $x['qn'] - $x['b']['available'] }}</span>@endif</td></tr>
          @endforeach
        </tbody></table></div>
      </div>
    @endif
    <div class="card"><h3>Requested items & daily quantities</h3>@include('partials.lines-table', ['pricing' => $r->pricing])</div>
    <div class="card"><div class="card-head"><h3>Payments</h3>@if($r->isActive())<button class="btn btn-sm" data-modal="cashModal">{{ icon('cash') }}Record cash payment</button>@endif</div>@include('partials.payment-history')<p class="small muted mt-1 mb-0">Customers pay PartyRent. Cash you collect (e.g. on delivery) is collected for PartyRent: hand it over to the PartyRent cashier. It counts once verified.</p>@php($po = $r->payout)<div class="alert {{ $po ? ($po->isPaid() ? 'alert-success' : 'alert-info') : 'alert-info' }} mt-2 mb-0">{{ icon('cash') }}<div class="small">@if($po)<b>Payout {{ $po->code }}:</b> {{ money($po->net) }} {{ $po->isPaid() ? 'sent on '.fmt_date($po->paid_at->toDateString()).' ('.$po->methodLabel().' · '.$po->reference.')' : 'released — PartyRent is sending it to your payout account' }}. <a href="{{ route('supplier.payouts') }}">Earnings & payouts</a>@else @php($held = \App\Domain\Payouts::held($r))<b>Held by PartyRent:</b> {{ money($held) }}. {{ $r->status === 'completed' || $r->status === 'cancelled' ? 'PartyRent releases it after reviewing the request.' : 'Released to you when the rental is completed. Commission is taken from items only; the delivery fee is yours in full.' }}@endif<details class="mt-1"><summary>How your payout is worked out</summary>@include('partials.payout-breakdown', ['b' => $po ? $po->breakdown() : \App\Domain\Payouts::breakdown($r), 'netLabel' => $po ? 'You receive' : 'You receive (so far)'])</details></div></div></div>
    @include('partials.inspection-report')
    <div class="card"><h3>Activity</h3>@include('partials.timeline')</div>
  </div>
  <aside class="stack">
    <div class="card"><h3>Customer</h3>
      <div class="row mb-1"><span class="avatar">{{ $c->initials() }}</span><div><b>{{ $c->name }}</b><div class="small muted">{{ $custHist->count() }} request(s) on PartyRent · {{ $custHist->where('status', 'completed')->count() }} completed</div></div></div>
      <dl class="kv"><dt>Contact</dt><dd>{{ $r->contact_name }}<br>{{ $r->contact_phone }}<br>{{ $c->email }}</dd><dt>Fulfillment</dt><dd>{{ $r->fulfillment === 'delivery' ? 'Delivery to '.$r->address : 'Pickup at your shop' }}@if($r->fulfillment === 'delivery' && ($dsnap = $r->pricing['delivery'] ?? null))<div class="small muted">Quoted {{ money($r->pricing['deliveryFee']) }}{{ $dsnap['distanceKm'] !== null ? ' · ~'.$dsnap['distanceKm'].' km' : '' }}{{ ($dsnap['trips'] ?? 1) > 1 ? ' · '.$dsnap['trips'].' trips' : '' }}</div>@endif</dd><dt>Payment</dt><dd>{{ \App\Models\Payment::ARR_LABEL[$r->arrangement] }} · {{ \App\Models\Payment::METHOD_LABEL[$r->method] ?? $r->method }}</dd></dl>
      @if($r->instructions)<div class="alert alert-info mt-1">{{ icon('message') }}<div class="small"><b>Instructions:</b> {{ $r->instructions }}</div></div>@endif
    </div>
    <div class="card">@include('partials.payment-summary')</div>
    <form class="card" method="POST" action="{{ route('supplier.requests.notes', $r) }}">@csrf<h3>Rental Partner notes</h3><div class="field"><textarea class="input" name="notes" rows="3" placeholder="Notes are visible to the customer.">{{ $r->supplier_notes }}</textarea></div><button class="btn btn-sm">{{ icon('edit') }}Save notes</button></form>
    @if(! empty($r->fulfillment_log['releaseDate']))
      <div class="card"><h3>Release & return log</h3><dl class="kv"><dt>Released</dt><dd>{{ fmt_date($r->fulfillment_log['releaseDate']) }}</dd><dt>Quantity</dt><dd>{{ $r->fulfillment_log['releaseQty'] ?? '' }}</dd><dt>Received by</dt><dd>{{ $r->fulfillment_log['receivedBy'] ?? '' }}</dd>@if(! empty($r->fulfillment_log['returnDate']))<dt>Returned</dt><dd>{{ fmt_date($r->fulfillment_log['returnDate']) }}</dd><dt>Returned qty</dt><dd>{{ $r->fulfillment_log['returnedQty'] ?? '' }}</dd>@endif</dl></div>
    @endif
  </aside>
</div>

{{-- ===== modals ===== --}}
@if($review)
<div class="modal-backdrop hidden" id="acceptModal"><div class="modal" role="dialog" aria-modal="true"><div class="modal-head"><h3>Accept {{ $r->code }}</h3><button class="icon-btn" data-close aria-label="Close">{{ icon('x') }}</button></div>
  <form method="POST" action="{{ route('supplier.requests.accept', $r) }}">@csrf<div class="modal-body"><div class="stack-sm">
    <div class="row">{{ $review['upfrontPaid'] ? '✅' : '❌' }}<b>{{ $review['upfront']['label'] }} received</b></div><div class="small muted" style="padding-left:28px">{{ $review['upfrontPaid'] ? money($review['paidRental']).' verified by PartyRent and held until the rental is completed.' : 'Required: '.money($review['upfront']['amount']).'. Verified so far: '.money($review['paidRental']).'.' }}</div>
    <div class="row">{{ $review['check']['ok'] ? '✅' : '❌' }}<b>1. Inventory re-checked for {{ count(date_range($r->start_date, $r->end_date)) }} date(s)</b></div>
    @if($review['check']['ok'])<div class="small muted" style="padding-left:28px">All quantities fit within available stock.</div>
    @else<div class="alert alert-danger">{{ icon('alert') }}<div>{!! collect($review['check']['bad'])->map(fn ($b) => e(fmt_date($b['date']).': '.$b['name'].' needs '.$b['requested'].', only '.$b['available'].' available'))->implode('<br>') !!}<br><b>Acceptance is blocked to prevent double-booking.</b> Decline, or ask the customer to change quantities.</div></div>@endif
    <div class="row">{{ $review['priceChanged'] ? '⚠️' : '✅' }}<b>2. Pricing validated</b></div><div class="small muted" style="padding-left:28px">{{ $review['priceChanged'] ? 'Your current prices total '.money($review['fresh']['total']).'; the customer was quoted '.money($r->pricing['total']).'. The quoted price will be honored.' : 'Quoted total '.money($r->pricing['total']).' matches your current prices.' }}</div>
    <div class="row">{{ $review['policyIssues'] ? '⚠️' : '✅' }}<b>3. Payment policy confirmed</b></div><div class="small muted" style="padding-left:28px">{{ $review['policyIssues'] ? implode(' ', $review['policyIssues']) : \App\Models\Payment::ARR_LABEL[$r->arrangement].' via '.(\App\Models\Payment::METHOD_LABEL[$r->method] ?? $r->method).'. Security deposit '.money($r->pricing['deposit']).'.' }}</div>
    <div class="row">📅<b>4. Payment requirements to be generated</b></div><div style="padding-left:28px">@foreach($review['schedule'] as $x)<div class="sum-line small"><span>{{ $x['label'] }} · due {{ fmt_date($x['due']) }}</span><b>{{ money($x['amount']) }}</b></div>@endforeach</div>
  </div><div class="field mt-2"><label>Note to customer (optional)</label><textarea class="input" name="note" placeholder="e.g. We will deliver at 8 AM.">{{ $r->supplier_notes }}</textarea></div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-success" @disabled(! $review['canAccept'])>{{ icon('check') }}Accept & reserve stock</button></div></form>
</div></div>
<div class="modal-backdrop hidden" id="declineModal"><div class="modal sm" role="dialog" aria-modal="true"><div class="modal-head"><h3>Decline {{ $r->code }}?</h3><button class="icon-btn" data-close aria-label="Close">{{ icon('x') }}</button></div>
  <form method="POST" action="{{ route('supplier.requests.decline', $r) }}">@csrf<div class="modal-body"><p>The customer will be notified. The request record is kept.</p><div class="field"><label>Reason (shown to the customer)</label><textarea class="input" name="reason" required maxlength="500"></textarea></div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-danger">Decline request</button></div></form>
</div></div>
@endif

@if(in_array('ongoing', $next, true))
<div class="modal-backdrop hidden" id="releaseModal"><div class="modal" role="dialog" aria-modal="true"><div class="modal-head"><h3>Record release to customer</h3><button class="icon-btn" data-close aria-label="Close">{{ icon('x') }}</button></div>
  <form method="POST" action="{{ route('supplier.requests.advance', $r) }}">@csrf<input type="hidden" name="to" value="ongoing"><div class="modal-body">
    <div class="form-grid"><div class="field"><label>Release date</label><input class="input" type="date" name="releaseDate" value="{{ today_iso() }}" required></div><div class="field"><label>Person receiving the items</label><input class="input" name="receivedBy" required value="{{ $r->contact_name ?: $c->name }}"></div></div>
    @php($firstDay = collect($r->lines)->map(function ($l) { $d = array_key_first($l['days']); return $l['days'][$d].'× '.inv()->itemName($l); })->implode(', '))
    <div class="field"><label>Released quantity</label><textarea class="input" name="releaseQty" required>{{ $firstDay }}</textarea><div class="hint">First-day quantities shown. For multi-day rentals with changing quantities, note any later drop-offs.</div></div>
  </div><div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Confirm release</button></div></form>
</div></div>
@endif

@if($r->status === 'return_pending')
<div class="modal-backdrop hidden" id="inspectModal"><div class="modal lg" role="dialog" aria-modal="true"><div class="modal-head"><h3>Return inspection · {{ $r->code }}</h3><button class="icon-btn" data-close aria-label="Close">{{ icon('x') }}</button></div>
  <form method="POST" action="{{ route('supplier.requests.inspection', $r) }}" enctype="multipart/form-data" id="inspForm">@csrf<div class="modal-body">
    <p class="small muted">Count returned units and classify their condition. Damaged (major/total) and missing units are removed from available stock; minor damage goes to a 3-day maintenance hold. Fees are entered by you — never by AI.</p>
    <div class="field"><label>Return date</label><input class="input" type="date" name="returnDate" value="{{ today_iso() }}" required style="max-width:200px"></div>
    @foreach(inv()->saleDemand($r->lines) as $spid => $sq)
      <div class="card card-flat mb-1 soft" style="padding:10px 14px"><div class="row between"><b>{{ inv()->product($spid)?->name }}</b><span class="small">{{ $sq }} sold · <span class="badge b-gold plain">Consumed — not returned</span></span></div></div>
    @endforeach
    @foreach($peak as $pid => $q)
      @php($rv = (float) (inv()->product($pid)->replacement_value ?? 0))
      <div class="card card-flat mb-1" style="padding:14px" data-insp data-value="{{ $rv }}" data-major-pct="{{ setting('majorDamagePct', 50) }}"><div class="row between mb-1"><b>{{ inv()->product($pid)->name }}</b><span class="small muted">Released (peak day): {{ $q }}@if($rv) · Replacement value {{ money($rv) }} each @endif</span></div>
        <div class="form-grid" style="grid-template-columns:repeat(6,minmax(0,1fr))">@foreach([['returned', 'Returned', $q], ['good', 'Good', $q], ['minor', 'Minor damage', 0], ['major', 'Major damage', 0], ['total', 'Completely damaged', 0], ['missing', 'Missing', 0]] as [$k, $l, $v])<div class="field"><label class="small">{{ $l }}</label><input class="input input-sm" type="number" min="0" name="items[{{ $pid }}][{{ $k }}]" value="{{ $v }}"></div>@endforeach</div>
        @if($rv)<p class="small muted mb-1" data-insp-hint>Charges fill in automatically: completely damaged or missing = {{ money($rv) }} each, major damage = {{ setting('majorDamagePct', 50) }}%. Add a repair fee for minor damage. You can change the amounts, up to {{ money($rv) }} per damaged or missing piece.</p>@else<p class="small muted mb-1">Tip: set a replacement value on this item so charges fill in automatically next time.</p>@endif
        <div class="form-grid"><div class="field"><label class="small">Repair fees (₱)</label><input class="input input-sm" type="number" min="0" step="0.01" name="items[{{ $pid }}][repairFee]" value="0"></div><div class="field"><label class="small">Replacement fees (₱)</label><input class="input input-sm" type="number" min="0" step="0.01" name="items[{{ $pid }}][replaceFee]" value="0"></div></div>
      </div>
    @endforeach
    <div class="field"><label>Inspection notes</label><textarea class="input" name="notes" placeholder="Describe any damage…"></textarea></div>
    <div class="field"><label>Photo evidence (up to 4)</label><input class="input" type="file" name="photos[]" accept="image/*" multiple></div>
    <p class="small muted mb-0">Rule: good + minor + major + completely damaged = returned, and returned + missing = released.</p>
  </div><div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Save inspection</button></div></form>
</div></div>
@endif

@if($r->status === 'inspection')
<div class="modal-backdrop hidden" id="settleModal"><div class="modal" role="dialog" aria-modal="true"><div class="modal-head"><h3>Settle security deposit</h3><button class="icon-btn" data-close aria-label="Close">{{ icon('x') }}</button></div>
  <form method="POST" action="{{ route('supplier.requests.settle', $r) }}">@csrf<div class="modal-body">
    <div class="sum-line"><span>Deposit held</span><b>{{ money($f['depositHeld']) }}</b></div><div class="sum-line"><span>Compensation assessed</span><b>{{ money($f['compensation']) }}</b></div><div class="sum-line"><span>Compensation already paid</span><b>{{ money($f['compPaidDirect']) }}</b></div><hr>
    @if($f['compensation'] > 0)
      <div class="stack-sm">@foreach([['deducted', 'Deduct compensation from the deposit', 'Refund '.money(max(0, $f['depositHeld'] - min($f['compensation'], $f['depositPaid']))).($f['compensation'] > $f['depositPaid'] ? '; customer still owes '.money($f['compensation'] - $f['depositPaid']) : '')], ['paid', 'Customer paid compensation separately', 'Refund full deposit '.money($f['depositHeld'])], ['waived', 'Waive compensation', 'Refund full deposit '.money($f['depositHeld'])]] as $i => [$v, $l, $d])
        <label class="card card-flat row" style="padding:12px;cursor:pointer"><input type="radio" name="mode" value="{{ $v }}" @checked($i === 0) style="accent-color:var(--brand);width:18px;height:18px"><div><b>{{ $l }}</b><div class="small muted">{{ $d }}</div></div></label>
      @endforeach</div>
    @else
      <input type="hidden" name="mode" value="settled"><p>No damages — the full deposit of <b>{{ money($f['depositHeld']) }}</b> will be refunded.</p>
    @endif
    <p class="small muted mt-2 mb-0">A deposit refund record is created and the customer is notified. This is logged in the audit trail.</p>
  </div><div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Confirm settlement</button></div></form>
</div></div>
@endif

@if($r->isActive())
<div class="modal-backdrop hidden" id="cashModal"><div class="modal" role="dialog" aria-modal="true"><div class="modal-head"><h3>Record cash payment · {{ $r->code }}</h3><button class="icon-btn" data-close aria-label="Close">{{ icon('x') }}</button></div>
  <form method="POST" action="{{ route('supplier.requests.cash', $r) }}">@csrf<div class="modal-body">
    <div class="alert alert-info mb-2">{{ icon('info') }}<div class="small">Use this when the customer pays you in cash (e.g. Cash on Delivery). You collect it for PartyRent: hand it over to the PartyRent cashier. It only counts once verified, and comes back to you in your payout when the rental is completed.</div></div>
    <div class="form-grid">
      <div class="field"><label>Payment type</label><select class="input" name="type">
        @if($f['balance'] - $f['pendingRental'] > 0)<option value="{{ $sug['type'] }}">{{ $sug['label'] }}</option><option value="balance">Remaining balance</option>@endif
        @if($f['depositPaid'] < $f['depositRequired'])<option value="deposit">Security deposit</option>@endif
        @if($f['compensationDue'] > 0)<option value="compensation">Damage compensation</option>@endif
      </select></div>
      <div class="field"><label>Amount (₱)</label><input class="input" type="number" step="0.01" min="1" name="amount" required value="{{ $sug['amount'] ?: '' }}"></div>
      <div class="field"><label>Date received</label><input class="input" type="date" name="date" value="{{ today_iso() }}" max="{{ today_iso() }}" required></div>
      <div class="field"><label>Acknowledgment / reference no.</label><input class="input" name="reference" required placeholder="e.g. AR-0021" maxlength="80"></div>
    </div>
    <div class="field"><label>Notes</label><input class="input" name="notes" maxlength="255"></div>
  </div><div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Submit for verification</button></div></form>
</div></div>
@endif
@push('scripts')
<script>
// Pre-fill charges from the replacement value until the rental partner types their own amount.
(function () {
  document.querySelectorAll('[data-insp]').forEach(function (box) {
    var v = +box.dataset.value || 0; if (!v) return;
    var pct = (+box.dataset.majorPct || 0) / 100;
    var get = function (k) { var i = box.querySelector('[name$="[' + k + ']"]'); return i ? (+i.value || 0) : 0; };
    var repl = box.querySelector('[name$="[replaceFee]"]');
    repl.addEventListener('input', function () { repl.dataset.touched = '1'; });
    box.addEventListener('input', function (e) {
      if (e.target === repl || repl.dataset.touched) return;
      repl.value = Math.round((get('total') + get('missing') + get('major') * pct) * v * 100) / 100;
    });
  });
})();
// Like the prototype: warn when units are damaged or missing but no fee was entered.
(function () {
  var f = document.getElementById('inspForm'); if (!f) return;
  f.addEventListener('submit', function () {
    if (f.dataset.confirmed) return;
    var sum = function (re) { var t = 0; f.querySelectorAll('input').forEach(function (i) { if (re.test(i.name)) t += +i.value || 0; }); return t; };
    var dmg = sum(/\[(minor|major|total|missing)\]$/), fees = sum(/\[(repairFee|replaceFee)\]$/);
    if (dmg > 0 && fees <= 0) {
      f.dataset.confirmTitle = 'No fees entered';
      f.dataset.confirm = dmg + ' unit(s) are damaged or missing but no compensation was entered. Continue without charging the customer?';
      f.dataset.confirmOk = 'Continue';
    } else { delete f.dataset.confirm; }
  });
})();
</script>
@endpush
@endsection
