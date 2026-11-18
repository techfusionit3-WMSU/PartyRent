@extends('layouts.dash', ['title' => 'Earnings & Payouts', 'nav' => 'payouts'])

{{-- Shop earnings and scheduled payouts (App\Domain\Payouts). Shown to the owner (supplier mode) and to the shop's
     cashier (cashier workspace, read-only unless they may manage the shop). --}}
@section('content')
@php
  $pctTxt = rtrim(rtrim(number_format($pct, 2), '0'), '.');
  $schedTxt = \App\Domain\Payouts::SCHEDULES[$schedule];
  $acct = $s->hasPayoutAccount() ? $s->payoutAccountLabel() : null;
  $nextAmt = $sum['next'];
@endphp
<div class="page-head"><div><h1>Earnings & payouts</h1><p>Customers pay PartyRent. When a rental is completed, what you earned goes into your next payout. PartyRent sends it <b>{{ strtolower($schedTxt) }}</b>{{ $acct ? ' to your '.$acct : '' }}.</p></div></div>

@unless($acct)
  <div class="alert alert-warn mb-2">{{ icon('alert') }}<div><b>Add your payout account</b> so PartyRent can send your money. @if($canManage)<a href="#account">Add it now</a>@else Ask your Organization Admin to add it.@endif</div></div>
@endunless
@if($owed['amount'] > 0)
  <div class="alert {{ $cashPaused ? 'alert-danger' : 'alert-info' }} mb-2">{{ icon($cashPaused ? 'alert' : 'info') }}<div>
    <b>You owe PartyRent {{ money($owed['amount']) }}.</b> You collected more cash than PartyRent owed you, so this is PartyRent's share (mostly commission). It is taken from your next payout automatically.
    @if($cashPaused)<br><b>Cash payments are paused for your shop</b> because this has been open for over {{ \App\Domain\Payouts::OWE_DAYS }} days. Customers can still pay online or by bank transfer. Cash opens again once the balance is cleared, or contact PartyRent support to pay it directly.@endif
  </div></div>
@endif

{{-- Next payout --}}
<div class="card mb-3" style="border:2px solid var(--brand-soft, #f3d1ea)">
  <div class="row wrap between" style="gap:16px;align-items:center">
    <div>
      <div class="small muted strong" style="text-transform:uppercase;letter-spacing:.04em">Next payout</div>
      <div style="font-size:2rem;font-weight:800;line-height:1.2">{{ $nextAmt > 0 ? money($nextAmt) : money(0) }}</div>
      <div class="muted">on <b>{{ fmt_date($nextDate) }}</b> · for rentals completed {{ fmt_range($period[0], $period[1]) }}</div>
      @if($nextAmt < 0)<div class="small mt-1" style="color:var(--red)">Right now the cash you collected is more than you earned, so nothing would be sent. The {{ money(-$nextAmt) }} difference is taken from a later payout.</div>@endif
    </div>
    <div class="right">
      <span class="badge b-purple">{{ icon('calendar', 'width:13px') }} {{ $schedTxt }}</span>
      @if($canManage)<div class="small mt-1"><a href="#schedule">Change schedule</a></div>@endif
    </div>
  </div>
</div>

<details class="card mb-3" @if($batches->isEmpty() && $upcoming->isEmpty()) open @endif>
  <summary class="strong" style="cursor:pointer">How payouts work</summary>
  <div class="grid grid-3 mt-2" style="gap:12px">
    <div class="card card-flat soft" style="padding:14px"><div class="strong">1 · Customer pays</div><p class="small muted mb-0">Online, bank transfer or debit payments go to PartyRent and are confirmed by PartyRent. Cash is handed to you and you record it.</p></div>
    <div class="card card-flat soft" style="padding:14px"><div class="strong">2 · Rental completed</div><p class="small muted mb-0">Your earnings are added to the next payout: items minus the {{ $pctTxt }}% commission, plus delivery fees and damage charges in full.</p></div>
    <div class="card card-flat soft" style="padding:14px"><div class="strong">3 · Payout sent</div><p class="small muted mb-0">{{ \App\Domain\Payouts::SCHEDULE_HINT[$schedule] }} Cash you already collected is yours, so it is taken off the amount sent.</p></div>
  </div>
  <p class="small muted mt-2 mb-0">Security deposits are not income: PartyRent (or you, if the customer paid it in cash) returns them to the customer.</p>
</details>

<div class="stats mb-3">
  @include('partials.stat', ['label' => 'Held by PartyRent', 'value' => money_short($sum['escrow']), 'sub' => $sum['escrowCount'].' active rental'.($sum['escrowCount'] === 1 ? '' : 's').' · not yet earned', 'icon' => 'shield', 'color' => 'teal'])
  @include('partials.stat', ['label' => 'Next payout', 'value' => money_short(max(0, $nextAmt)), 'sub' => fmt_date($nextDate), 'icon' => 'calendar', 'color' => 'pink'])
  @include('partials.stat', ['label' => 'On the way', 'value' => money_short($sum['pending']), 'sub' => $sum['pendingCount'].' payout'.($sum['pendingCount'] === 1 ? '' : 's').' being sent', 'icon' => 'cash', 'color' => 'gold'])
  @include('partials.stat', ['label' => 'Paid to you', 'value' => money_short($sum['paid']), 'sub' => 'all time', 'icon' => 'check', 'color' => 'purple'])
</div>

{{-- Earnings waiting for the next payout --}}
<div class="card mb-3">
  <h3>In your next payout</h3>
  @if($upcoming->isEmpty())
    <p class="small muted mb-0">Nothing yet. When you complete a rental, its earnings show up here.</p>
  @else
    <div class="table-wrap"><table class="table cards"><thead><tr><th>Rental</th><th class="num">You earned</th><th class="num">Cash you collected</th><th class="num">Adds to payout</th></tr></thead><tbody>
      @foreach($upcoming as $e)@php($eb = $e->breakdown())<tr>
        <td data-label="Rental"><a class="strong" href="{{ $rentalLink($e->rentalRequest) }}">{{ $e->rentalRequest->code }}</a><div class="small muted">{{ $e->rentalRequest->event_name }} · completed {{ fmt_date($e->created_at->toDateString()) }}</div></td>
        <td data-label="You earned" class="num">{{ money($e->earned) }}<details class="small"><summary class="muted">details</summary>@include('partials.payout-breakdown', ['b' => $eb, 'netLabel' => 'Adds to payout'])</details></td>
        <td data-label="Cash you collected" class="num">{{ $e->cash_kept != 0 ? '− '.money($e->cash_kept) : '—' }}</td>
        <td data-label="Adds to payout" class="num"><b>{{ money($e->net) }}</b></td>
      </tr>@endforeach
      @foreach($owedRows as $o)<tr>
        <td data-label="Rental"><b>Balance from {{ $o->code }}</b><div class="small muted">{{ $o->periodLabel() }} · cash collected was more than earnings</div></td>
        <td></td><td></td>
        <td data-label="Adds to payout" class="num" style="color:var(--red)"><b>− {{ money(-$o->net) }}</b></td>
      </tr>@endforeach
      <tr><td colspan="3" class="strong right">Next payout so far</td><td class="num"><b>{{ money($nextAmt) }}</b></td></tr>
    </tbody></table></div>
  @endif
</div>

{{-- Payout history --}}
<div class="card mb-3">
  <h3>Payout history</h3>
  @if($batches->isEmpty())
    @include('partials.empty', ['emoji' => '💸', 'title' => 'No payouts yet', 'text' => 'Your first payout appears here after your first payout date.'])
  @else
    <div class="table-wrap"><table class="table cards"><thead><tr><th>Payout</th><th>Period</th><th class="num">Amount</th><th>Status</th></tr></thead><tbody>
      @foreach($batches as $b)<tr>
        <td data-label="Payout"><b>{{ $b->code }}</b><div class="small muted">{{ $b->lines->count() }} rental{{ $b->lines->count() === 1 ? '' : 's' }}</div></td>
        <td data-label="Period">{{ $b->periodLabel() }}</td>
        <td data-label="Amount" class="num"><b>{{ $b->net >= 0 ? money($b->net) : '− '.money(-$b->net) }}</b>
          <details class="small"><summary class="muted">how it adds up</summary>
            <div class="payout-bd" style="min-width:240px;text-align:left;margin-top:6px">
              @foreach($b->lines as $l)<div class="row between"><span>{{ $l->rentalRequest->code }}</span><span class="num">{{ money($l->earned) }}</span></div>@endforeach
              <div class="row between strong" style="border-top:1px solid var(--line);padding-top:4px"><span>You earned</span><span class="num">{{ money($b->earned) }}</span></div>
              @if($b->cash_kept != 0)<div class="row between"><span>Cash you already collected</span><span class="num">− {{ money($b->cash_kept) }}</span></div>@endif
              @if($b->carried_in != 0)<div class="row between"><span>Balance from before</span><span class="num">{{ $b->carried_in < 0 ? '− '.money(-$b->carried_in) : '+ '.money($b->carried_in) }}</span></div>@endif
              <div class="row between strong" style="border-top:1px solid var(--line);padding-top:4px"><span>{{ $b->net > 0 ? 'Sent to you' : 'Nothing sent' }}</span><span class="num">{{ $b->net >= 0 ? money($b->net) : '− '.money(-$b->net) }}</span></div>
              <div class="muted mt-1">Commission included above: {{ money($b->commission) }}</div>
            </div>
          </details></td>
        <td data-label="Status"><span class="badge {{ $b->isOwed() ? 'b-red' : (\App\Models\PayoutBatch::STATUS_BADGE[$b->status] ?? 'b-gray') }}">{{ $b->isOwed() && $b->carried_to_id ? 'Taken from '.optional($batches->firstWhere('id', $b->carried_to_id))->code : $b->statusLabel() }}</span>
          @if($b->isPaid())<div class="small muted">{{ $b->methodLabel() }} · {{ $b->reference }}<br>{{ fmt_date($b->paid_at->toDateString()) }}</div>
          @elseif($b->status === 'pending')<div class="small muted">{{ $acct ? 'Sending within 2 business days' : 'Waiting for your payout account' }}</div>@endif</td>
      </tr>@endforeach
    </tbody></table></div>
  @endif
</div>

<div class="grid grid-2 mb-3" style="align-items:start">
  <div class="card" id="schedule">
    <h3>Payout schedule</h3>
    @if($canManage)
      <form method="POST" action="{{ route('supplier.payouts.schedule') }}">@csrf @method('PUT')
        @foreach(\App\Domain\Payouts::SCHEDULES as $k => $l)
          <label class="card card-flat {{ $schedule === $k ? 'soft' : '' }}" style="display:flex;gap:10px;padding:12px;margin-bottom:8px;cursor:pointer"><input type="radio" name="payout_schedule" value="{{ $k }}" @checked($schedule === $k)><span><b>{{ $l }}</b><span class="small muted" style="display:block">{{ \App\Domain\Payouts::SCHEDULE_HINT[$k] }}</span></span></label>
        @endforeach
        <button class="btn btn-primary">Save schedule</button>
      </form>
    @else
      <p class="mb-0"><b>{{ $schedTxt }}</b><br><span class="small muted">{{ \App\Domain\Payouts::SCHEDULE_HINT[$schedule] }}</span></p>
    @endif
  </div>
  <div class="card" id="account">
    <h3>Payout account</h3>
    <p class="small muted">Where PartyRent sends your money.</p>
    @if($canManage)
      <form method="POST" action="{{ route('supplier.payouts.account') }}">@csrf @method('PUT')
        <div class="field"><label>Receive by</label><div class="row wrap">@foreach(\App\Models\Payout::METHOD_LABEL as $k => $l)<label class="row" style="gap:6px"><input type="radio" name="payout_method" value="{{ $k }}" data-payout-method @checked(old('payout_method', $s->payout_method ?? 'gcash') === $k)> {{ $l }}</label>@endforeach</div></div>
        <div class="field" data-for-bank @if(old('payout_method', $s->payout_method ?? 'gcash') !== 'bank') hidden @endif><label for="payout_bank">Bank</label>@php($curBank = old('payout_bank', $s->payout_bank))<select class="input" id="payout_bank" name="payout_bank"><option value="">Choose your bank…</option>@foreach(array_keys(\App\Domain\XenditPayouts::BANKS) as $bk)<option @selected($curBank === $bk)>{{ $bk }}</option>@endforeach @if($curBank && ! array_key_exists($curBank, \App\Domain\XenditPayouts::BANKS))<option selected>{{ $curBank }}</option>@endif</select>@error('payout_bank')<div class="error-text">{{ $message }}</div>@enderror</div>
        <div class="field"><label for="payout_account_name">Account name</label><input class="input" id="payout_account_name" name="payout_account_name" required maxlength="120" value="{{ old('payout_account_name', $s->payout_account_name) }}">@error('payout_account_name')<div class="error-text">{{ $message }}</div>@enderror</div>
        <div class="field"><label for="payout_account_no">Account / mobile number</label><input class="input" id="payout_account_no" name="payout_account_no" required maxlength="40" inputmode="numeric" value="{{ old('payout_account_no', $s->payout_account_no) }}">@error('payout_account_no')<div class="error-text">{{ $message }}</div>@enderror</div>
        <button class="btn btn-primary">Save payout account</button>
      </form>
    @else
      <p class="mb-0">{{ $acct ?: 'Not added yet.' }}</p>
    @endif
  </div>
</div>

<div class="card">
  <h3>Held for your active rentals</h3>
  <p class="small muted">Money customers paid PartyRent for rentals that are not completed yet. It becomes yours when you complete the rental.</p>
  @if($escrow->isEmpty() && $awaiting->isEmpty())
    <p class="small muted mb-0">Nothing held right now.</p>
  @else
    @foreach($escrow as $x)
      <div class="row between" style="padding:8px 0;border-top:1px solid var(--line)"><div><a class="strong" href="{{ $rentalLink($x['r']) }}">{{ $x['r']->code }}</a><div class="small muted">{{ $x['r']->event_name }} · {{ fmt_range($x['r']->start_date, $x['r']->end_date) }}</div></div><div class="num"><b>{{ money($x['held']) }}</b><details class="small"><summary class="muted">≈ {{ money($x['b']['earned']) }} earned so far</summary>@include('partials.payout-breakdown', ['b' => $x['b'], 'netLabel' => 'Adds to payout (so far)'])</details></div></div>
    @endforeach
    @foreach($awaiting as $r)
      <div class="row between" style="padding:8px 0;border-top:1px solid var(--line)"><div><a class="strong" href="{{ $rentalLink($r) }}">{{ $r->code }}</a><div class="small muted">{{ $r->status === 'cancelled' ? 'Cancelled — PartyRent settles refunds first' : 'Completed — a payment is still being confirmed' }}</div></div><div class="num"><b>{{ money(\App\Domain\Payouts::held($r)) }}</b></div></div>
    @endforeach
  @endif
</div>
@endsection
