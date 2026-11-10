@extends('layouts.dash', ['title' => 'Payment Accounts', 'nav' => 'pay-accounts'])

@section('content')
<div class="page-head"><div><h1>Payment accounts</h1><p>PartyRent's own GCash, Maya and bank accounts that customers transfer to. Turn one off when it's full, or set a monthly limit so it hides itself before going over.</p></div></div>

@php($types = \App\Models\PayAccount::TYPE_LABEL)
<div class="stack" style="max-width:860px">
  @forelse($accounts as $acc)
    @php($got = $acc->receivedThisMonth())
    @php($room = $acc->room())
    @php($pct = $acc->monthly_cap ? min(100, round($got / $acc->monthly_cap * 100)) : 0)
    <div class="card">
      <div class="row wrap between top">
        <div class="row" style="gap:12px">
          @if($acc->qr_path)<img src="{{ route('payments.qr', $acc) }}" alt="{{ $acc->label }} QR" style="width:56px;height:56px;object-fit:contain;background:#fff;border-radius:6px;border:1px solid var(--line-2)">@endif
          <div><div class="row gap-sm"><b>{{ $acc->label }}</b><span class="badge b-gray plain">{{ $acc->typeLabel() }}</span>
              @if(! $acc->active)<span class="badge b-gray">Off</span>@elseif($room !== null && $room < 0.01)<span class="badge b-red">Limit reached</span>@else<span class="badge b-green">Showing to customers</span>@endif</div>
            <div class="small mt-1">{{ $acc->account_no ?: 'QR only' }}@if($acc->account_name) · {{ $acc->account_name }}@endif</div></div>
        </div>
        <div class="right" style="min-width:200px">
          <div class="small muted">Received this month</div>
          <div><b>{{ money($got) }}</b>@if($acc->monthly_cap) <span class="small muted">of {{ money($acc->monthly_cap) }}</span>@endif</div>
          @if($acc->monthly_cap)<div class="cap-bar {{ $pct >= 100 ? 'full' : ($pct >= 80 ? 'warn' : '') }}"><span style="width:{{ $pct }}%"></span></div><div class="small muted">{{ money($room) }} left</div>@else<div class="small muted">No monthly limit</div>@endif
        </div>
      </div>
      <div class="row wrap mt-2" style="gap:8px;justify-content:flex-end">
        <form method="POST" action="{{ route('super.pay-accounts.toggle', $acc) }}">@csrf<button class="btn btn-sm {{ $acc->active ? '' : 'btn-primary' }}">{{ $acc->active ? 'Turn off' : 'Turn on' }}</button></form>
        <form method="POST" action="{{ route('super.pay-accounts.delete', $acc) }}" data-confirm="Remove {{ $acc->label }}? Accounts with payments can only be turned off." data-confirm-title="Remove account?" data-confirm-ok="Remove" data-danger>@csrf<button class="btn btn-sm btn-ghost">Remove</button></form>
      </div>
      <details class="mt-2"><summary class="small"><b>Edit</b></summary>
        @include('super.partials.pay-account-form', ['acc' => $acc, 'action' => route('super.pay-accounts.update', $acc), 'submit' => 'Save'])
      </details>
    </div>
  @empty
    <div class="card">@include('partials.empty', ['emoji' => '🏦', 'title' => 'No payment accounts yet', 'text' => 'Add PartyRent\'s GCash or bank account below so customers know where to send payments.'])</div>
  @endforelse

  <div class="card"><h3>Add an account</h3>
    @include('super.partials.pay-account-form', ['acc' => null, 'action' => route('super.pay-accounts.store'), 'submit' => 'Add account'])
  </div>

  <div class="card"><h3>About monthly limits</h3>
    <p class="small muted mb-0">GCash and Maya limit how much an account can receive each month (see Profile → Account Limits in the app). Set that number as the account's monthly limit: PartyRent adds up verified and pending payments into the account this month and stops offering it for any payment that would go over. Bank accounts and QR Ph merchant QR codes usually need no limit. Customers can still scan a bank's QR Ph code from GCash or Maya.</p>
  </div>
</div>
@endsection
