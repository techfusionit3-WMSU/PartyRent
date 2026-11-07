@extends('layouts.dash', ['title' => 'Roles & Permissions', 'nav' => 'roles'])

@section('content')
<div class="page-head"><div><h1>Roles & permissions</h1><p>Default permissions for each staff role. People with custom permissions (set on Administrators) aren't affected.</p></div></div>
<form method="POST" action="{{ route('super.roles.update') }}" data-confirm="Change the default permissions for every admin and cashier who uses role defaults?" data-confirm-title="Save role defaults?" data-confirm-ok="Save">
  @csrf
  <div class="grid grid-2">
    <div class="card"><h3>Admin</h3>@foreach($adminPerms as $k => $l)<label class="check"><input type="checkbox" name="admin[]" value="{{ $k }}" @checked(in_array($k, $defaults['admin'], true))> {{ $l }} <span class="small muted">({{ $k }})</span></label>@endforeach</div>
    <div class="card"><h3>Cashier</h3>@foreach($cashierPerms as $k => $l)<label class="check"><input type="checkbox" name="cashier[]" value="{{ $k }}" @checked(in_array($k, $defaults['cashier'], true))> {{ $l }} <span class="small muted">({{ $k }})</span></label>@endforeach
      <div class="card card-flat soft mt-2" style="padding:12px"><b>Superadmin</b><div class="small muted">Always has every permission and can't be restricted.</div></div></div>
  </div>
  <button class="btn btn-primary mt-2">Save role defaults</button>
</form>
@if($customised->isNotEmpty())
  <div class="card mt-3"><h3>Staff with custom permissions</h3>@foreach($customised as $u)<div class="row between" style="padding:8px 0;border-bottom:1px solid var(--line)"><span>{{ $u->name }} <span class="small muted">{{ $u->email }}</span></span><a class="btn btn-xs" href="{{ route('super.admins') }}">Review</a></div>@endforeach</div>
@endif
@endsection
