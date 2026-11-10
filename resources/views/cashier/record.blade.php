@extends('layouts.dash', ['title' => 'Record Payment', 'nav' => 'record'])

@section('content')
<div class="page-head"><div><h1>Record payment or refund</h1><p>For authorized over-the-counter payments and refunds. Recorded entries are verified immediately and a receipt is issued.</p></div></div>
<form method="GET" class="card row wrap mb-2" style="gap:8px">
  <label for="rq" class="strong">Rental request</label>
  <input class="input" id="rq" name="request" list="openReqs" value="{{ $code }}" placeholder="e.g. PR-0001" style="max-width:220px" required>
  <datalist id="openReqs">@foreach($open as $o)<option value="{{ $o->code }}">{{ $o->event_name }} · {{ $o->customer->name }}</option>@endforeach</datalist>
  <button class="btn">{{ icon('search') }}Look up</button>
</form>
@if($code && ! $r)<div class="alert alert-warn">{{ icon('alert') }}<div>No rental request found for <b>{{ $code }}</b>.</div></div>@endif
@if($r)
  @php($f = $r->fin())
  <div class="grid grid-side">
    <form method="POST" action="{{ route('cashier.record.store') }}" class="card">
      @csrf <input type="hidden" name="request" value="{{ $r->code }}">
      <h3>{{ $r->code }} · {{ $r->event_name }}</h3><p class="small muted">{{ $r->customer->name }} · @include('partials.badges', ['status' => $r->status])</p>
      <div class="form-grid">
        <div class="field"><label>Type</label><select class="input" name="type">@foreach(\App\Models\Payment::TYPE_LABEL as $k => $l)@continue($k === 'deposit_refund')<option value="{{ $k }}" @selected(old('type') === $k)>{{ $l }}</option>@endforeach</select><div class="hint">Deposit refunds are created when the rental's deposit is settled after the return inspection.</div></div>
        <div class="field"><label>Method</label><select class="input" name="method"><option value="cash">Cash (over the counter)</option><option value="bank">Online bank transfer</option><option value="debit">Debit card</option></select></div>
        <div class="field"><label>Amount (₱)</label><input class="input" type="number" min="1" step="0.01" name="amount" required value="{{ old('amount', $f['balance'] ?: '') }}"></div>
        <div class="field"><label>Date</label><input class="input" type="date" name="date" value="{{ old('date', today_iso()) }}" max="{{ today_iso() }}" required></div>
        <div class="field full"><label>Official reference / OR number</label><input class="input" name="reference" required maxlength="80" value="{{ old('reference') }}"></div>
        <div class="field full"><label>Notes</label><input class="input" name="notes" maxlength="255" value="{{ old('notes') }}"></div>
      </div>
      <button class="btn btn-primary" data-busy="Recording…">{{ icon('check') }}Record & issue receipt</button>
    </form>
    <aside class="stack"><div class="card">@include('partials.payment-summary')</div><div class="card"><h3>Payments</h3>@include('partials.payment-history')</div></aside>
  </div>
@endif
@endsection
