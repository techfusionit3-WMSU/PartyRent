@extends('layouts.dash', ['title' => 'Customers & Suppliers', 'nav' => 'users'])

@section('content')
<div class="page-head"><div><h1>Customers & suppliers</h1><p>Suspended accounts can't log in. Staff accounts are managed by the superadmin.</p></div>
  <form method="GET"><input class="input input-sm" name="q" value="{{ $q }}" placeholder="Search name or email" style="width:240px"></form></div>
<div class="card pad-0"><div class="table-wrap"><table class="table cards"><thead><tr><th>User</th><th>Roles</th><th>Supplier shop</th><th>Joined</th><th>Status</th><th></th></tr></thead><tbody>
  @foreach($list as $u)
    <tr>
      <td data-label="User"><div class="row"><span class="avatar">{{ $u->initials() }}</span><div><b>{{ $u->name }}</b><div class="small muted">{{ $u->email }} · {{ $u->phone }}</div></div></div></td>
      <td data-label="Roles">{{ implode(', ', array_map('ucfirst', $u->roles)) }}</td>
      <td data-label="Shop">@if($u->supplier){{ $u->supplier->name }} <span class="badge {{ $u->supplier->status === 'approved' ? 'b-green' : ($u->supplier->status === 'pending' ? 'b-gold' : 'b-red') }}">{{ ucfirst($u->supplier->status) }}</span>@else<span class="muted">—</span>@endif</td>
      <td data-label="Joined">{{ fmt_date($u->created_at) }}</td>
      <td data-label="Status"><span class="badge {{ $u->status === 'active' ? 'b-green' : 'b-red' }}">{{ ucfirst($u->status) }}</span></td>
      <td>@unless($u->isStaff() || $u->id === auth()->id())
        <form method="POST" action="{{ route('admin.users.status', $u) }}" data-confirm="{{ $u->status === 'active' ? 'Suspend '.$u->name.'? They will be signed out and unable to log in.' : 'Reactivate '.$u->name.'?' }}" data-confirm-title="{{ $u->status === 'active' ? 'Suspend account?' : 'Reactivate account?' }}" @if($u->status === 'active') data-danger @endif>@csrf<input type="hidden" name="status" value="{{ $u->status === 'active' ? 'suspended' : 'active' }}"><button class="btn btn-xs {{ $u->status === 'active' ? 'btn-ghost' : '' }}">{{ $u->status === 'active' ? 'Suspend' : 'Reactivate' }}</button></form>
      @endunless</td>
    </tr>
  @endforeach
</tbody></table></div><div style="padding:12px 16px">{{ $list->links('pagination::simple-default') }}</div></div>
@endsection
