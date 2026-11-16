@extends('layouts.market', ['title' => 'Request Submitted', 'nav' => 'rentals'])

{{-- Port of C.confirmation (reference js/pages/customer-rent.js) --}}
@section('content')
<div class="card center" style="max-width:680px;margin:20px auto;padding:36px 24px">
  <div style="font-size:3.4rem">🎉</div>
  <h1>{{ $list->count() > 1 ? $list->count().' requests submitted!' : 'Request submitted!' }}</h1>
  <p class="muted">Each shop received its own rental request.</p>
  <div class="stack-sm" style="text-align:left">
    @foreach($list as $r)
      <div class="card card-flat row between wrap" style="padding:12px 14px"><div><b class="price">{{ $r->code }}</b> · {{ $r->supplier->name }}<div class="small muted">{{ fmt_range($r->start_date, $r->end_date) }} · {{ \App\Domain\Finance::PLAN_LABEL[$r->plan] ?? '' }}{{ count($r->destinations ?? []) > 1 ? ' · '.count($r->destinations).' locations' : '' }}</div></div>
        <div class="row">@include('partials.badges', ['status' => 'pending'])<b>{{ money($r->pricing['total']) }}</b><a class="btn btn-xs" href="{{ route('rentals.show', $r) }}">View</a></div></div>
    @endforeach
  </div>
  <div class="card card-flat soft mt-2" style="text-align:left"><b>What happens next</b><ol class="small mb-0" style="padding-left:18px">
    <li>Each supplier reviews availability and its inventory allocation (status stays <b>Pending</b>).</li>
    <li>Once accepted, that order's payment schedule appears in <b>My Rentals</b> — reservation fee first (or the full payment).</li>
    @if($list->contains(fn ($r) => $r->plan === 'installment'))<li>Installment schedules are approved by the supplier before they take effect.</li>@endif
    <li>You pay online through PartyRent; each payment is confirmed automatically. Everything is due on or before the event date.</li>
  </ol></div>
  <div class="row wrap mt-3" style="justify-content:center"><a class="btn btn-primary" href="{{ route('rentals.index') }}">My Rentals</a>@if($left)<a class="btn" href="{{ route('cart') }}">Back to Rental List ({{ $left }} left)</a>@endif<a class="btn btn-ghost" href="{{ route('home') }}">Keep browsing</a></div>
</div>
@endsection
