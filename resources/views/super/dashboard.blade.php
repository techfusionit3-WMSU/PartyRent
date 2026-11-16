@extends('layouts.dash', ['title' => 'Platform overview', 'nav' => 'dashboard'])

@section('content')
<div class="page-head"><div><h1>Platform overview</h1><p>Everything on {{ setting('platformName') }} at a glance · {{ fmt_date(today_iso()) }}</p></div>
  <div class="row wrap"><a class="btn" href="{{ route('admin.audit') }}">{{ icon('file') }}Audit logs</a><a class="btn btn-primary" href="{{ route('super.admins') }}">{{ icon('shield') }}Manage admins</a></div>
</div>
<div class="stats mb-3">
  @include('partials.stat', ['label' => 'Total users', 'value' => number_format($users), 'sub' => $inactive.' inactive / suspended', 'icon' => 'users', 'color' => 'pink', 'href' => route('super.users')])
  @include('partials.stat', ['label' => 'Customers', 'value' => number_format($customers), 'sub' => $alsoSuppliers.' also suppliers', 'icon' => 'user', 'color' => 'purple'])
  @include('partials.stat', ['label' => 'Suppliers', 'value' => $suppliers, 'sub' => $pendingApps.' pending', 'icon' => 'store', 'color' => 'teal', 'href' => route('admin.applications')])
  @include('partials.stat', ['label' => 'Administrators', 'value' => $admins, 'sub' => $cashiers.' cashier'.($cashiers === 1 ? '' : 's'), 'icon' => 'shield', 'color' => 'sky', 'href' => route('super.admins')])
  @include('partials.stat', ['label' => 'Rental transactions', 'value' => $requests, 'sub' => $active.' active', 'icon' => 'activity', 'color' => 'purple', 'href' => route('super.monitoring')])
  @include('partials.stat', ['label' => 'Gross rental value collected', 'value' => money_short($gross), 'sub' => 'verified, net of refunds', 'icon' => 'chart', 'color' => 'teal'])
  @include('partials.stat', ['label' => 'Est. platform commission', 'value' => money_short($commission), 'sub' => setting('commissionPct').'% setting', 'icon' => 'cash', 'color' => 'gold'])
  @include('partials.stat', ['label' => 'Outstanding balances', 'value' => money_short($outstanding), 'sub' => $overdue.' overdue', 'icon' => 'clock', 'color' => 'red', 'href' => route('cashier.balances')])
</div>
<div class="grid grid-2">
  <div class="card"><h3>Rental trends</h3>@include('partials.columns', ['rows' => $trend, 'color' => '#7C3AED', 'label' => 'Requests per month'])</div>
  <div class="card"><h3>Collections per month</h3>@include('partials.columns', ['rows' => $collections, 'money' => true, 'color' => '#0D9488', 'label' => 'Collections per month'])</div>
  <div class="card pad-0"><div style="padding:18px 20px 0"><h3>Supplier performance</h3></div>
    <div class="table-wrap"><table class="table"><thead><tr><th>Supplier</th><th class="num">Rentals</th><th class="num">Value</th><th class="num">Rating</th></tr></thead><tbody>
      @forelse($perf as $s)<tr><td><a href="{{ route('suppliers.show', $s['model']) }}">{{ $s['model']->name }}</a></td><td class="num">{{ $s['rentals'] }}</td><td class="num">{{ money_short($s['value']) }}</td><td class="num">{{ $s['model']->rating > 0 ? number_format($s['model']->rating, 1) : '—' }}</td></tr>@empty<tr><td colspan="4" class="muted">No approved suppliers yet.</td></tr>@endforelse
    </tbody></table></div>
  </div>
  <div class="card"><div class="card-head"><h3>Latest system activity</h3><a class="small strong" href="{{ route('admin.audit') }}">All logs</a></div>
    @forelse($audit as $a)<div style="padding:8px 0;border-bottom:1px solid var(--line)"><b class="small">{{ $a->action }}</b> <span class="badge b-gray plain">{{ ucfirst($a->role) }}</span><div class="small muted">{{ $a->actor->name ?? 'System' }} · {{ time_ago($a->created_at) }}</div><div class="small">{{ $a->details }}</div></div>@empty<p class="small muted">No activity yet.</p>@endforelse
  </div>
</div>
@endsection
