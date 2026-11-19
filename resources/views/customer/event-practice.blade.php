@extends('layouts.market', ['title' => 'Practice checkout', 'nav' => 'rentals'])

{{-- Built-in practice checkout for event installments: no real money moves. --}}
@section('content')
<div class="card" style="max-width:480px;margin:24px auto">
  <span class="badge b-gold">Practice mode · no real money</span>
  <h1 class="mt-1 mb-0">{{ money($p->amount) }}</h1>
  <p class="muted">{{ $p->label }} · {{ $b->code }} · {{ $b->supplier->name }}</p>
  <form method="POST" action="{{ route('events.online.practice.pay', [$b, $p]) }}" class="stack-sm">@csrf
    <button class="btn btn-primary btn-block btn-lg" name="result" value="ok">{{ icon('check') }}Pay {{ money($p->amount) }}</button>
    <button class="btn btn-block" name="result" value="fail">Simulate a failed payment</button>
  </form>
  <p class="center small mt-2"><a href="{{ route('events.show', $b) }}">Cancel</a></p>
</div>
@endsection
