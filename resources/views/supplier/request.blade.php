@extends('layouts.dash', ['title' => 'Request Details', 'nav' => 'requests'])

@php
    $hints = ['accepted' => 'Prepare the items once payments are in order.', 'in_progress' => $r->fulfillment === 'delivery' ? 'Send the items out for delivery.' : 'Notify the customer that items are ready for pickup.', 'ready_pickup' => 'Record who picked up the items.', 'out_delivery' => 'Record who received the delivery.', 'ongoing' => 'Mark the rental for return after the event ('.fmt_date($r->end_date).').', 'return_pending' => 'Count and inspect returned items.', 'inspection' => 'Settle the security deposit and complete the rental.'];
    $showAvail = in_array($r->status, ['pending', 'accepted', 'in_progress'], true);
    $settleDisabled = $r->inspection && ($r->inspection['settlement'] ?? '') !== 'pending' && $f['depositHeld'] <= 0;
@endphp

@section('content')
<div data-leave-warn="dirty" data-leave-msg="You started filling in a form on this request (payment, notes or a status step) that was not saved. Leave anyway?" hidden></div>
<div class="crumbs"><a href="{{ route('supplier.requests') }}">Rentals</a> / {{ $r->code }}</div>
<div class="page-head"><div><h1>{{ $r->event_name }}</h1><div class="mb-1">@include('partials.txn-code', ['code' => $r->code, 'side' => 'supplier'])</div><p>@if($r->event_type && $r->event_type !== 'Other'){{ $r->event_type }} · @endif event {{ fmt_date($r->event_date) }} · submitted {{ fmt_datetime($r->created_at) }}</p></div><div class="row wrap">@include('partials.badges', ['status' => $r->status]) @if($r->status !== 'pending') @include('partials.badges', ['pay' => $f['status']])@endif @include('partials.badges', ['planBadge' => $r->plan])</div></div>
@if($r->awaitingDownPayment())<div class="alert alert-warn">{{ icon('clock') }}<div><b>Accepted, waiting for the first payment.</b> Nothing is reserved yet: the stock is held once the {{ mb_strtolower($f['schedule'][0]['label'] ?? 'first payment') }} is verified. The customer has until <b>{{ fmt_datetime($r->pay_by) }}</b>; after that the request is cancelled. If another customer pays first for the same items and time, this one is declined automatically.</div></div>@endif
@include('partials.case-alert', ['r' => $r])
<div class="card mb-2">@include('partials.progress')@include('partials.next-step', ['ns' => \App\Domain\NextStep::rental($r, 'shop')])</div>

{{-- next step --}}
<div class="card mb-2">
  <div class="row wrap between">
    <div><b>Next step</b><div class="small muted">
      @if($r->status === 'pending') Check availability, the inventory allocation{{ $r->plan === 'installment' ? ' and the installment schedule' : '' }}, then accept or decline.
      @elseif($next || $r->status === 'inspection') {{ $hints[$r->status] ?? '' }}
      @else No further action. This request is {{ strtolower(\App\Models\RentalRequest::statusLabel($r->status)) }}.
      @endif
    </div></div>
    <div class="btn-group">
      @if($r->status === 'pending')
        <button class="btn btn-danger" data-modal="declineModal">{{ icon('x') }}Decline</button>
        @if(auth()->user()->orgCan('orders.manage'))<button class="btn btn-success" data-modal="acceptModal" @if($conflicts) title="Not enough stock" @endif>{{ icon('check') }}Accept request</button>@else<span class="small muted">Only the Organization Admin can accept or decline.</span>@endif
      @elseif($r->status === 'inspection')
        <button class="btn" data-modal="settleModal" @disabled($settleDisabled)>{{ icon('shield') }}Settle deposit</button>
        <form method="POST" action="{{ route('supplier.requests.complete', $r) }}" data-confirm="Close {{ $r->code }} as completed? Make sure the inspection and deposit are settled. This can't be undone: the request can't go back to an earlier step." data-confirm-title="Complete rental?" data-confirm-ok="Complete rental">@csrf<button class="btn btn-primary">Complete rental</button></form>
      @else
        @foreach($next as $st)
          @php($why = \App\Domain\Rentals::stepBlockers($r, $st))
          @if($st === 'ongoing')<button class="btn btn-primary" data-modal="releaseModal" @disabled($why)>{{ \App\Domain\Rentals::ACTION_LABEL[$st] }}</button>
          @elseif($st === 'inspection')<button class="btn btn-primary" data-modal="inspectModal">{{ \App\Domain\Rentals::ACTION_LABEL[$st] }}</button>
          @else
            <form method="POST" action="{{ route('supplier.requests.advance', $r) }}" data-confirm="Move {{ $r->code }} to “{{ \App\Models\RentalRequest::statusLabel($st) }}”? The customer will be notified. This can't be undone: the request can't go back to an earlier step." data-confirm-title="{{ \App\Domain\Rentals::ACTION_LABEL[$st] }}?" data-confirm-ok="Yes, move on">@csrf<input type="hidden" name="to" value="{{ $st }}"><button class="btn btn-primary" @disabled($why)>{{ \App\Domain\Rentals::ACTION_LABEL[$st] }}</button></form>
          @endif
        @endforeach
      @endif
    </div>
  </div>
  @php($blockers = collect($next)->flatMap(fn ($st) => \App\Domain\Rentals::stepBlockers($r, $st))->unique()->values())
  @if(! in_array($r->status, ['pending', 'inspection'], true) && $blockers->isNotEmpty())
    <div class="alert alert-warn mt-2 mb-0">{{ icon('lock') }}<div><b>Before the next step:</b><ul class="small" style="margin:4px 0 0;padding-left:18px">@foreach($blockers as $b)<li>{{ $b }}</li>@endforeach</ul></div></div>
  @endif
  @if($checklist)<div class="row wrap mt-2 gap-sm">@foreach($checklist as $x)<span class="badge {{ $x['ok'] ? 'b-green' : 'b-gray' }}">{{ $x['ok'] ? '✓' : '○' }} {{ $x['label'] }}{{ $x['detail'] ? ' · '.$x['detail'] : '' }}</span>@endforeach</div>@endif
</div>
@if($f['isOverdue'])<div class="alert alert-danger mb-2">{{ icon('alert') }}<div><b>Overdue payment:</b> {{ money($f['overdueAmount']) }} outstanding. Review with the customer and apply your cancellation policy if needed — the rental is never cancelled automatically.</div></div>@endif

<div class="grid grid-side">
  <div class="stack">
    @if($showAvail)
      <div class="card"><div class="card-head"><h3>Availability check (date & time)</h3>
        @if($r->status === 'pending'){!! $conflicts ? '<span class="badge b-red">Cannot accept: insufficient stock</span>' : '<span class="badge b-green">All dates and times can be accommodated</span>' !!}@else<span class="badge b-sky">Reserved</span>@endif</div>
        <p class="small muted">Available = total − the highest number of units already booked during an overlapping time (including your preparation and recovery time) − damaged, missing, written-off, maintenance and set-aside units. Bookings at non-overlapping times share the same units.</p>
        <div class="table-wrap"><table class="table cards"><thead><tr><th>Date</th><th>Item</th><th class="num">Requested (peak)</th><th class="num">Available</th><th class="num">Other pending</th><th>Result</th></tr></thead><tbody>
          @foreach($chk['rows'] as $x)
            @php($pend = empty($x['location_id']) && ! empty($x['product_id']) && empty($x['sale']) ? inv()->pendingOn($x['product_id'], $x['date'], $r->id) : 0)
            <tr class="{{ $x['ok'] ? '' : 'row-bad' }}"><td data-label="Date">{{ fmt_day($x['date']) }}</td><td data-label="Item">{{ $x['name'] }}@if(! empty($x['location_id']))<div class="small muted">at {{ $locs->firstWhere('id', $x['location_id'])?->name }}</div>@endif</td>
              <td data-label="Requested (peak)" class="num"><b>{{ $x['requested'] }}</b></td><td data-label="Available" class="num">{{ $x['available'] }}</td>
              <td data-label="Other pending" class="num">@if($pend)<span style="color:#B45309">{{ $pend }}</span>@else 0 @endif</td>
              <td data-label="Result">@if($x['ok'])<span class="badge b-green">OK</span>@else<span class="badge b-red">Short by {{ max(1, $x['requested'] - $x['available']) }}</span>@endif</td></tr>
          @endforeach
        </tbody></table></div>
      </div>
    @endif
    @if($locs->count())
      @php($alloc = $r->allocation['status'] ?? 'proposed')
      <div class="card"><div class="card-head"><h3>Inventory allocation</h3><span class="badge {{ $alloc === 'approved' ? 'b-green' : 'b-gold' }}">{{ $alloc === 'approved' ? 'Approved' : 'Proposed — review before accepting' }}</span></div>
        <p class="small muted">Each line uses stock from one inventory location. PartyRent proposed the sources below; {{ $canAlloc ? 'change them if another location is closer or has the stock.' : 'they can be changed before the items are released.' }}</p>
        <div class="table-wrap"><table class="table cards"><thead><tr><th>Item</th><th>Deliver to</th><th>Qty / dates</th><th>Inventory source</th></tr></thead><tbody>
        @foreach($r->lines as $l)
          @php($ld = array_keys($l['days']))
          @php(sort($ld))
          @php($dest = collect($r->destinations ?? [])->firstWhere('id', $l['destId'] ?? null))
          <tr><td data-label="Item"><b>{{ inv()->itemName($l) }}</b><div class="small muted">{{ \App\Domain\Slots::rangeLabel(\App\Domain\Slots::time($l)) }}{{ \App\Domain\Slots::time($l) ? ' · '.\App\Domain\Slots::duration($l)['label'] : '' }}</div></td>
            <td data-label="Deliver to">{{ $dest['label'] ?? ($r->fulfillment === 'pickup' ? 'Pickup' : '—') }}</td>
            <td data-label="Qty / dates">{{ max($l['days']) }}× · {{ fmt_range($ld[0], end($ld)) }}</td>
            <td data-label="Inventory source">@if($canAlloc)
              <form method="POST" action="{{ route('supplier.requests.source', $r) }}">@csrf<input type="hidden" name="line" value="{{ $l['id'] }}"><select class="input input-sm" name="location" data-autosubmit aria-label="Inventory source">
                @foreach($locs as $loc)
                  @php($av = $l['type'] === 'item' ? inv()->availableAtLocation((int) $l['ref_id'], $loc->id, $ld[0], $r->id, inv()->lineWindow($l, $ld[0])) : null)
                  <option value="{{ $loc->id }}" @selected((int) ($l['sourceId'] ?? 0) === $loc->id)>{{ $loc->name }}{{ $av !== null ? ' ('.$av.' free)' : '' }}</option>
                @endforeach
              </select></form>
            @else{{ $locs->firstWhere('id', (int) ($l['sourceId'] ?? 0))?->name ?? '—' }}@endif</td></tr>
        @endforeach
        </tbody></table></div>
      </div>
    @endif
    @if($r->plan === 'installment')
      @php($ip = $r->installment_plan)
      <div class="card"><div class="card-head"><h3>Installment schedule</h3>@if($ip)<span class="badge {{ ['approved' => 'b-green', 'proposed' => 'b-gold', 'rejected' => 'b-red'][$ip['status']] ?? 'b-gray' }}">{{ ['approved' => 'Approved', 'proposed' => 'Proposed by customer', 'rejected' => 'Rejected'][$ip['status']] ?? '' }}</span>@endif</div>
        @if($ip)
          <div class="table-wrap"><table class="table cards"><thead><tr><th>Installment</th><th>Due</th><th class="num">Amount</th></tr></thead><tbody>
            @foreach($ip['items'] as $i => $x)<tr><td data-label="Installment">Installment {{ $i + 1 }} of {{ count($ip['items']) }}</td><td data-label="Due">{{ fmt_date($x['due']) }}@if($x['due'] > $r->event_date) <span class="badge b-red">after event</span>@endif</td><td data-label="Amount" class="num"><b>{{ money($x['amount']) }}</b></td></tr>@endforeach
          </tbody></table></div>
          <p class="small muted mt-1">Installment balance {{ money($r->pricing['finalBalance']) }} (after the {{ $r->pricing['terms']['downPct'] ?? 0 }}% down payment{{ '' }}@if(($r->pricing['reservationFee'] ?? 0) > 0) and {{ $r->pricing['terms']['resPct'] ?? 0 }}% reservation fee{{ '' }}@endif) · last allowed due date {{ fmt_date($r->event_date) }}.</p>
          @if($ip['status'] === 'rejected')<div class="alert alert-danger">{{ icon('x') }}<div class="small">Rejected: {{ $ip['reason'] ?? '' }} — waiting for the customer to propose a new schedule.</div></div>@endif
          @if($ip['status'] === 'proposed' && auth()->user()->orgCan('orders.manage') && ! in_array($r->status, ['declined', 'cancelled', 'completed'], true))
            <div class="btn-group mt-1"><button class="btn btn-danger btn-sm" data-modal="ipRejModal">{{ icon('x') }}Reject schedule</button>
              <form method="POST" action="{{ route('supplier.requests.schedule', [$r, 'approve']) }}" data-confirm="{{ count($ip['items']) }} payments, the last one due {{ fmt_date(end($ip['items'])['due']) }}. The customer will be notified." data-confirm-title="Approve installment schedule?" data-confirm-ok="Approve">@csrf<button class="btn btn-success btn-sm">{{ icon('check') }}Approve schedule</button></form></div>
            <div class="modal-backdrop hidden" id="ipRejModal"><div class="modal sm" role="dialog" aria-modal="true"><div class="modal-head"><h3>Reject installment schedule?</h3><button class="icon-btn" data-close aria-label="Close">{{ icon('x') }}</button></div>
              <form method="POST" action="{{ route('supplier.requests.schedule', [$r, 'reject']) }}">@csrf<div class="modal-body"><div class="field"><label>Reason (the customer will propose a new schedule)</label><textarea class="input" name="reason" required></textarea></div></div>
              <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-danger">Reject</button></div></form></div></div>
          @endif
        @else<p class="muted small">The customer has not proposed a schedule yet.</p>@endif
      </div>
    @endif
    <div class="card"><h3>Requested items, times & daily quantities</h3>@include('partials.lines-table', ['pricing' => $r->pricing, 'r' => $r, 'showSource' => true])</div>
    @php($pendPays = $r->payments->where('status', 'pending'))
    <div class="card" id="payments"><div class="card-head"><h3>Payments</h3><div class="row wrap" style="gap:6px">@if($canRemind && $due)<button class="btn btn-sm" data-modal="remindModal">{{ icon('bell') }}Notify customer</button>@endif @if($canRecord)<button class="btn btn-sm btn-primary" data-modal="cashModal">{{ icon('cash') }}Record payment</button>@endif</div></div>
      @if($due && in_array($r->status, \App\Domain\Finance::ACTIVE, true))
        <div class="due-strip mb-1">@foreach($due as $d)<div><span class="small muted">{{ $d['label'] }}</span><b>{{ money($d['amount']) }}</b>@if($d['due'])<span class="small {{ $d['due'] < today_iso() ? 'text-red' : 'muted' }}">due {{ fmt_date($d['due']) }}</span>@endif</div>@endforeach</div>
      @endif
      @if($pendPays->count())<div class="alert alert-warn mb-1">{{ icon('clock') }}<div class="grow">{{ $pendPays->count() }} payment(s) awaiting verification. @if($finOK)Check each one below and verify or reject it here. <a href="{{ route('supplier.payments', ['tab' => 'verify']) }}" class="small">Open the Payments page</a>@else Your Cashier or Organization Admin verifies them.@endif</div></div>@endif
      @include('partials.payment-history', ['inlineVerify' => $finOK])<p class="small muted mt-1 mb-0">Only verified payments reduce the balance. Duplicate reference numbers and amounts above the outstanding balance are blocked.</p></div>
    @include('partials.inspection-report', ['viewer' => 'supplier'])
    @if(! $r->inspection && ! empty($r->fulfillment_log['releasePhotos']))<div class="card"><h3>Condition at release</h3><p class="small muted">Photos the shop took when the items were handed over. They are compared with the condition at return.</p><div class="thumbs">@foreach($r->fulfillment_log['releasePhotos'] as $ph)<a href="{{ asset('storage/'.$ph) }}" target="_blank" rel="noopener"><img src="{{ asset('storage/'.$ph) }}" alt="Condition at release"></a>@endforeach</div></div>@endif
    @include('partials.request-chat', ['chatSide' => 'supplier'])
    <div class="card"><h3>Activity</h3>@include('partials.timeline')</div>
  </div>
  <aside class="stack">
    <div class="card"><h3>Customer</h3>
      <div class="row mb-1"><span class="avatar">{{ $c->initials() }}</span><div><b>{{ $c->name }}</b><div class="small muted">{{ $custHist->count() }} request(s) on PartyRent · {{ $custHist->where('status', 'completed')->count() }} completed</div></div></div>
      <dl class="kv"><dt>Requested by</dt><dd>{{ $c->name }} <span class="muted">(account)</span><br>{{ $c->email }}@if($c->phone)<br>{{ $c->phone }}@endif</dd><dt>Contact on the day</dt><dd>{{ $r->contact_name ?: $c->name }}@if($r->contact_name && $r->contact_name !== $c->name) <span class="badge b-gray">not the account holder</span>@endif<br>{{ $r->contact_phone }}</dd><dt>Payment</dt><dd>{{ \App\Domain\Finance::PLAN_LABEL[$r->plan] ?? '' }} · {{ \App\Domain\Finance::METHOD_LABEL[$r->method] ?? $r->method }}</dd></dl>
      @if($r->instructions)<div class="alert alert-info mt-1">{{ icon('message') }}<div class="small"><b>Instructions:</b> {{ $r->instructions }}</div></div>@endif
    </div>
    <div class="card"><h3>{{ $r->fulfillment === 'delivery' ? (count($r->destinations ?? []) > 1 ? count($r->destinations).' delivery locations' : 'Delivery location') : 'Pickup' }}</h3>@if($r->fulfillment === 'delivery')@include('partials.destinations')@else<div class="small">Pickup at <b>{{ $r->supplier->address }}</b><div class="muted">{{ $r->supplier->hours }}</div></div>@endif</div>
    <div class="card">@include('partials.payment-summary')</div>
    <div class="card"><h3>Supplier notes</h3><p class="small">@if($r->supplier_notes){{ $r->supplier_notes }}@else<span class="muted">No notes yet. Notes are visible to the customer.</span>@endif</p>@if(auth()->user()->orgCan('orders.manage'))<button class="btn btn-sm" data-modal="notesModal">{{ icon('edit') }}Edit notes</button>@endif</div>
    <div class="modal-backdrop hidden" id="notesModal"><div class="modal" role="dialog" aria-modal="true"><div class="modal-head"><h3>Supplier notes</h3><button class="icon-btn" data-close aria-label="Close">{{ icon('x') }}</button></div><form method="POST" action="{{ route('supplier.requests.notes', $r) }}">@csrf<div class="modal-body"><div class="field"><label for="sn">Notes for this rental (visible to customer)</label><textarea class="input" id="sn" name="notes" rows="4">{{ $r->supplier_notes }}</textarea></div></div><div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Save notes</button></div></form></div></div>
    @if(! empty($r->fulfillment_log['releaseDate']))
      <div class="card"><h3>Release & return log</h3><dl class="kv"><dt>Released</dt><dd>{{ fmt_date($r->fulfillment_log['releaseDate']) }}</dd><dt>Quantity</dt><dd>{{ $r->fulfillment_log['releaseQty'] ?? '' }}</dd><dt>Received by</dt><dd>{{ $r->fulfillment_log['receivedBy'] ?? '' }}</dd>@if(! empty($r->fulfillment_log['returnDate']))<dt>Returned</dt><dd>{{ fmt_date($r->fulfillment_log['returnDate']) }}</dd><dt>Returned qty</dt><dd>{{ $r->fulfillment_log['returnedQty'] ?? '' }}</dd>@endif</dl></div>
    @endif
  </aside>
</div>

{{-- ===== modals ===== --}}
@if($review)
<div class="modal-backdrop hidden" id="acceptModal"><div class="modal" role="dialog" aria-modal="true"><div class="modal-head"><h3>Accept {{ $r->code }}</h3><button class="icon-btn" data-close aria-label="Close">{{ icon('x') }}</button></div>
  <form method="POST" action="{{ route('supplier.requests.accept', $r) }}" data-confirm="The customer then has 24 hours to pay the first payment. The stock is reserved only once that payment is verified; if another customer pays first for the same items and time, this request is declined automatically. This can't be undone." data-confirm-title="Accept {{ $r->code }}?" data-confirm-ok="Accept">@csrf<div class="modal-body"><div class="stack-sm">
    <div class="row">{{ $review['check']['ok'] ? '✅' : '❌' }}<b>1. Inventory re-checked for {{ count(date_range($r->start_date, $r->end_date)) }} date(s)</b></div>
    @if($review['check']['ok'])<div class="small muted" style="padding-left:28px">All quantities fit within available stock.</div>
    @else<div class="alert alert-danger">{{ icon('alert') }}<div>{!! collect($review['check']['bad'])->map(fn ($b) => e(fmt_date($b['date']).': '.$b['name'].' needs '.$b['requested'].', only '.$b['available'].' available'))->implode('<br>') !!}<br><b>Acceptance is blocked to prevent double-booking.</b> Decline, or ask the customer to change quantities.</div></div>@endif
    <div class="row">{{ $review['priceChanged'] ? '⚠️' : '✅' }}<b>2. Pricing validated</b></div><div class="small muted" style="padding-left:28px">{{ $review['priceChanged'] ? 'Your current prices total '.money($review['fresh']['total']).'; the customer was quoted '.money($r->pricing['total']).'. The quoted price will be honored.' : 'Quoted total '.money($r->pricing['total']).' matches your current prices.' }}</div>
    <div class="row">{{ $review['policyIssues'] ? '⚠️' : '✅' }}<b>3. Payment plan confirmed</b></div><div class="small muted" style="padding-left:28px">{{ $review['policyIssues'] ? implode(' ', $review['policyIssues']) : (\App\Domain\Finance::PLAN_LABEL[$r->plan] ?? '').'. Security deposit '.money($r->pricing['deposit']).'.' }}</div>
    @if($review['durationNotes'])<div class="row">⚠️<b>Rental duration</b></div><div class="small muted" style="padding-left:28px">{{ collect($review['durationNotes'])->pluck('message')->implode(' ') }} (It complied when submitted, so you may still accept.)</div>@endif
    @php($probe = (clone $r)->forceFill(['accepted_at' => today_iso(), 'pay_by' => now()->addHours(\App\Domain\Rentals::PAY_HOURS)]))
    <div class="row">📅<b>4. Payment schedule from today</b></div><div style="padding-left:28px">@foreach(\App\Domain\Finance::schedule($probe) as $x)<div class="sum-line small"><span>{{ $x['label'] }} · due {{ isset($x['dueAt']) ? fmt_datetime($x['dueAt']) : fmt_date($x['due']) }}</span><b>{{ money($x['amount']) }}</b></div>@endforeach</div>
    <div class="alert alert-info mt-1">{{ icon('info') }}<div class="small"><b>The stock is reserved when the first payment is verified,</b> not when you accept. The customer has {{ \App\Domain\Rentals::PAY_HOURS }} hours to pay it, or the request is cancelled. If another customer pays first for the same items, day and time, and your stock can't cover both, this request is declined automatically.</div></div>
  </div><div class="field mt-2"><label>Note to customer (optional)</label><textarea class="input" name="note" placeholder="e.g. We will deliver at 8 AM.">{{ $r->supplier_notes }}</textarea></div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-success" @disabled(! $review['canAccept'])>{{ icon('check') }}Accept request</button></div></form>
</div></div>
<div class="modal-backdrop hidden" id="declineModal"><div class="modal sm" role="dialog" aria-modal="true"><div class="modal-head"><h3>Decline {{ $r->code }}?</h3><button class="icon-btn" data-close aria-label="Close">{{ icon('x') }}</button></div>
  <form method="POST" action="{{ route('supplier.requests.decline', $r) }}" data-confirm="The customer is told the request was declined. A declined request can't be reopened." data-confirm-title="Decline {{ $r->code }}?" data-confirm-ok="Decline" data-danger>@csrf<div class="modal-body"><p>The customer will be notified. The request record is kept.</p><div class="field"><label>Reason (shown to the customer)</label><textarea class="input" name="reason" required maxlength="500"></textarea></div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-danger">Decline request</button></div></form>
</div></div>
@endif

@if(in_array('ongoing', $next, true))
<div class="modal-backdrop hidden" id="releaseModal"><div class="modal" role="dialog" aria-modal="true"><div class="modal-head"><h3>Record release to customer</h3><button class="icon-btn" data-close aria-label="Close">{{ icon('x') }}</button></div>
  <form method="POST" action="{{ route('supplier.requests.advance', $r) }}" enctype="multipart/form-data" data-confirm="Record that the items were handed to the customer. Consumables are deducted from stock. This can't be undone: the request can't go back to an earlier step." data-confirm-title="Confirm release?" data-confirm-ok="Yes, released">@csrf<input type="hidden" name="to" value="ongoing"><div class="modal-body">
    <div class="form-grid"><div class="field"><label>Release date</label><input class="input" type="date" name="releaseDate" value="{{ today_iso() }}" required></div><div class="field"><label>Person receiving the items</label><input class="input" name="receivedBy" required value="{{ $r->contact_name ?: $c->name }}"></div></div>
    @php($firstDay = collect($r->lines)->map(function ($l) { $d = array_key_first($l['days']); return $l['days'][$d].'× '.inv()->itemName($l); })->implode(', '))
    <div class="field"><label>Released quantity</label><textarea class="input" name="releaseQty" required>{{ $firstDay }}</textarea><div class="hint">First-day quantities shown. For multi-day rentals with changing quantities, note any later drop-offs.</div></div>
    @if(\App\Domain\Damage::usesOutcomes($r->supplier) && $peak)
      <div class="field"><label>Condition photos at release (1 to 6)</label><input class="input" type="file" name="release_photos[]" accept="image/*" multiple required>
        <div class="hint">Photograph the items as they are handed over. The customer sees these photos; if something is damaged later, they are compared with the return photos.</div></div>
    @endif
  </div><div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Confirm release</button></div></form>
</div></div>
@endif

@php($outcomes = \App\Domain\Damage::usesOutcomes($r->supplier))
@if($r->status === 'return_pending' && $outcomes)
<div class="modal-backdrop hidden" id="inspectModal"><div class="modal lg" role="dialog" aria-modal="true"><div class="modal-head"><h3>Return inspection · {{ $r->code }}</h3><button class="icon-btn" data-close aria-label="Close">{{ icon('x') }}</button></div>
  <form method="POST" action="{{ route('supplier.requests.inspection', $r) }}" enctype="multipart/form-data" id="outForm" novalidate>@csrf<input type="hidden" name="confirmed" value="1"><div class="modal-body">
    <p class="small muted">Count what came back and say what happened to any unit with a problem. <b>Minor</b> = your minor fee. <b>Repairable</b> = what the repair costs (never more than the replacement value). <b>Not working</b> is free unless the customer misused it. <b>Beyond repair</b> and <b>lost</b> = the replacement value. The customer then has {{ \App\Domain\Damage::REVIEW_HOURS }} hours to accept or dispute each charge.</p>
    <div class="field"><label>Return date</label><input class="input" type="date" name="returnDate" value="{{ today_iso() }}" required style="max-width:200px"></div>
    @foreach(inv()->saleDemand($r->lines) as $spid => $sq)
      <div class="card card-flat mb-1 soft" style="padding:10px 14px"><div class="row between"><b>{{ inv()->product($spid)?->name }}</b><span class="small">{{ $sq }} sold · <span class="badge b-gold plain">Consumed — not returned</span></span></div></div>
    @endforeach
    @foreach($peak as $pid => $q)
      @php($p = inv()->product($pid))
      <div class="card card-flat mb-1" data-out data-name="{{ $p->name }}" data-rented="{{ $q }}" data-value="{{ (float) $p->replacement_value }}" data-fee="{{ (float) $p->minor_fee }}" style="padding:14px">
        <div class="mb-1"><b>{{ $p->name }}</b><div class="small muted">Rented {{ $q }} · replacement value {{ $p->replacement_value ? money($p->replacement_value) : 'not set' }} · minor fee {{ $p->minor_fee !== null ? money($p->minor_fee) : 'not set' }}</div></div>
        <div class="form-grid" style="grid-template-columns:repeat(3,minmax(0,1fr))">
          <div class="field"><label class="small">Returned</label><input class="input input-sm" type="number" min="0" max="{{ $q }}" name="items[{{ $pid }}][returned]" data-k="returned" value="{{ $q }}"></div>
          <div class="field"><label class="small">Minor (cleaning, stain)</label><input class="input input-sm" type="number" min="0" name="items[{{ $pid }}][minor]" data-k="minor" value="0"></div>
          <div class="field"><label class="small">Lost / not returned</label><input class="input input-sm" type="number" data-k="lost" value="0" readonly tabindex="-1" aria-label="Lost units (rented minus returned)"></div>
          <div class="field"><label class="small">Repairable</label><input class="input input-sm" type="number" min="0" name="items[{{ $pid }}][repairable]" data-k="repairable" value="0"></div>
          <div class="field"><label class="small">Repair cost, all units (₱)</label><input class="input input-sm" type="number" min="0" step="0.01" name="items[{{ $pid }}][repairCost]" data-k="repairCost" placeholder="from a quote or receipt"></div>
          <div class="field"><label class="small">Beyond repair</label><input class="input input-sm" type="number" min="0" name="items[{{ $pid }}][beyond]" data-k="beyond" value="0"></div>
          <div class="field"><label class="small">Not working</label><input class="input input-sm" type="number" min="0" name="items[{{ $pid }}][notWorking]" data-k="notWorking" value="0"></div>
          <div class="field" style="grid-column:span 2"><label class="check small"><input type="checkbox" name="items[{{ $pid }}][misuse]" value="1" data-k="misuse"> Caused by misuse (water, dropped, wrong power…), shown in the photos</label>
            <input class="input input-sm mt-1" type="number" min="0" step="0.01" name="items[{{ $pid }}][misuseCost]" data-k="misuseCost" placeholder="Repair cost (₱)" hidden></div>
        </div>
        <div class="field"><label class="small">What happened</label><textarea class="input" name="items[{{ $pid }}][description]" data-k="description" rows="2" placeholder="e.g. One table leg cracked; repair shop quote ₱600"></textarea></div>
        <div class="row between small"><span class="muted" data-calc></span><b data-amt>₱0.00</b></div><div class="error-text" data-err></div>
      </div>
    @endforeach
    <div class="field"><label>Inspection notes</label><textarea class="input" name="notes" placeholder="General notes for the customer…"></textarea></div>
    <div class="field"><label>Photos of the returned items (up to 4)</label><input class="input" type="file" name="photos[]" accept="image/*" multiple><div class="hint">Needed for any charge: they are compared with the photos taken at release.</div></div>
    <div class="sum-line total"><span>Total compensation</span><span id="outT">₱0.00</span></div>
  </div><div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Confirm assessment</button></div></form>
</div></div>
@endif

@if($r->status === 'return_pending' && ! $outcomes)
@php($C = \App\Domain\Damage::CONDITIONS)
<div class="modal-backdrop hidden" id="inspectModal"><div class="modal lg" role="dialog" aria-modal="true"><div class="modal-head"><h3>Return inspection · {{ $r->code }}</h3><button class="icon-btn" data-close aria-label="Close">{{ icon('x') }}</button></div>
  <form method="POST" action="{{ route('supplier.requests.inspection', $r) }}" enctype="multipart/form-data" id="inspForm" novalidate>@csrf<input type="hidden" name="confirmed" value="1"><div class="modal-body">
    <p class="small muted">Count the returned units and classify any damage. Compensation is calculated automatically from <b>your damage policy</b>: product value × compensation % × affected quantity. Units not returned count as <b>Lost or Not Returned</b>. Nothing is charged until you review and confirm.</p>
    <div class="field"><label>Return date</label><input class="input" type="date" name="returnDate" value="{{ today_iso() }}" required style="max-width:200px"></div>
    @foreach(inv()->saleDemand($r->lines) as $spid => $sq)
      <div class="card card-flat mb-1 soft" style="padding:10px 14px"><div class="row between"><b>{{ inv()->product($spid)?->name }}</b><span class="small">{{ $sq }} sold · <span class="badge b-gold plain">Consumed — not returned</span></span></div></div>
    @endforeach
    @foreach($peak as $pid => $q)
      @php($p = inv()->product($pid))
      @php($rt = \App\Domain\Damage::rates($r->supplier, $p))
      @php($pcs = ['repairable' => $rt['repairable']['enabled'] ? +$rt['repairable']['pct'] : 0, 'nonRepairable' => $rt['nonRepairable']['enabled'] ? +$rt['nonRepairable']['pct'] : 0, 'lost' => $rt['lost']['enabled'] ? +$rt['lost']['pct'] : 0])
      <div class="card card-flat mb-1" data-insp data-name="{{ $p->name }}" data-rented="{{ $q }}" data-value="{{ (float) $p->replacement_value }}" data-pcts="{{ json_encode($pcs) }}" style="padding:14px">
        <div class="row between wrap mb-1"><div><b>{{ $p->name }}</b><div class="small muted">Rented: {{ $q }} · condition before rental: {{ $C[$rt['condition']] }} · product value {{ money($p->replacement_value ?? 0) }}</div></div>
          <div class="row wrap gap-sm">@foreach([['repairable', 'Repairable'], ['nonRepairable', 'Non-repairable'], ['lost', 'Lost ('.$C[$rt['condition']].')']] as [$k, $lbl])<span class="badge {{ $rt[$k]['enabled'] ? 'b-gray' : 'b-red' }} plain">{{ $lbl }}: {{ $rt[$k]['enabled'] ? +$rt[$k]['pct'].'%' : 'off' }}</span>@endforeach @if($rt['source'] === 'category')<span class="badge b-purple plain">category rule</span>@endif</div></div>
        <div class="form-grid" style="grid-template-columns:repeat(4,minmax(0,1fr))">
          <div class="field"><label class="small">Returned</label><input class="input input-sm" type="number" min="0" max="{{ $q }}" name="items[{{ $pid }}][returned]" data-k="returned" value="{{ $q }}"></div>
          <div class="field"><label class="small">Repairable damage</label><input class="input input-sm" type="number" min="0" name="items[{{ $pid }}][repairable]" data-k="repairable" value="0"></div>
          <div class="field"><label class="small">Non-repairable damage</label><input class="input input-sm" type="number" min="0" name="items[{{ $pid }}][nonRepairable]" data-k="nonRepairable" value="0"></div>
          <div class="field"><label class="small">Lost / not returned</label><input class="input input-sm" type="number" data-k="lost" value="0" readonly tabindex="-1" aria-label="Lost units (rented minus returned)"></div>
        </div>
        <div class="field"><label class="small">Description of the damage or loss</label><textarea class="input" name="items[{{ $pid }}][description]" data-k="description" rows="2" placeholder="e.g. Two chairs with cracked legs"></textarea></div>
        <div class="row between small"><span class="muted" data-calc></span><b data-amt>₱0.00</b></div><div class="error-text" data-err></div>
      </div>
    @endforeach
    <div class="field"><label>Inspection notes</label><textarea class="input" name="notes" placeholder="General notes for the customer…"></textarea></div>
    <div class="field"><label>Photo evidence (up to 4)</label><input class="input" type="file" name="photos[]" accept="image/*" multiple></div>
    <div class="sum-line total"><span>Total compensation</span><span id="compT">₱0.00</span></div>
  </div><div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Review assessment</button></div></form>
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

@if($canRemind && $due)
<div class="modal-backdrop hidden" id="remindModal"><div class="modal" role="dialog" aria-modal="true"><div class="modal-head"><h3>Notify {{ $c->firstName() }} about payment · {{ $r->code }}</h3><button class="icon-btn" data-close aria-label="Close">{{ icon('x') }}</button></div>
  <form method="POST" action="{{ route('supplier.requests.remind', $r) }}">@csrf<div class="modal-body">
    <div class="due-strip mb-2">@foreach($due as $d)<div><span class="small muted">{{ $d['label'] }}</span><b>{{ money($d['amount']) }}</b>@if($d['due'])<span class="small muted">due {{ fmt_date($d['due']) }}</span>@endif</div>@endforeach</div>
    <div class="field"><label for="remind-msg">Message to {{ $c->name }}</label><textarea class="input" id="remind-msg" name="message" rows="5" maxlength="{{ \App\Domain\Chat::MAX }}">{{ $reminderText }}</textarea><div class="hint">Sent as a notification and added to your messages with the customer. One reminder every 6 hours.</div></div>
  </div><div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">{{ icon('send') }}Send reminder</button></div></form>
</div></div>
@endif

@if($canRecord)
<div class="modal-backdrop hidden" id="cashModal"><div class="modal" role="dialog" aria-modal="true"><div class="modal-head"><h3>Record payment · {{ $r->code }}</h3><button class="icon-btn" data-close aria-label="Close">{{ icon('x') }}</button></div>
  <form method="POST" action="{{ route('supplier.requests.cash', $r) }}">@csrf<div class="modal-body">
    <div class="alert alert-info mb-2">{{ icon('info') }}<div class="small">Use this when {{ $c->name }} paid your shop directly — for example <b>cash handed over on delivery, at pickup or at your shop</b>. It is verified at once, a receipt is issued and the customer is notified. The customer doesn't need to do anything on the website.</div></div>
    <div class="form-grid">
      <div class="field"><label>Payment type</label><select class="input" name="type">
        @if($f['balance'] - $f['pendingRental'] > 0)<option value="{{ $sug['type'] }}">{{ $sug['label'] }}</option><option value="balance">Remaining balance</option>@endif
        @if($f['depositPaid'] < $f['depositRequired'])<option value="deposit">Security deposit{{ term('security_deposit') }}</option>@endif
        @if($f['compensationDue'] > 0)<option value="compensation">Damage compensation</option>@endif
      </select></div>
      <div class="field"><label>Amount (₱)</label><input class="input" type="number" step="0.01" min="1" name="amount" required value="{{ $sug['amount'] ?: '' }}"></div>
      <div class="field"><label>Date received</label><input class="input" type="date" name="date" value="{{ today_iso() }}" max="{{ today_iso() }}" required></div>
      <div class="field"><label>Paid by</label><select class="input" name="method"><option value="cash">Cash (in person)</option><option value="bank">Bank transfer / e-wallet</option><option value="debit">Debit card</option></select></div>
      <div class="field"><label>Reference / receipt no. <span class="muted">(optional for cash)</span></label><input class="input" name="reference" placeholder="e.g. AR-0021" maxlength="80"><div class="hint">Leave blank for cash — the receipt number we issue is the proof.</div></div>
    </div>
    <div class="field"><label>Notes</label><input class="input" name="notes" maxlength="255"></div>
  </div><div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Record & issue receipt</button></div></form>
</div></div>
@endif
@push('scripts')
<script>
// Return inspection, personal shops: minor fee / repair cost (capped) / not working (free unless misuse) / beyond repair / lost.
(function () {
  var f = document.getElementById('outForm'); if (!f) return;
  var peso = function (n) { return '₱' + (Math.round(n * 100) / 100).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
  var cards = Array.prototype.slice.call(f.querySelectorAll('[data-out]'));
  var calc = function () {
    var errors = [], total = 0;
    cards.forEach(function (c) {
      var el = function (k) { return c.querySelector('[data-k=' + k + ']'); };
      var n = function (k) { return Math.max(0, Math.floor(+el(k).value || 0)); };
      var m = function (k) { return Math.max(0, +el(k).value || 0); };
      var rented = +c.dataset.rented, v = +c.dataset.value || 0, fee = +c.dataset.fee || 0, name = c.dataset.name;
      var ret = n('returned'), minor = n('minor'), rep = n('repairable'), nw = n('notWorking'), bey = n('beyond'), lost = Math.max(0, rented - ret);
      var misuse = el('misuse').checked && nw > 0; el('misuseCost').hidden = !el('misuse').checked;
      var cap = function (u, cost) { return v > 0 ? Math.min(cost, v * u) : cost; };
      var a = { minor: fee * minor, rep: rep ? cap(rep, m('repairCost')) : 0, nw: misuse ? cap(nw, m('misuseCost')) : 0, bey: v * bey, lost: v * lost };
      var t = a.minor + a.rep + a.nw + a.bey + a.lost; total += t;
      el('lost').value = lost;
      var errs = [];
      if (ret > rented) errs.push('returned cannot exceed ' + rented + '.');
      if (minor + rep + nw + bey > ret) errs.push('units with a problem cannot exceed the units returned.');
      if (rep && !(m('repairCost') > 0)) errs.push('enter the repair cost.');
      if (misuse && !(m('misuseCost') > 0)) errs.push('enter the repair cost for the misused units.');
      if (minor + rep + nw + bey + lost > 0 && !el('description').value.trim()) errs.push('describe what happened.');
      c.querySelector('[data-calc]').textContent = [minor ? minor + ' minor × ' + peso(fee) : '', rep ? 'repair ' + peso(a.rep) : '', nw ? nw + ' not working' + (misuse ? ' (misuse) ' + peso(a.nw) : ' — free') : '', bey ? bey + ' beyond repair × ' + peso(v) : '', lost ? lost + ' lost × ' + peso(v) : ''].filter(Boolean).join(' + ') || 'No problems';
      c.querySelector('[data-amt]').textContent = peso(t);
      var er = c.querySelector('[data-err]'); er.textContent = errs.join(' '); er.style.display = errs.length ? 'block' : 'none';
      errs.forEach(function (e) { errors.push(name + ': ' + e); });
    });
    document.getElementById('outT').textContent = peso(total);
    return { errors: errors, total: total };
  };
  f.addEventListener('input', calc); f.addEventListener('change', calc); calc();
  f.addEventListener('submit', function (e) {
    if (f.dataset.confirmed) return;
    var as = calc();
    if (as.errors.length) { e.preventDefault(); e.stopImmediatePropagation(); delete f.dataset.confirm; window.PR.toast(as.errors[0], 'error'); return; }
    f.dataset.confirmTitle = 'Confirm the assessment?'; f.dataset.confirmOk = 'Confirm';
    f.dataset.confirm = as.total > 0 ? 'Charge ' + peso(as.total) + '? The customer gets {{ \App\Domain\Damage::REVIEW_HOURS }} hours to accept or dispute it; a PartyRent admin decides disputes.' : 'All items came back fine: no charge.';
  });
})();

// Return inspection (reference inspectFlow): live compensation per damage policy, then review & confirm.
(function () {
  var f = document.getElementById('inspForm'); if (!f) return;
  var peso = function (n) { return '₱' + (Math.round(n * 100) / 100).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
  var esc = function (s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var cards = Array.prototype.slice.call(f.querySelectorAll('[data-insp]'));
  var calc = function () {
    var errors = [], items = [];
    cards.forEach(function (c) {
      var g = function (k) { return Math.max(0, Math.floor(+c.querySelector('[data-k=' + k + ']').value || 0)); };
      var rented = +c.dataset.rented, v = +c.dataset.value || 0, pc = JSON.parse(c.dataset.pcts), name = c.dataset.name;
      var it = { name: name, returned: g('returned'), repairable: g('repairable'), nonRepairable: g('nonRepairable'), desc: c.querySelector('[data-k=description]').value.trim() };
      it.lost = Math.max(0, rented - it.returned);
      var amt = function (k) { return Math.round(v * pc[k] / 100 * it[k] * 100) / 100; };
      it.pc = pc; it.total = amt('repairable') + amt('nonRepairable') + amt('lost');
      var errs = [];
      if (it.returned > rented) errs.push('returned (' + it.returned + ') cannot exceed the quantity rented (' + rented + ').');
      if (it.repairable + it.nonRepairable > it.returned) errs.push('damaged units (' + (it.repairable + it.nonRepairable) + ') cannot exceed the units returned (' + it.returned + ').');
      if (it.repairable + it.nonRepairable + it.lost > 0 && !it.desc) errs.push('describe the damage or loss.');
      c.querySelector('[data-k=lost]').value = it.lost;
      c.querySelector('[data-amt]').textContent = peso(it.total);
      c.querySelector('[data-calc]').textContent = [it.repairable ? it.repairable + ' × ' + peso(v) + ' × ' + pc.repairable + '%' : '', it.nonRepairable ? it.nonRepairable + ' × ' + peso(v) + ' × ' + pc.nonRepairable + '%' : '', it.lost ? it.lost + ' lost × ' + peso(v) + ' × ' + pc.lost + '%' : ''].filter(Boolean).join(' + ') || 'No damage or loss';
      var er = c.querySelector('[data-err]'); er.textContent = errs.join(' '); er.style.display = errs.length ? 'block' : 'none';
      errs.forEach(function (e) { errors.push(name + ': ' + e); });
      items.push(it);
    });
    var total = items.reduce(function (s, i) { return s + i.total; }, 0);
    document.getElementById('compT').textContent = peso(total);
    return { errors: errors, items: items, total: total };
  };
  f.addEventListener('input', calc); calc();
  f.addEventListener('submit', function (e) {
    if (f.dataset.confirmed) return;
    var as = calc();
    if (as.errors.length) {
      e.preventDefault(); e.stopImmediatePropagation(); delete f.dataset.confirm;
      if (window.PR && window.PR.toast) window.PR.toast(as.errors[0], 'error'); else alert(as.errors[0]);
      return;
    }
    var aff = as.items.filter(function (i) { return i.repairable || i.nonRepairable || i.lost; });
    var cell = function (q, pc) { return q ? q + ' × ' + pc + '%' : '—'; };
    f.dataset.confirmTitle = 'Confirm damage assessment?';
    f.dataset.confirmOk = 'Confirm & finalize';
    f.dataset.confirm = 'Confirm the damage assessment';
    f.dataset.confirmHtml = (aff.length ? '<div class="table-wrap"><table class="table"><thead><tr><th>Product</th><th class="num">Repairable</th><th class="num">Non-repairable</th><th class="num">Lost</th><th class="num">Compensation</th></tr></thead><tbody>' + aff.map(function (i) { return '<tr><td>' + esc(i.name) + '</td><td class="num">' + cell(i.repairable, i.pc.repairable) + '</td><td class="num">' + cell(i.nonRepairable, i.pc.nonRepairable) + '</td><td class="num">' + cell(i.lost, i.pc.lost) + '</td><td class="num"><b>' + peso(i.total) + '</b></td></tr>'; }).join('') + '</tbody></table></div>' : '<p>All items were returned in good condition — no compensation.</p>') +
      '<div class="sum-line total"><span>Total compensation</span><span>' + peso(as.total) + '</span></div><ul class="small muted"><li>Repairable units stay unavailable until you mark them repaired.</li><li>Non-repairable and lost units are removed from usable inventory.</li><li>The customer sees the breakdown, explanation and photos. The charge is kept separate from the rental fee and can only be recorded once.</li></ul>';
  });
})();
</script>
@endpush
@endsection
