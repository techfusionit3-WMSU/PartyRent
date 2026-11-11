@extends('layouts.dash', ['title' => 'Earnings & Payouts', 'nav' => 'payouts'])

@section('content')
@php($pctTxt = rtrim(rtrim(number_format($pct, 2), '0'), '.'))
<div class="page-head"><div><h1>Earnings & payouts</h1><p>Customers pay PartyRent, not you directly. PartyRent holds the money while the rental runs and sends it to you once the rental is completed. Like Shopee, the {{ $pctTxt }}% platform commission is taken from items only: delivery fees and damage charges come to you in full. Security deposits are held for the customer and are not part of your payout.</p></div></div>
@unless($s->hasPayoutAccount())
  <div class="alert alert-warn mb-2">{{ icon('alert') }}<div>Add your payout account below so PartyRent can send your earnings.</div></div>
@endunless
<div class="stats mb-3">
  @include('partials.stat', ['label' => 'Held by PartyRent', 'value' => money_short($sum['escrow']), 'sub' => $sum['escrowCount'].' active rental'.($sum['escrowCount'] === 1 ? '' : 's'), 'icon' => 'shield', 'color' => 'teal'])
  @include('partials.stat', ['label' => 'On the way to you', 'value' => money_short($sum['pending']), 'sub' => $sum['pendingCount'].' released payout'.($sum['pendingCount'] === 1 ? '' : 's'), 'icon' => 'cash', 'color' => 'gold'])
  @include('partials.stat', ['label' => 'Paid out', 'value' => money_short($sum['paid']), 'sub' => 'all time', 'icon' => 'check', 'color' => 'purple'])
  @include('partials.stat', ['label' => 'Platform commission', 'value' => money_short($sum['commission']), 'sub' => $pctTxt.'% of items · delivery is free of commission', 'icon' => 'chart', 'color' => 'pink'])
</div>

<div class="card mb-3">
    <h3>Payouts</h3>
    @if($payouts->isEmpty())
      @include('partials.empty', ['emoji' => '💸', 'title' => 'No payouts yet', 'text' => 'Complete a rental and its payout is released here.'])
    @else
      <div class="table-wrap"><table class="table cards"><thead><tr><th>Payout</th><th class="num">Items</th><th class="num">Commission</th><th class="num">You receive</th><th>Status</th></tr></thead><tbody>
        @foreach($payouts as $p)<tr>
          <td data-label="Payout"><b>{{ $p->code }}</b><div class="small muted"><a href="{{ route('supplier.requests.show', $p->rentalRequest) }}">{{ $p->rentalRequest->code }}</a> · {{ fmt_date($p->created_at->toDateString()) }}</div></td>
          @php($pb = $p->breakdown())
          <td data-label="Items" class="num">{{ money($pb['items']) }}</td>
          <td data-label="Commission" class="num">− {{ money($pb['commission']) }}<div class="small muted">{{ rtrim(rtrim(number_format($pb['pct'], 2), '0'), '.') }}%</div></td>
          <td data-label="You receive" class="num"><b>{{ money($p->net) }}</b>@if($pb['delivery'] > 0)<div class="small muted">+ {{ money($pb['delivery']) }} delivery</div>@endif @if($pb['voucher'] > 0)<div class="small muted">+ {{ money($pb['voucher']) }} Free Shipping voucher</div>@endif @if($pb['compensation'] > 0)<div class="small muted">+ {{ money($pb['compensation']) }} damages</div>@endif</td>
          <td data-label="Status">@if($p->isPaid())<span class="badge b-green">Paid</span><div class="small muted">{{ $p->methodLabel() }} · {{ $p->reference }}<br>{{ fmt_date($p->paid_at->toDateString()) }}</div>@else<span class="badge b-gold">Sending</span>@endif</td>
        </tr>@endforeach
      </tbody></table></div>
    @endif
</div>

<div class="grid grid-2 mb-3" style="align-items:start">
    <div class="card">
      <h3>Payout account</h3>
      <p class="small muted">Where PartyRent sends your earnings.</p>
      <form method="POST" action="{{ route('supplier.payouts.account') }}">@csrf @method('PUT')
        <div class="field"><label>Receive by</label><div class="row wrap">@foreach(\App\Models\Payout::METHOD_LABEL as $k => $l)<label class="row" style="gap:6px"><input type="radio" name="payout_method" value="{{ $k }}" data-payout-method @checked(old('payout_method', $s->payout_method ?? 'gcash') === $k)> {{ $l }}</label>@endforeach</div></div>
        <div class="field" data-for-bank @if(old('payout_method', $s->payout_method ?? 'gcash') !== 'bank') hidden @endif><label for="payout_bank">Bank</label>@php($curBank = old('payout_bank', $s->payout_bank))<select class="input" id="payout_bank" name="payout_bank"><option value="">Choose your bank…</option>@foreach(array_keys(\App\Domain\XenditPayouts::BANKS) as $bk)<option @selected($curBank === $bk)>{{ $bk }}</option>@endforeach @if($curBank && ! array_key_exists($curBank, \App\Domain\XenditPayouts::BANKS))<option selected>{{ $curBank }}</option>@endif</select>@error('payout_bank')<div class="error-text">{{ $message }}</div>@enderror</div>
        <div class="field"><label for="payout_account_name">Account name</label><input class="input" id="payout_account_name" name="payout_account_name" required maxlength="120" value="{{ old('payout_account_name', $s->payout_account_name) }}">@error('payout_account_name')<div class="error-text">{{ $message }}</div>@enderror</div>
        <div class="field"><label for="payout_account_no">Account / mobile number</label><input class="input" id="payout_account_no" name="payout_account_no" required maxlength="40" inputmode="numeric" value="{{ old('payout_account_no', $s->payout_account_no) }}">@error('payout_account_no')<div class="error-text">{{ $message }}</div>@enderror</div>
        <button class="btn btn-primary">Save payout account</button>
      </form>
    </div>
    <div class="card">
      <h3>Held for your rentals</h3>
      @if($escrow->isEmpty() && $awaiting->isEmpty())
        <p class="small muted mb-0">Nothing held right now.</p>
      @else
        @foreach($escrow as $x)
          <div class="row between" style="padding:8px 0;border-top:1px solid var(--line)"><div><a class="strong" href="{{ route('supplier.requests.show', $x['r']) }}">{{ $x['r']->code }}</a><div class="small muted">{{ $x['r']->event_name }} · {{ fmt_range($x['r']->start_date, $x['r']->end_date) }}</div></div><div class="num"><b>{{ money($x['held']) }}</b><details class="small"><summary class="muted">≈ {{ money($x['b']['net']) }} to you</summary>@include('partials.payout-breakdown', ['b' => $x['b'], 'netLabel' => 'You receive (so far)'])</details></div></div>
        @endforeach
        @foreach($awaiting as $r)
          <div class="row between" style="padding:8px 0;border-top:1px solid var(--line)"><div><a class="strong" href="{{ route('supplier.requests.show', $r) }}">{{ $r->code }}</a><div class="small muted">{{ $r->status === 'cancelled' ? 'Cancelled — PartyRent reviews refunds before release' : 'Completed — release pending' }}</div></div><div class="num"><b>{{ money(\App\Domain\Payouts::held($r)) }}</b></div></div>
        @endforeach
      @endif
    </div>
</div>
@endsection
