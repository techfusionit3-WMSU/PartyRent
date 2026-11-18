@extends($layout, ['title' => 'Case '.$c->code, 'nav' => 'cases'])

{{--
  Shared shortfall / dispute case page (change list C12): customer, shop and PartyRent support see the same
  timeline (reported → proposed → countered → agreed → resolved), the open proposal with its deadline, the
  message thread and every proposal / change (audit log).
--}}
@php
    $r = $c->rentalRequest;
    $kinds = $c->events->pluck('kind')->all();
    $reached = [
        'reported' => true,
        'proposed' => in_array('proposal', $kinds, true),
        'countered' => (bool) array_intersect(['counter', 'decline'], $kinds),
        'agreed' => (bool) array_intersect(['accept', 'apply'], $kinds),
        'resolved' => $c->status === 'resolved',
    ];
    $st = \App\Models\ShortfallCase::STATUS[$c->status] ?? [$c->status, 'b-gray'];
    $WHO = ['customer' => $c->customer->name, 'supplier' => $c->supplier->name, 'admin' => 'PartyRent support', 'system' => 'PartyRent'];
    $bookingUrl = $role === 'customer' ? route('rentals.show', $r) : ($role === 'supplier' ? route('supplier.requests.show', $r) : null);
    $mine = $open && ($open->data['by'] ?? '') === $role;
    $proposals = $c->events->whereIn('kind', ['proposal', 'counter']);
@endphp
@section('content')
<div data-leave-warn="dirty" data-leave-msg="Your message or proposal on this case has not been sent. Leave anyway?" hidden></div>
<div class="page-head"><div>
  <h1>Case {{ $c->code }} <span class="badge {{ $st[1] }}" style="vertical-align:middle">{{ $st[0] }}</span></h1>
  <p>@if($c->byCustomer()){{ $c->customer->name }} reported a problem: <b>{{ \App\Domain\Shortfalls::PROBLEMS[$c->problem] ?? 'Problem with the items' }}</b> — <b>{{ $c->units() }} × {{ $c->product->name }}</b> on booking @else{{ $c->supplier->name }} cannot provide <b>{{ $c->units() }} × {{ $c->product->name }}</b> for booking @endif @if($bookingUrl)<a href="{{ $bookingUrl }}">{{ $r->code }}</a>@else<b>{{ $r->code }}</b>@endif ({{ $r->event_name }}).</p>
</div></div>

<div class="card mb-2">
  <ol class="case-steps">@foreach(\App\Models\ShortfallCase::STEPS as $k => $l)<li class="{{ $reached[$k] ? 'on' : '' }}"><span class="dot">{{ $reached[$k] ? '✓' : '' }}</span>{{ $l }}</li>@endforeach</ol>
  @if($c->status === 'escalated')<div class="alert alert-danger mt-2 mb-0">{{ icon('alert') }}<div>No agreement before the deadline. <b>PartyRent support</b> is reviewing this case and will settle it.</div></div>@endif
</div>

<div class="grid grid-side">
  <div class="stack">
    <div class="card"><h3>What happened</h3>
      <dl class="kv">
        <dt>Item</dt><dd>{{ $c->product->name }}</dd>
        <dt>Units short</dt><dd>@foreach($c->short as $d => $n)<div>{{ fmt_date($d) }}: <b>{{ $n }}</b> of the booked units</div>@endforeach</dd>
        <dt>Reason</dt><dd style="white-space:pre-line">{{ $c->reason }}</dd>
        @if($c->photo)<dt>Photo</dt><dd><a href="{{ route('cases.photo', $c) }}" target="_blank" rel="noopener"><img src="{{ route('cases.photo', $c) }}" alt="Photo of the problem" style="max-width:220px;border-radius:10px;border:1px solid var(--line)"></a></dd>@endif
        <dt>Booking total</dt><dd>{{ money($r->pricing['total'] ?? 0) }}{{ $r->status === 'cancelled' ? ' · cancelled' : '' }}</dd>
        <dt>Missing units' value</dt><dd>{{ money($shortValue) }} <span class="small muted">at the booked rates</span></dd>
      </dl>
    </div>

    @if($c->status === 'resolved' && $c->resolution)
      <div class="card"><h3>Resolution</h3>
        <dl class="kv">
          <dt>Agreed</dt><dd>{{ ($c->resolution['type'] ?? '') === 'none' ? 'Closed without changes' : \App\Domain\Shortfalls::TYPES[$c->resolution['type']] ?? $c->resolution['type'] }}{{ ($c->resolution['by'] ?? '') === 'admin' ? ' (settled by PartyRent support)' : '' }}</dd>
          @if(! empty($c->resolution['changes']))<dt>Changes</dt><dd>@foreach($c->resolution['changes'] as $x)<div>{{ $x }}</div>@endforeach</dd>@endif
          @if(isset($c->resolution['totalAfter']))<dt>Booking total</dt><dd>{{ money($c->resolution['totalBefore']) }} → <b>{{ money($c->resolution['totalAfter']) }}</b></dd>@endif
          @if(! empty($c->resolution['comp']))<dt>Compensation</dt><dd>{{ money($c->resolution['comp']['amount']) }} paid by {{ $c->resolution['comp']['payer'] === 'supplier' ? 'the shop to the customer' : 'the customer to the shop' }} · {{ $c->resolution['comp']['reason'] }}</dd>@endif
          @if(($c->resolution['refund'] ?? 0) > 0)<dt>Refund</dt><dd>{{ money($c->resolution['refund']) }} {{ $role === 'customer' ? 'is on its way back to you.' : 'due to the customer (in Payments).' }}</dd>@endif
          <dt>Resolved</dt><dd>{{ $c->resolved_at?->format('M j, Y g:i A') }}</dd>
        </dl>
      </div>
    @endif

    @if($open)
      @php($od = $open->data)
      <div class="card case-open"><h3>{{ ($od['by'] ?? '') === 'customer' ? 'Counter-offer from '.$c->customer->name : 'Proposal from '.$c->supplier->name }}</h3>
        <div class="strong">{{ \App\Domain\Shortfalls::TYPES[$od['type']] }}</div>
        @if($od['type'] === 'substitute')<div>Substitute: <b>{{ $od['substitute'] }}</b> for the missing units</div>@endif
        @if(($od['credit'] ?? 0) > 0)<div>Price reduction: <b>{{ money($od['credit']) }}</b> off the booking</div>@endif
        @if($od['type'] === 'cancel')<div>Everything paid for the rental is refunded.</div>@endif
        @if(! empty($od['comp']))<div class="comp-line {{ $od['comp']['payer'] === 'supplier' ? 'to-cust' : 'to-shop' }}">{{ icon('cash', 'width:15px;vertical-align:-3px') }} Compensation: <b>{{ money($od['comp']['amount']) }}</b> paid by {{ $od['comp']['payer'] === 'supplier' ? $c->supplier->name.' to '.$c->customer->name : $c->customer->name.' to '.$c->supplier->name }} · {{ $od['comp']['reason'] }}</div>@endif
        @if($open->body)<p class="mt-1" style="white-space:pre-line">"{{ $open->body }}"</p>@endif
        <div class="small muted mt-1">{{ icon('clock', 'width:13px;vertical-align:-2px') }} Answer before <b>{{ $c->deadline_at?->format('M j, g:i A') }}</b> ({{ $c->deadline_at?->diffForHumans() }}). Without an answer PartyRent support steps in.</div>
        @if(! $mine && in_array($role, ['customer', 'supplier'], true))
          <div class="row wrap mt-2">
            <form method="POST" action="{{ route('cases.respond', $c) }}" data-confirm-title="Accept this proposal?" data-confirm="The booking, the price, any refund and any compensation are updated right away. This can't be undone: the case closes and can't be reopened." data-confirm-ok="Yes, accept">@csrf<input type="hidden" name="answer" value="accept"><button class="btn btn-primary">{{ icon('check') }}Accept</button></form>
            <button class="btn" data-modal="declineM">Decline</button>
            @if($role === 'customer')<button class="btn btn-outline" data-modal="proposeM">Counter-offer</button>@endif
          </div>
        @elseif($mine)
          <div class="small mt-1">Waiting for {{ $role === 'customer' ? $c->supplier->name : $c->customer->name }} to answer. You can send a new proposal to replace it.</div>
        @endif
      </div>
    @endif

    @if($c->status !== 'resolved' && $role === 'supplier')
      <div class="card"><div class="row between"><h3 class="mb-0">{{ $open ? 'Replace your proposal' : 'Propose a solution' }}</h3><button class="btn btn-primary btn-sm" data-modal="proposeM">{{ icon('plus') }}New proposal</button></div>
        <p class="small muted mb-0 mt-1">Offer fewer units at a lower price, a substitute item, a later or split delivery, a refund, or cancelling the booking — with compensation for rush costs either way if needed. The customer can accept, counter or decline.</p></div>
    @endif

    @if($c->status !== 'resolved' && $role === 'admin')
      <div class="card"><h3>Settle as PartyRent support</h3>
        <form method="POST" action="{{ route('cases.settle', $c) }}" data-confirm-title="Settle {{ $c->code }}?" data-confirm="The chosen proposal is applied to the booking now (lines, inventory, price, refund, compensation). This can't be undone." data-confirm-ok="Settle">@csrf
          <div class="field"><label for="setP">Apply</label><select class="input" id="setP" name="proposal"><option value="">Close without changes</option>@foreach($proposals as $p)<option value="{{ $p->id }}">{{ $WHO[$p->role] ?? $p->role }}: {{ \App\Domain\Shortfalls::describe($p->data) }}</option>@endforeach</select></div>
          <div class="field"><label for="setN">Note to both sides</label><textarea class="input" id="setN" name="note" rows="2"></textarea></div>
          <button class="btn btn-primary">Settle case</button></form></div>
    @endif

    <div class="card"><h3>Messages &amp; history</h3>
      <ul class="case-thread">
        @foreach($c->events as $e)
          @php($ed = $e->data ?? [])
          <li class="ev-{{ $e->kind }} by-{{ $e->role }} {{ $e->role === $role ? 'me' : '' }}">
            <div class="small"><b>{{ $e->role === 'system' ? 'PartyRent' : ($e->user?->name ?? $WHO[$e->role] ?? '') }}</b> <span class="muted">· {{ ['customer' => 'Customer', 'supplier' => 'Shop', 'admin' => 'Support', 'system' => 'System'][$e->role] ?? '' }} · {{ $e->created_at?->format('M j, g:i A') }}</span></div>
            @switch($e->kind)
              @case('report')<div><span class="badge b-gold plain">Reported</span> @if(! empty($ed['problem'])){{ \App\Domain\Shortfalls::PROBLEMS[$ed['problem']] ?? 'Problem' }} — {{ array_sum($ed['short'] ?? []) }} × {{ $ed['product'] ?? '' }}@else{{ array_sum($ed['short'] ?? []) }} × {{ $ed['product'] ?? '' }} short @endif</div>@break
              @case('proposal')@case('counter')<div><span class="badge {{ $e->kind === 'counter' ? 'b-purple' : 'b-sky' }} plain">{{ $e->kind === 'counter' ? 'Counter-offer' : 'Proposal' }}</span> {{ \App\Domain\Shortfalls::describe($ed) }} <span class="small muted">· {{ ['open' => 'open', 'accepted' => 'accepted', 'declined' => 'declined', 'replaced' => 'replaced', 'expired' => 'expired'][$ed['status'] ?? ''] ?? '' }}</span></div>@break
              @case('accept')<div><span class="badge b-green plain">Accepted</span></div>@break
              @case('decline')<div><span class="badge b-red plain">Declined</span></div>@break
              @case('escalate')<div><span class="badge b-red plain">Escalated to support</span></div>@break
              @case('apply')<div><span class="badge b-green plain">Booking updated</span></div>@break
              @case('resolve')<div><span class="badge b-green plain">Closed</span></div>@break
            @endswitch
            @if($e->body)<div style="white-space:pre-line">{{ $e->body }}</div>@endif
          </li>
        @endforeach
      </ul>
      <form method="POST" action="{{ route('cases.message', $c) }}" class="row mt-2" style="align-items:flex-end">@csrf
        <div class="field grow mb-0"><label for="msg" class="sr-only">Message</label><textarea class="input" id="msg" name="body" rows="2" required maxlength="2000" placeholder="Write a message to {{ $role === 'customer' ? $c->supplier->name : ($role === 'supplier' ? $c->customer->name : 'both sides') }}…"></textarea></div>
        <button class="btn btn-primary">{{ icon('send') }}Send</button></form>
    </div>
  </div>

  <aside class="card sticky-side"><h3>Booking</h3>
    <dl class="kv">
      <dt>Booking</dt><dd>@if($bookingUrl)<a href="{{ $bookingUrl }}">{{ $r->code }}</a>@else{{ $r->code }}@endif</dd>
      <dt>Event</dt><dd>{{ $r->event_name }} · {{ fmt_date($r->event_date) }}</dd>
      <dt>Customer</dt><dd>{{ $c->customer->name }}</dd>
      <dt>Shop</dt><dd>{{ $c->supplier->name }}</dd>
      <dt>Total</dt><dd>{{ money($r->pricing['total'] ?? 0) }}</dd>
      <dt>Paid</dt><dd>{{ money($r->fin()['paidRental']) }}</dd>
      @if($r->fin()['refunded'] > 0)<dt>Refunded</dt><dd>{{ money($r->fin()['refunded']) }}</dd>@endif
    </dl>
  </aside>
</div>

{{-- propose / counter --}}
@if($c->status !== 'resolved' && in_array($role, ['customer', 'supplier'], true))
<div class="modal-backdrop hidden" id="proposeM"><div class="modal" role="dialog" aria-modal="true" aria-label="Proposal"><div class="modal-head"><h3>{{ $role === 'customer' ? 'Counter-offer' : 'Propose a solution' }}</h3><button class="icon-btn" data-close aria-label="Close">{{ icon('x') }}</button></div>
  <form method="POST" action="{{ route('cases.propose', $c) }}" data-confirm-title="Send this {{ $role === 'customer' ? 'counter-offer' : 'proposal' }}?" data-confirm="{{ $role === 'customer' ? 'The shop' : 'The customer' }} can accept it right away, which updates the booking. {{ $open && $mine ? 'It replaces your open proposal.' : '' }}" data-confirm-ok="Send">@csrf<div class="modal-body">
    <div class="field"><label for="pType">What do you propose?</label><select class="input" id="pType" name="type" required>@foreach(\App\Domain\Shortfalls::TYPES as $k => $l)@continue($role === 'customer' && $k === 'substitute')<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
    @if($role === 'supplier')<div class="field" data-for="substitute" hidden><label for="pSub">Substitute item</label><select class="input" id="pSub" name="substitute_id"><option value="">Choose…</option>@foreach($subs as $s)<option value="{{ $s->id }}">{{ $s->name }} · {{ money($s->basePrice()) }}/day</option>@endforeach</select><div class="hint">Added to the booking for the missing units on the same dates.</div></div>@endif
    <div class="field" data-hide-for="cancel compensation"><label for="pCredit">Price reduction (₱ off the booking)</label><input class="input" type="number" id="pCredit" name="credit" min="0" step="0.01" max="{{ $r->pricing['total'] ?? 0 }}" value="0"><div class="hint">For "fewer units" the missing units come off automatically ({{ money($shortValue) }}); add extra here if you want. For a refund, enter the amount.</div></div>
    <fieldset class="comp-box" data-comp>
      <legend>{{ $role === 'customer' ? 'Compensation from the shop' : 'Compensation to the customer' }} <span class="muted" style="font-weight:500" data-comp-opt>(optional)</span></legend>
      <p class="small muted mt-0">This problem is on the shop's side, so <b>the customer never pays anything extra</b> — only the shop compensates (for example rush fees to rent replacements elsewhere). {{ $role === 'customer' ? 'Ask for it here.' : 'Offer it here.' }}</p>
      <input type="hidden" name="comp_payer" value="supplier">
      <div class="form-grid">
        <div class="field"><label for="pComp">Amount (₱)</label><input class="input" type="number" id="pComp" name="comp_amount" min="0" step="0.01" value="0"></div>
        <div class="field"><label for="pCompR">What it's for</label><input class="input" id="pCompR" name="comp_reason" maxlength="200" placeholder="{{ $role === 'customer' ? 'e.g. Rush fee to rent 20 chairs elsewhere' : 'e.g. Covers renting the missing chairs elsewhere' }}"></div>
      </div>
      <div class="hint">{{ $role === 'customer' ? 'Sent to you by the shop like a refund.' : 'Sent to the customer and taken from your payout.' }}</div>
    </fieldset>
    <div class="field"><label for="pNote">Message</label><textarea class="input" id="pNote" name="note" rows="3" maxlength="2000" placeholder="{{ $role === 'customer' ? 'e.g. We can accept 80 chairs if the delivery fee is waived.' : 'e.g. We can deliver 80 chairs on time and the other 20 by 10 AM.' }}"></textarea></div>
    <div class="field"><label for="pDl">Answer needed within</label><select class="input" id="pDl" name="deadline_hours">@foreach([12 => '12 hours', 24 => '24 hours', 48 => '2 days', 72 => '3 days'] as $h => $l)<option value="{{ $h }}" @selected($h === \App\Domain\Shortfalls::DEFAULT_DEADLINE_HOURS)>{{ $l }}</option>@endforeach</select></div>
  </div><div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Send</button></div></form>
</div></div>
<div class="modal-backdrop hidden" id="declineM"><div class="modal sm" role="dialog" aria-modal="true" aria-label="Decline"><div class="modal-head"><h3>Decline the proposal</h3><button class="icon-btn" data-close aria-label="Close">{{ icon('x') }}</button></div>
  <form method="POST" action="{{ route('cases.respond', $c) }}" data-confirm-title="Decline this proposal?" data-confirm="The proposal can't be accepted later. {{ $role === 'customer' ? 'The shop' : 'The customer' }} has {{ \App\Domain\Shortfalls::DEFAULT_DEADLINE_HOURS }} hours to send a new one before PartyRent support steps in." data-confirm-ok="Decline" data-danger>@csrf<input type="hidden" name="answer" value="decline"><div class="modal-body">
    <div class="field"><label for="dNote">Why? <span class="muted">(optional)</span></label><textarea class="input" id="dNote" name="note" rows="3" maxlength="2000"></textarea></div>
  </div><div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-danger">Decline</button></div></form>
</div></div>
@push('scripts')
<script>
(function () {
  var t = document.getElementById('pType'); if (!t) return;
  function sync() {
    document.querySelectorAll('[data-for]').forEach(function (el) { el.hidden = el.getAttribute('data-for') !== t.value; });
    document.querySelectorAll('[data-hide-for]').forEach(function (el) { el.hidden = el.getAttribute('data-hide-for').split(' ').indexOf(t.value) >= 0; });
    var only = t.value === 'compensation', opt = document.querySelector('[data-comp-opt]'), amt = document.getElementById('pComp');
    if (opt) opt.textContent = only ? '(required)' : '(optional)';
    if (amt) amt.required = only;
  }
  t.addEventListener('change', sync); sync();
})();
</script>
@endpush
@endif
@endsection
