@extends('layouts.dash', ['title' => 'Dashboard', 'nav' => 'dashboard'])

@section('content')
<div class="page-head"><div><h1>{{ active_role() === 'superadmin' ? 'Platform overview' : 'Admin dashboard' }}</h1><p>{{ fmt_date(today_iso()) }} · {{ setting('platformName') }}</p></div>@if($pendingApps)<a class="btn btn-primary" href="{{ route('admin.applications') }}">{{ icon('file') }}Review {{ $pendingApps }} application{{ $pendingApps > 1 ? 's' : '' }}</a>@endif</div>
<div class="stats">
  @include('partials.stat', ['label' => 'Registered users', 'value' => $users, 'sub' => $customers.' customers', 'icon' => 'users', 'color' => 'purple', 'href' => route('admin.users')])
  @include('partials.stat', ['label' => 'Approved suppliers', 'value' => $suppliers, 'sub' => $pendingApps.' pending review', 'icon' => 'store', 'color' => 'pink', 'href' => route('admin.applications')])
  @include('partials.stat', ['label' => 'Published listings', 'value' => $listings, 'icon' => 'box', 'color' => 'sky'])
  @include('partials.stat', ['label' => 'Pending requests', 'value' => $pending, 'sub' => 'awaiting suppliers', 'icon' => 'clock', 'color' => 'gold', 'href' => route('admin.transactions', ['status' => 'pending'])])
  @include('partials.stat', ['label' => 'Active rentals', 'value' => $active, 'icon' => 'truck', 'color' => 'teal', 'href' => route('admin.transactions')])
  @include('partials.stat', ['label' => 'Overdue payments', 'value' => $overdue, 'icon' => 'alert', 'color' => 'red', 'href' => route('admin.transactions')])
  @include('partials.stat', ['label' => 'Completed rentals', 'value' => $completed, 'icon' => 'check', 'color' => 'teal'])
  @include('partials.stat', ['label' => 'Verified rental volume', 'value' => money_short($gmv), 'sub' => 'est. commission '.money_short($gmv * $commission / 100).' ('.$commission.'%)', 'icon' => 'chart', 'color' => 'purple'])
  @include('partials.stat', ['label' => 'Open complaints', 'value' => $openComplaints, 'icon' => 'flag', 'color' => 'gold'])
</div>
<div class="grid grid-2 mt-3">
  <div class="card"><div class="card-head"><h3>Requests by status</h3></div>@include('partials.bars', ['rows' => $byStatus, 'color' => '#7C3AED'])</div>
  <div class="card"><div class="card-head"><h3>Recent activity</h3><a class="small strong" href="{{ route('admin.audit') }}">Full log</a></div>
    @foreach($audit as $a)<div style="padding:8px 0;border-bottom:1px solid var(--line)"><b>{{ $a->action }}</b> <span class="small muted">· {{ $a->actor->name ?? 'System' }} ({{ $a->role }}) · {{ time_ago($a->created_at) }}</span><div class="small">{{ $a->details }}</div></div>@endforeach
  </div>
</div>
@endsection
