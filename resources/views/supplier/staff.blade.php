@extends('layouts.dash', ['title' => 'Staff & Roles', 'nav' => 'staff'])

@section('content')
<div class="page-head"><div><h1>Staff & Roles</h1><p>People who help run {{ $s->name }}. Each role only sees the pages it needs. PartyRent collects every customer payment, so no role handles money.</p></div></div>

<div class="grid grid-side">
  <div class="card">
    <div class="card-head"><h3>Your staff</h3><span class="small muted">{{ $staff->count() }} {{ $staff->count() === 1 ? 'person' : 'people' }}</span></div>
    @forelse($staff as $m)
      <div class="todo-row">
        <span class="avatar">{{ $m->user->initials() }}</span>
        <div class="grow"><div class="strong">{{ $m->user->name }}</div><div class="small muted">{{ $m->user->email }} · added {{ fmt_date($m->created_at->toDateString()) }}</div></div>
        <form method="POST" action="{{ route('supplier.staff.update', $m) }}" class="row" style="gap:6px">@csrf @method('PUT')
          <select class="input input-sm" name="role" aria-label="Role for {{ $m->user->name }}" onchange="this.form.submit()">@foreach(\App\Models\ShopStaff::ROLES as $k => [$l])<option value="{{ $k }}" @selected($m->role === $k)>{{ $l }}</option>@endforeach</select>
        </form>
        <form method="POST" action="{{ route('supplier.staff.remove', $m) }}" data-confirm="Remove {{ $m->user->name }}? They lose access to your shop right away. Their PartyRent account stays." data-confirm-title="Remove staff?" data-confirm-ok="Remove">@csrf @method('DELETE')<button class="btn btn-sm btn-danger" aria-label="Remove {{ $m->user->name }}">{{ icon('trash') }}</button></form>
      </div>
    @empty
      <p class="muted mb-0">No staff yet. Add your first person on the right.</p>
    @endforelse
  </div>

  <div class="stack">
    <form class="card" method="POST" action="{{ route('supplier.staff.store') }}">@csrf
      <h3>Add a person</h3>
      <div class="field"><label for="st-name">Name</label><input class="input" id="st-name" name="name" maxlength="120" value="{{ old('name') }}" placeholder="e.g. Liza Cruz"></div>
      <div class="field"><label for="st-email">Email</label><input class="input" type="email" id="st-email" name="email" required maxlength="180" value="{{ old('email') }}">@error('email')<div class="error-text">{{ $message }}</div>@enderror
        <div class="hint">If they have no PartyRent account yet, we create one and email them a link to set a password.</div></div>
      <div class="field"><label for="st-role">Role</label><select class="input" id="st-role" name="role">@foreach(\App\Models\ShopStaff::ROLES as $k => [$l])<option value="{{ $k }}" @selected(old('role', 'coordinator') === $k)>{{ $l }}</option>@endforeach</select></div>
      <button class="btn btn-primary">{{ icon('plus') }}Add to staff</button>
    </form>
    <div class="card"><h3>What each role can do</h3>
      <dl class="kv">@foreach(\App\Models\ShopStaff::ROLES as [$l, $what])<dt>{{ $l }}</dt><dd class="small">{{ $what }}</dd>@endforeach<dt>Owner (you)</dt><dd class="small">Everything, including staff, shop settings and the payout account.</dd></dl>
    </div>
  </div>
</div>
@endsection
