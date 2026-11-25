@extends('layouts.market', ['title' => 'Profile & Settings', 'nav' => 'profile'])

@section('content')
@if($welcome)<div class="alert alert-success mb-2">{{ icon('party') }}<div><b>Welcome to PartyRent!</b> Your account is ready. <a href="{{ route('ai') }}">Plan your first event with AI →</a></div></div>@endif
<div class="card row wrap mb-3">
  <span class="avatar lg">{{ $u->initials() }}</span>
  <div class="grow"><h1 class="mb-0">{{ $u->name }}</h1><div class="muted">{{ $u->email }} · Member since {{ fmt_date($u->created_at) }}</div>
    <div class="row wrap gap-sm mt-1"><span class="badge b-pink">Customer</span>@if($sup)<span class="badge {{ ['approved' => 'b-purple', 'pending' => 'b-gold'][$sup->status] ?? 'b-red' }}">Supplier · {{ $sup->status === 'pending' ? 'Pending Review' : ucfirst($sup->status) }}</span>@endif</div>
  </div>
  <div class="row wrap">
    @if($sup && $sup->status === 'approved')
      <form method="POST" action="{{ route('mode.switch') }}">@csrf<input type="hidden" name="mode" value="supplier"><button class="btn btn-primary" title="Open your shop">{{ icon('store') }}{{ $sup->name }}</button></form>
    @else
      <a class="btn btn-primary" href="{{ route('start-selling') }}">{{ icon('store') }}{{ $sup ? 'View application' : 'Start Selling' }}</a>
    @endif
    <form method="POST" action="{{ route('logout') }}">@csrf<button class="btn">{{ icon('logout') }}Log out</button></form>
  </div>
</div>
@php($ver = \App\Domain\Verifications::of($u))
<div class="card row wrap between mb-3" style="gap:10px">
  <div><b>{{ icon('shield') }} Account verification</b> <span class="badge {{ \App\Models\CustomerVerification::STATUS_BADGE[$ver->status] ?? 'b-gray' }}">{{ $ver->statusLabel() }}</span>
    <div class="small muted">{{ $ver->isApproved() ? 'You can rent from any shop.' : 'Needed before you rent: a government ID and your bank or e-wallet account.' }}</div>
    @if($ver->trustBadges())<div class="row wrap gap-sm mt-1">@foreach($ver->trustBadges() as [$l, $c])<span class="badge {{ $c }}">{{ $l }}</span>@endforeach</div>@endif</div>
  <a class="btn {{ $ver->isApproved() ? '' : 'btn-primary' }}" href="{{ route('account.verification') }}">{{ $ver->isApproved() ? 'View' : ($ver->status === 'pending' ? 'View status' : 'Verify now') }}</a>
</div>
<div class="stats mb-3">
  @include('partials.stat', ['label' => 'Total requests', 'value' => $reqs->count(), 'icon' => 'list', 'color' => 'pink', 'href' => route('rentals.index')])
  @include('partials.stat', ['label' => 'Active rentals', 'value' => $reqs->filter->isActive()->count(), 'icon' => 'calendar', 'color' => 'purple', 'href' => route('rentals.index', ['tab' => 'active'])])
  @include('partials.stat', ['label' => 'Completed', 'value' => $reqs->where('status', 'completed')->count(), 'icon' => 'check', 'color' => 'teal', 'href' => route('rentals.index', ['tab' => 'completed'])])
  @include('partials.stat', ['label' => 'Payments', 'value' => '', 'sub' => '<a href="'.route('customer.payments').'">View history →</a>', 'icon' => 'card', 'color' => 'gold'])
</div>
<div class="grid grid-2">@include('partials.account-forms', ['u' => $u])</div>
@endsection
