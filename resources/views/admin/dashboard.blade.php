@extends('layouts.dash', ['title' => 'Admin Dashboard', 'nav' => 'dashboard'])

{{-- Reference AD.dashboard --}}
@section('content')
<div class="page-head"><div><h1>Platform operations</h1><p>Welcome, {{ auth()->user()->name }}. Here's what's happening on {{ setting('platformName') }}.</p></div></div>
<div class="stats mb-3">
  @include('partials.stat', ['label' => 'Registered customers', 'value' => $customers, 'sub' => $inactiveCustomers.' inactive/suspended', 'icon' => 'users', 'color' => 'pink', 'href' => route('admin.users')])
  @include('partials.stat', ['label' => 'Total suppliers', 'value' => $suppliers, 'icon' => 'store', 'color' => 'purple', 'href' => route('admin.users')])
  @include('partials.stat', ['label' => 'Active suppliers', 'value' => $activeSuppliers, 'icon' => 'check', 'color' => 'teal'])
  @include('partials.stat', ['label' => 'Pending applications', 'value' => $pendingApps->count(), 'sub' => 'awaiting review', 'icon' => 'file', 'color' => 'gold', 'href' => route('admin.applications')])
  @include('partials.stat', ['label' => 'Rental listings', 'value' => $listings, 'sub' => $packages.' active packages', 'icon' => 'box', 'color' => 'sky', 'href' => route('admin.listings')])
  @include('partials.stat', ['label' => 'Rental requests', 'value' => $requests, 'sub' => $pending.' pending', 'icon' => 'list', 'color' => 'purple', 'href' => route('admin.transactions')])
  @include('partials.stat', ['label' => 'Active reservations', 'value' => $active, 'icon' => 'calendar', 'color' => 'pink', 'href' => route('admin.transactions')])
  @include('partials.stat', ['label' => 'Completed rentals', 'value' => $completed, 'icon' => 'check', 'color' => 'teal'])
  @include('partials.stat', ['label' => 'Declined requests', 'value' => $declined, 'sub' => $cancelled.' cancelled', 'icon' => 'x', 'color' => 'red'])
  @include('partials.stat', ['label' => 'Reported issues', 'value' => $openComplaints, 'sub' => 'open complaints', 'icon' => 'flag', 'color' => 'red', 'href' => route('admin.complaints')])
</div>
<div class="grid grid-2">
  <div class="card"><h3>Rental requests per month</h3>@include('partials.chart', ['kind' => 'bars', 'rows' => $monthly, 'opts' => ['color' => '#7C3AED']])</div>
  <div class="card"><h3>Request status</h3>@include('partials.chart', ['kind' => 'donut', 'rows' => $status, 'opts' => ['center' => 'requests']])</div>
  <div class="card"><div class="card-head"><h3>Recent activity</h3><a class="small strong" href="{{ route('admin.audit') }}">View all</a></div>
    @forelse($audit as $a)<div style="padding:8px 0;border-bottom:1px solid var(--line)"><b class="small">{{ $a->action }}</b><div class="small muted">{{ $a->details }}</div><div class="small muted">{{ $a->actor->name ?? 'System' }} · {{ time_ago($a->created_at) }}</div></div>@empty<p class="muted">No activity yet.</p>@endforelse
  </div>
  <div class="card"><div class="card-head"><h3>Pending supplier applications</h3><a class="small strong" href="{{ route('admin.applications') }}">Review</a></div>
    @forelse($pendingApps as $s)
      <div class="row between" style="padding:10px 0;border-bottom:1px solid var(--line)"><div class="row"><span class="logo-tile" style="width:40px;height:40px;font-size:1.2rem;background:var(--brand-50)">{{ $s->logo }}</span><div><b>{{ $s->name }}</b><div class="small muted">{{ $s->owner->name ?? '—' }} · {{ $s->city }} · {{ time_ago($s->applied_at) }}</div></div></div><a class="btn btn-sm" href="{{ route('admin.applications') }}">Review</a></div>
    @empty <p class="muted">No pending applications.</p> @endforelse
  </div>
</div>
@endsection
