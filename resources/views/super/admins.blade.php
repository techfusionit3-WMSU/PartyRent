@extends('layouts.dash', ['title' => 'Administrators', 'nav' => 'admins'])

@section('content')
<div class="page-head"><div><h1>Administrators & cashiers</h1><p>Create staff accounts and fine-tune what each person can do.</p></div><button class="btn btn-primary" data-modal="newStaff">{{ icon('plus') }}New staff account</button></div>
<div class="stack">
  @foreach($staff as $u)
    @php($role = $u->hasRole('superadmin') ? 'superadmin' : ($u->hasRole('admin') ? 'admin' : 'cashier'))
    @php($custom = $role === 'admin' ? $u->admin_perms : ($role === 'cashier' ? $u->cashier_perms : null))
    <div class="card">
      <div class="row wrap between">
        <div class="row"><span class="avatar">{{ $u->initials() }}</span><div><b>{{ $u->name }}</b> <span class="badge {{ $role === 'superadmin' ? 'b-purple' : ($role === 'admin' ? 'b-pink' : 'b-teal') }}">{{ ucfirst($role) }}</span> <span class="badge {{ $u->status === 'active' ? 'b-green' : 'b-red' }}">{{ ucfirst($u->status) }}</span><div class="small muted">{{ $u->email }} · last sign-in {{ $u->last_login_at ? fmt_datetime($u->last_login_at) : 'never' }}</div></div></div>
        @if($role !== 'superadmin')<button class="btn btn-sm" data-modal="perm-{{ $u->id }}">{{ icon('lock') }}Permissions{{ $custom !== null ? ' (custom)' : '' }}</button>@else<span class="small muted">Has every permission</span>@endif
      </div>
      @if($role !== 'superadmin')<div class="row wrap gap-sm mt-1">@foreach($custom ?? $defaults[$role] as $p)<span class="tag">{{ ($role === 'admin' ? $adminPerms : $cashierPerms)[$p] ?? $p }}</span>@endforeach</div>@endif
    </div>
    @if($role !== 'superadmin')
    <div class="modal-backdrop hidden" id="perm-{{ $u->id }}"><div class="modal" role="dialog" aria-modal="true"><div class="modal-head"><h3>Permissions · {{ $u->name }}</h3><button class="icon-btn" data-close aria-label="Close">{{ icon('x') }}</button></div>
      <form method="POST" action="{{ route('super.admins.update', $u) }}" data-confirm-title="Update access?" data-confirm="This changes what {{ $u->name }} can do immediately." data-confirm-ok="Update">@csrf<div class="modal-body">
        <label class="check mb-2"><input type="checkbox" name="use_defaults" value="1" @checked($custom === null)> Use the {{ $role }} role defaults (set on Roles & Permissions)</label>
        @foreach($role === 'admin' ? $adminPerms : $cashierPerms as $k => $l)<label class="check"><input type="checkbox" name="perms[]" value="{{ $k }}" @checked(in_array($k, $custom ?? $defaults[$role], true))> {{ $l }} <span class="small muted">({{ $k }})</span></label>@endforeach
        <p class="small muted mt-2 mb-0">Untick "use role defaults" for the boxes below to take effect.</p>
      </div><div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Save permissions</button></div></form>
    </div></div>
    @endif
  @endforeach
</div>

<div class="modal-backdrop hidden" id="newStaff"><div class="modal" role="dialog" aria-modal="true"><div class="modal-head"><h3>New staff account</h3><button class="icon-btn" data-close aria-label="Close">{{ icon('x') }}</button></div>
  <form method="POST" action="{{ route('super.admins.store') }}" data-confirm-title="Create this account?" data-confirm="The new staff member gets access as soon as it is created." data-confirm-ok="Create">@csrf<div class="modal-body">
    <div class="field"><label>Full name</label><input class="input" name="name" required minlength="3" value="{{ old('name') }}"></div>
    <div class="form-grid"><div class="field"><label>Email</label><input class="input" type="email" name="email" required value="{{ old('email') }}"></div><div class="field"><label>Phone</label><input class="input" name="phone" value="{{ old('phone') }}"></div></div>
    <div class="form-grid"><div class="field"><label>Role</label><select class="input" name="role"><option value="admin">Admin</option><option value="cashier">Cashier</option></select></div><div class="field"><label>Temporary password</label><input class="input" type="text" name="password" required minlength="10" autocomplete="off"><div class="hint">At least 10 characters, with an uppercase and a lowercase letter.</div></div></div>
  </div><div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Create account</button></div></form>
</div></div>
@endsection
