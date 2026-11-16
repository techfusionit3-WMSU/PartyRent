@extends('layouts.market', ['title' => 'Request submitted', 'nav' => 'rentals'])

@section('content')
<div class="card center" style="max-width:640px;margin:20px auto;padding:36px 24px">
  <div style="font-size:3.4rem">🎉</div>
  <h1>Request submitted!</h1>
  <p class="muted">Your rental request number is</p>
  <div class="price-lg" style="font-size:2rem">{{ $r->code }}</div>
  <div class="mt-1">@include('partials.badges', ['status' => 'pending'])</div>
  @php($up = $r->fin()['upfront'])
  <p class="mt-2">We sent it to <b>{{ $r->supplier->name }}</b>. To confirm it, pay the <b>{{ strtolower($up['label']) }} of {{ money($up['amount']) }}</b>. {{ $r->supplier->name }} can accept once PartyRent's cashier has verified it.</p>
  <div class="card card-flat soft" style="text-align:left"><b>What happens next</b><ol class="small mb-0" style="padding-left:18px">
    <li>Pay the {{ strtolower($up['label']) }} ({{ money($up['amount']) }}) from your request page. Our cashier verifies it.</li>
    <li>The rental partner then accepts or declines (status stays <b>Pending</b> until then). If they decline, PartyRent refunds you in full.</li>
    <li>Once accepted, the rest of your payment schedule appears in <b>My Rentals</b>.</li>
    <li>Pay using {{ \App\Models\Payment::METHOD_LABEL[$r->method] ?? $r->method }}: payments go to PartyRent, count after cashier verification, and are released to the rental partner only after your rental is completed.</li>
    <li>Receive your items by {{ $r->fulfillment === 'delivery' ? 'delivery' : 'pickup' }} and return them after the event.</li>
  </ol></div>
  <div class="row wrap mt-3" style="justify-content:center"><a class="btn btn-primary" href="{{ route('rentals.show', $r) }}">View request</a><a class="btn" href="{{ route('rentals.index') }}">My Rentals</a><a class="btn btn-ghost" href="{{ route('home') }}">Keep browsing</a></div>
</div>
@endsection
