@extends('layouts.dash', ['title' => 'Platform Users', 'nav' => 'all-users'])

@section('content')
<div class="page-head"><div><h1>Platform users</h1><p>Every account, including staff. Suspended accounts can't sign in.</p></div>
  <form method="GET" class="row wrap" style="gap:6px"><input class="input input-sm" name="q" value="{{ $q }}" placeholder="Search name or email" style="width:220px"><select class="input input-sm" name="role" style="width:auto" data-autosubmit><option value="">All roles</option>@foreach(['customer', 'cashier', 'admin', 'superadmin'] as $r)<option value="{{ $r }}" @selected($role === $r)>{{ ucfirst($r) }}</option>@endforeach</select><button class="btn btn-sm">Search</button></form></div>
<div class="card pad-0"><div class="table-wrap"><table class="table cards"><thead><tr><th>User</th><th>Roles</th><th>Last sign-in</th><th>Joined</th><th>Status</th><th></th></tr></thead><tbody>
  @foreach($list as $u)
    <tr>
      <td data-label="User"><div class="row"><span class="avatar">{{ $u->initials() }}</span><div><b>{{ $u->name }}</b><div class="small muted">{{ $u->email }}</div></div></div></td>
      <td data-label="Roles">{{ implode(', ', array_map('ucfirst', $u->roles)) }}{{ $u->supplier ? ' + Supplier ('.$u->supplier->status.')' : '' }}</td>
      <td data-label="Last sign-in"><span class="small">{{ $u->last_login_at ? fmt_datetime($u->last_login_at) : '—' }}</span></td>
      <td data-label="Joined">{{ fmt_date($u->created_at) }}</td>
      <td data-label="Status"><span class="badge {{ $u->status === 'active' ? 'b-green' : 'b-red' }}">{{ ucfirst($u->status) }}</span></td>
      <td>@if($u->id !== auth()->id())<form method="POST" action="{{ route('super.users.status', $u) }}" data-confirm="{{ $u->status === 'active' ? 'Suspend '.$u->name.'?' : 'Reactivate '.$u->name.'?' }}" data-confirm-title="Change account status?" @if($u->status === 'active') data-danger @endif>@csrf<input type="hidden" name="status" value="{{ $u->status === 'active' ? 'suspended' : 'active' }}"><button class="btn btn-xs {{ $u->status === 'active' ? 'btn-ghost' : '' }}">{{ $u->status === 'active' ? 'Suspend' : 'Reactivate' }}</button></form>@endif</td>
    </tr>
  @endforeach
</tbody></table></div><div style="padding:12px 16px">{{ $list->links('pagination::simple-default') }}</div></div>
@endsection
