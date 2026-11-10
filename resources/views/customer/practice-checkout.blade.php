@extends('layouts.base', ['title' => 'Practice checkout'])

@section('body')
<div style="min-height:100vh;background:var(--surface-2, #F6F4FA);padding:24px 16px">
  <div style="max-width:460px;margin:0 auto">
    <div class="alert alert-warn mb-2">{{ icon('alert') }}<div class="small"><b>Practice mode.</b> This page stands in for PayMongo's checkout while there is no PayMongo account. No real money is charged.</div></div>
    <div class="card">
      <div class="small muted">Pay {{ setting('platformName', 'PartyRent') }}</div>
      <h2 class="mb-0" style="margin-top:4px">{{ money($s['amount']) }}</h2>
      <div class="small muted mb-2">{{ $s['label'] }} · {{ $r->event_name }} · ref {{ $p->code }}</div>
      <form method="POST" action="{{ route('paymongo.practice.act', $ref) }}">@csrf
        <div class="field"><label>Pay with</label>
          <div class="stack-sm">@foreach($methods as $k => $l)<label class="row" style="gap:8px;padding:10px 12px;border:1px solid var(--line);border-radius:10px"><input type="radio" name="method" value="{{ $k }}" @checked($loop->first)> {{ $l }}</label>@endforeach</div>
        </div>
        <button class="btn btn-primary btn-block mt-2" name="action" value="pay" style="width:100%" data-busy="Paying…">Pay {{ money($s['amount']) }}</button>
        <div class="row mt-1" style="gap:8px">
          <button class="btn" name="action" value="fail" style="flex:1">Simulate a failed payment</button>
          <button class="btn btn-ghost" name="action" value="cancel" style="flex:1">Cancel and go back</button>
        </div>
      </form>
      @if(($s['failed'] ?? 0) > 0)<p class="small muted mt-1 mb-0">Failed attempts so far: {{ $s['failed'] }}</p>@endif
    </div>
  </div>
</div>
@endsection
