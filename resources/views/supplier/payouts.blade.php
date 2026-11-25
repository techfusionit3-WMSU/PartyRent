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

<details class="card mb-3" id="how" @if($batches->isEmpty() && $upcoming->isEmpty()) open @endif>
  <summary class="strong" style="cursor:pointer">How payouts work</summary>
  <p class="small mt-2">A <b>payout</b> is PartyRent sending you the money you earned. It is not the same as the day the customer pays: customers pay PartyRent first, PartyRent holds it while the rental runs, and pays you after the rental is completed, on your payout date.</p>
  <div class="grid grid-3 mt-2" style="gap:12px">
    <div class="card card-flat soft" style="padding:14px"><div class="strong">1 · Customer payment date</div><p class="small muted mb-0">The day the customer pays (online, bank transfer or debit). PartyRent confirms it and holds it. Cash is handed to you and you record it.</p></div>
    <div class="card card-flat soft" style="padding:14px"><div class="strong">2 · Rental completed</div><p class="small muted mb-0">Your earnings join the next payout: items minus the {{ $pctTxt }}% commission, plus delivery fees and damage charges in full.</p></div>
    <div class="card card-flat soft" style="padding:14px"><div class="strong">3 · Payout date</div><p class="small muted mb-0">{{ \App\Domain\Payouts::SCHEDULE_HINT[$schedule] }} Cash you already collected is yours, so it is taken off the amount sent.</p></div>
  </div>
  <h4 class="mt-2 mb-1">Expected timing</h4>
  <ul class="small mt-0">
    <li>On your payout date the payout is <b>prepared</b>. PartyRent then sends it, usually within <b>2 business days</b>.</li>
    <li>After it is sent, when the money shows up depends on your bank or e-wallet: e-wallets are usually quick; bank transfers can take <b>1–3 business days</b>, longer over weekends and holidays.</li>
    <li>A payment the customer made close to the payout date may still be <b>settling</b> (being confirmed by the bank or payment provider). It is paid in the next payout once confirmed.</li>
    <li>These are estimates, not guarantees: bank cut-offs, provider maintenance or a wrong account number can delay a payout.</li>
  </ul>
  <h4 class="mt-2 mb-1">Payout status</h4>
  <div class="row wrap small" style="gap:8px">
    <span><span class="badge b-gold">Pending</span> being prepared or waiting to be sent</span>
    <span><span class="badge b-sky">Processing</span> sent, waiting for the bank or e-wallet</span>
    <span><span class="badge b-green">Completed</span> received by your account</span>
    <span><span class="badge b-red">Failed</span> the transfer was refused (often a wrong account); it is sent again after the account is checked</span>
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
        <td data-label="Status">@php($st = $b->payoutStatus())<span class="badge {{ $b->isOwed() ? 'b-red' : $st[1] }}">{{ $b->isOwed() && $b->carried_to_id ? 'Taken from '.optional($batches->firstWhere('id', $b->carried_to_id))->code : ($b->isOwed() ? $b->statusLabel() : $st[0]) }}</span>
          @if($b->status === 'pending' && $b->failure)<div class="small" style="color:var(--red)">Last attempt failed: {{ $b->failure }}. PartyRent will check your account and send it again.</div>@endif
          @if($b->isPaid())<div class="small muted">{{ $b->methodLabel() }} · {{ $b->reference }}<br>{{ fmt_date($b->paid_at->toDateString()) }}</div>
          @elseif($b->status === 'pending' && ! $b->failure)<div class="small muted">{{ $acct ? 'Usually sent within 2 business days' : 'Waiting for your payout account' }}</div>
          @elseif($b->status === 'sending')<div class="small muted">Sent{{ $b->reference ? ' · '.$b->reference : '' }}. Arrival depends on your bank or e-wallet (usually 1–3 business days).</div>@endif</td>
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
    <h3>Payout accounts</h3>
    <p class="small muted">Where PartyRent sends your money. You can keep several; payouts go to the <b>default</b> one. Account numbers are stored encrypted and shown masked.</p>
    @forelse($accounts as $a)
      <div class="row between wrap" style="gap:8px;padding:10px 0;border-top:1px solid var(--line)">
        <div><b>{{ $a->provider_name }}</b> <span class="small muted">{{ \App\Models\PayoutAccount::CATEGORY_LABEL[$a->category] }}{{ $a->category === 'bank' && ! empty($a->details['accountType']) ? ' · '.ucfirst($a->details['accountType']) : '' }}</span>
          @if($a->is_default)<span class="badge b-green">Default</span>@endif
          <div class="small">{{ $a->account_name }} · <span class="mono">{{ $a->masked() }}</span>{{ ! empty($a->details['branch']) ? ' · '.$a->details['branch'] : '' }}</div></div>
        @if($canManage)
          <div class="btn-group">
            @unless($a->is_default)<form method="POST" action="{{ route('supplier.payouts.accounts.default', $a) }}">@csrf @method('PUT')<button class="btn btn-xs">Make default</button></form>@endunless
            <form method="POST" action="{{ route('supplier.payouts.accounts.destroy', $a) }}" data-confirm-title="Remove this payout account?" data-confirm="{{ $a->label() }} will be removed.">@csrf @method('DELETE')<button class="btn btn-xs btn-ghost" style="color:var(--red)">Remove</button></form>
          </div>
        @endif
      </div>
    @empty
      <p class="small mb-2">No payout account yet.</p>
    @endforelse
    @if($canManage)
      <details class="mt-2" data-payout-add @if($accounts->isEmpty() || $errors->hasAny(['category', 'provider', 'provider_name', 'account_name', 'account_number', 'account_type'])) open @endif>
        <summary class="btn btn-sm">{{ icon('plus') }} Add a payout account</summary>
        <form method="POST" action="{{ route('supplier.payouts.accounts.store') }}" class="mt-2" data-no-enter autocomplete="off">@csrf
          @php($cat = old('category', 'ewallet'))
          <div class="field"><label>Type</label><div class="row wrap" style="gap:10px">
            @foreach(\App\Models\PayoutAccount::CATEGORY_LABEL as $k => $l)<label class="row" style="gap:6px"><input type="radio" name="category" value="{{ $k }}" data-pa-cat @checked($cat === $k)> {{ $l }}</label>@endforeach
          </div>@error('category')<div class="error-text">{{ $message }}</div>@enderror</div>
          <div data-pa-for="card" @if($cat !== 'card') hidden @endif>
            <div class="alert alert-info small">{{ icon('info') }}<div>@if($cardsEnabled)Debit cards are added on the card provider's secure form: PartyRent only keeps the provider's token and the last 4 digits, never the card number or CVV.@else<b>Not available yet.</b> Debit card payouts need a card-payout provider (card tokenization), which PartyRent has not connected. PartyRent never asks for your card number or CVV here. Use an e-wallet or bank account for now.@endif</div></div>
          </div>
          <div data-pa-for="ewallet bank" @if($cat === 'card') hidden @endif>
            @php($prov = old('provider', $cat === 'bank' ? 'bpi' : 'gcash'))
            <div class="field" data-pa-for="ewallet" @if($cat !== 'ewallet') hidden @endif><label for="pa-wallet">E-wallet</label>
              <select class="input" id="pa-wallet" data-pa-prov="ewallet" @if($cat === 'ewallet') name="provider" @endif>@foreach(\App\Models\PayoutAccount::PROVIDERS['ewallet'] as $k => $l)<option value="{{ $k }}" @selected($prov === $k)>{{ $l }}</option>@endforeach</select></div>
            <div class="field" data-pa-for="bank" @if($cat !== 'bank') hidden @endif><label for="pa-bank">Bank</label>
              <select class="input" id="pa-bank" data-pa-prov="bank" @if($cat === 'bank') name="provider" @endif>@foreach(\App\Models\PayoutAccount::PROVIDERS['bank'] as $k => $l)<option value="{{ $k }}" @selected($prov === $k)>{{ $l }}</option>@endforeach</select></div>
            @error('provider')<div class="error-text">{{ $message }}</div>@enderror
            <div class="field" data-pa-other @if(! in_array($prov, ['other_ewallet', 'other_bank'], true)) hidden @endif><label for="pa-pname" data-pa-pname-label>{{ $cat === 'bank' ? 'Bank name' : 'E-wallet name' }}</label>
              <input class="input" id="pa-pname" name="provider_name" maxlength="60" value="{{ old('provider_name') }}" list="pa-banks" placeholder="e.g. Metrobank, ShopeePay">
              <datalist id="pa-banks">@foreach(array_keys(\App\Domain\XenditPayouts::BANKS) as $bk)@unless(in_array($bk, ['BPI', 'BDO'], true))<option value="{{ $bk }}">@endunless @endforeach</datalist>
              @error('provider_name')<div class="error-text">{{ $message }}</div>@enderror</div>
            <div class="field"><label for="pa-name">Account name</label><input class="input" id="pa-name" name="account_name" maxlength="120" value="{{ old('account_name') }}" placeholder="As registered with the bank or e-wallet">@error('account_name')<div class="error-text">{{ $message }}</div>@enderror</div>
            <div class="field"><label for="pa-no" data-pa-no-label>{{ $cat === 'bank' ? 'Account number' : 'Mobile number' }}</label><input class="input" id="pa-no" name="account_number" maxlength="24" inputmode="numeric" autocomplete="off" value="{{ old('account_number') }}">
              <div class="small muted" data-pa-hint></div>@error('account_number')<div class="error-text">{{ $message }}</div>@enderror</div>
            <div class="form-grid" data-pa-for="bank" @if($cat !== 'bank') hidden @endif>
              <div class="field"><label for="pa-type">Account type</label><select class="input" id="pa-type" name="account_type"><option value="savings" @selected(old('account_type') !== 'checking')>Savings</option><option value="checking" @selected(old('account_type') === 'checking')>Checking</option></select>@error('account_type')<div class="error-text">{{ $message }}</div>@enderror</div>
              <div class="field"><label for="pa-branch">Branch <span class="muted small">(optional)</span></label><input class="input" id="pa-branch" name="branch" maxlength="80" value="{{ old('branch') }}"></div>
            </div>
            @if($accounts->isNotEmpty())<label class="check small mb-2"><input type="checkbox" name="make_default" value="1" @checked(old('make_default'))> Send my payouts here (make it the default)</label>@endif
            <button class="btn btn-primary">Save payout account</button>
          </div>
        </form>
      </details>
      <script>
        (() => {
          const f = document.querySelector('[data-payout-add] form'); if (!f) return;
          const hints = @json(collect(\App\Models\PayoutAccount::NUMBER_RULES)->map(fn ($r) => 'Enter '.$r[1].'.'));
          const sync = () => {
            const cat = (f.querySelector('[data-pa-cat]:checked') || {}).value || 'ewallet';
            f.querySelectorAll('[data-pa-for]').forEach(el => { el.hidden = !el.dataset.paFor.split(' ').includes(cat); });
            f.querySelectorAll('[data-pa-prov]').forEach(sel => { if (sel.dataset.paProv === cat) sel.setAttribute('name', 'provider'); else sel.removeAttribute('name'); });
            const sel = f.querySelector('[data-pa-prov="' + cat + '"]'), prov = sel ? sel.value : '';
            f.querySelector('[data-pa-other]').hidden = !(prov === 'other_ewallet' || prov === 'other_bank') || cat === 'card';
            f.querySelector('[data-pa-pname-label]').textContent = cat === 'bank' ? 'Bank name' : 'E-wallet name';
            f.querySelector('[data-pa-no-label]').textContent = cat === 'bank' ? 'Account number' : (prov === 'other_ewallet' ? 'Account or mobile number' : 'Mobile number');
            f.querySelector('[data-pa-hint]').textContent = hints[prov] || '';
          };
          f.addEventListener('change', sync); sync();
        })();
      </script>
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
