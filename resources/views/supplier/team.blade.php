@extends('layouts.dash', ['title' => 'Team & Roles', 'nav' => 'team'])
@php($R = \App\Models\ShopStaff::labels($s))
@php($P = \App\Models\ShopStaff::ORG_PERM_LIST)
@php($solo = ! $s->isOrganization())
@php($multi = $solo && $allBranches->count() > 1)
@php($brNames = fn ($ids) => $ids ? collect($ids)->map(fn ($id) => $allBranches[(int) $id]->name ?? null)->filter()->implode(', ') : 'All branches')
@section('content')
<div class="page-head"><div><h1>Team & Roles</h1><p>Roles belong to <b>{{ $s->name }}</b>, not to PartyRent. Each person uses their own PartyRent account, switches to <b>Business Mode</b> and works only in the modules of their roles.@if($solo) A team is <b>optional</b>: as the owner you can run every module yourself.@endif</p></div><button class="btn btn-primary" data-modal="empAdd">{{ icon('plus') }}Add employee</button></div>

<div class="card pad-0 mb-3"><div class="table-wrap"><table class="table cards"><thead><tr><th>Member</th><th>Roles</th>@if($multi)<th>Branches</th>@endif<th>Status</th><th>Added</th><th></th></tr></thead><tbody>
@foreach($members as $m)
  @php($tweaked = isset($m['member']) && ($m['member']->perms_add || $m['member']->perms_remove))
  <tr>
    <td data-label="Member"><div class="row"><span class="avatar">{{ $m['user']->initials() }}</span><div><b>{{ $m['user']->name }}</b><div class="small muted">{{ $m['user']->email }}</div></div></div></td>
    <td data-label="Roles">@foreach($m['roles'] as $r)<span class="badge {{ \App\Models\ShopStaff::ROLE_BADGE[$r] ?? 'b-gray' }}">{{ $R[$r] ?? $r }}</span> @endforeach @if($tweaked)<span class="badge b-gold plain" title="The owner added or removed permissions for this person">Custom permissions</span>@endif</td>
    @if($multi)<td data-label="Branches"><span class="small">{{ $m['role'] === 'org_admin' ? 'All branches' : $brNames($m['member']->branch_ids) }}</span></td>@endif
    <td data-label="Status"><span class="badge {{ $m['status'] === 'active' ? 'b-green' : 'b-gray' }}">{{ ucfirst($m['status']) }}</span></td>
    <td data-label="Added"><span class="small">{{ fmt_date($m['since']?->toDateString()) }}</span></td>
    <td data-label="">@if($m['role'] === 'org_admin')<span class="small muted">Account owner · all modules</span>@else
      <div class="btn-group">
        <button class="btn btn-xs" data-modal="empRole{{ $m['user']->id }}">Roles{{ $multi ? ' & branches' : '' }}</button>
        @if($scope['owner'])<button class="btn btn-xs" data-modal="empPerm{{ $m['user']->id }}">Permissions</button>@endif
        @php($on = $m['status'] !== 'active')
        <form method="POST" action="{{ route('supplier.team.update', $m['user']) }}" data-confirm-title="{{ ($on ? 'Reactivate ' : 'Deactivate ').$m['user']->name }}?" data-confirm="{{ $on ? 'They can switch to Business Mode for your shop again.' : 'They lose access to your shop. Their own customer account stays, and their past actions stay in the records.' }}" data-confirm-ok="{{ $on ? 'Reactivate' : 'Deactivate' }}" @if(! $on) data-danger @endif>@csrf @method('PUT')<input type="hidden" name="status" value="{{ $on ? 'active' : 'deactivated' }}"><button class="btn btn-xs {{ $on ? 'btn-success' : 'btn-ghost' }}">{{ $on ? 'Reactivate' : 'Deactivate' }}</button></form>
      </div>
    @endif</td>
  </tr>
@endforeach
@foreach($invites as $i)
  @php($expired = $i->isExpired())
  <tr>
    <td data-label="Member"><div class="row"><span class="avatar">{{ icon('user') }}</span><div><b>{{ $i->email }}</b><div class="small muted">Invited by {{ $i->inviter?->name ?? '—' }}</div></div></div></td>
    <td data-label="Roles">@foreach($i->roleList() as $r)<span class="badge {{ \App\Models\ShopStaff::ROLE_BADGE[$r] ?? 'b-gray' }}">{{ $R[$r] ?? $r }}</span> @endforeach</td>
    @if($multi)<td data-label="Branches"><span class="small">{{ $brNames($i->branch_ids) }}</span></td>@endif
    <td data-label="Status">@if($expired)<span class="badge b-gray">Expired</span>@else<span class="badge b-gold">Invited · waiting</span>@endif</td>
    <td data-label="Added"><span class="small">{{ fmt_date($i->last_sent_at->toDateString()) }}</span></td>
    <td data-label=""><div class="btn-group">
      <form method="POST" action="{{ route('supplier.team.resend', $i) }}">@csrf<button class="btn btn-xs">Resend</button></form>
      <form method="POST" action="{{ route('supplier.team.cancel', $i) }}" data-confirm-title="Cancel the invitation to {{ $i->email }}?" data-confirm="The link in their email stops working." data-confirm-ok="Cancel invitation" data-danger>@csrf @method('DELETE')<button class="btn btn-xs btn-ghost">Cancel</button></form>
    </div></td>
  </tr>
@endforeach
</tbody></table></div></div>

@if($solo)
<div class="card mb-3"><h3>Modules by role</h3><p class="small muted">Each role works in its own module with its own home page and menu. Someone with two roles switches between their modules at the top of the menu.</p>
  <div class="grid grid-3">
    @foreach(\App\Support\CompanyModules::matrix($s) as $k => $mod)
      <div class="card card-flat" style="padding:12px"><b>{{ $mod['label'] }} module</b><div class="small muted mb-1">{{ $mod['about'] }}</div>@foreach($mod['roles'] as $r)<span class="badge {{ \App\Models\ShopStaff::ROLE_BADGE[$r] ?? 'b-gray' }}">{{ $R[$r] }}</span> @endforeach</div>
    @endforeach
  </div>
</div>
@endif

<div class="card"><h3>What each role can do</h3><div class="table-wrap"><table class="table"><thead><tr><th>Permission</th>@foreach($R as $l)<th class="center">{{ $l }}</th>@endforeach</tr></thead><tbody>
@foreach($P as $k => $l)
  <tr><td>{{ $l }}</td>@foreach(array_keys($R) as $r)<td class="center">@if(in_array($k, \App\Models\ShopStaff::permsOf($r), true))<span class="perm-yes">✓</span>@else<span class="perm-no">—</span>@endif</td>@endforeach</tr>
@endforeach
</tbody></table></div><p class="small muted mb-0 mt-1">These are the defaults. Use <b>Permissions</b> on a member to add or remove single permissions for that person only. Managing the team always stays with the owner, and platform administration stays with PartyRent.</p></div>

{{-- Add employee --}}
<div class="modal-backdrop {{ $errors->any() ? '' : 'hidden' }}" id="empAdd"><div class="modal" role="dialog" aria-modal="true"><div class="modal-head"><h3>Add employee</h3><button class="icon-btn" data-close aria-label="Close">{{ icon('x') }}</button></div>
  <form method="POST" action="{{ route('supplier.team.store') }}" id="empForm" novalidate>@csrf<div class="modal-body">
    @error('team')<div class="alert alert-danger mb-2">{{ icon('alert') }}<div>{{ $message }}</div></div>@enderror
    <div class="field"><label for="em-email">Their email</label>
      <div class="row" style="gap:8px"><input class="input" id="em-email" type="email" name="email" required value="{{ old('email') }}" autocomplete="off" style="flex:1"><button type="button" class="btn" id="emCheck">Check</button></div>
      <div class="hint" id="emHint">We check the email first. An account that already works for another business can't be added. A new email gets a code to create its own account.</div>
    </div>
    <div class="field"><label>Roles <span class="muted small">(one or more; the first one ticked is their main module)</span></label>
      @foreach($scope['roles'] as $r)
        <label class="check mb-1"><input type="checkbox" name="roles[]" value="{{ $r }}" @checked(in_array($r, (array) old('roles', []), true))> <span><b>{{ $R[$r] }}</b><span class="small muted" style="display:block">{{ \App\Models\ShopStaff::hints($s)[$r] }}</span></span></label>
      @endforeach
    </div>
    @if($multi)
      <div class="field"><label>Branches</label>
        @foreach($branches as $b)<label class="check mb-1"><input type="checkbox" name="branches[]" value="{{ $b->id }}" @checked(in_array($b->id, array_map('intval', (array) old('branches', $scope['branches'] ?? [])), true))> <span>{{ $b->name }}</span></label>@endforeach
        <div class="hint">{{ $scope['branches'] === null ? 'Leave all unticked for every branch. A Branch Admin needs at least one branch; an Organization Admin always has every branch.' : 'They can only see and work on the branches ticked here.' }}</div>
      </div>
    @endif
    <p class="small muted mb-0">Nobody joins until they confirm from their inbox. The invitation expires in {{ \App\Models\StaffInvite::TTL_HOURS }} hours.</p>
  </div><div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary" id="emSend" disabled>{{ icon('send') }}Send invitation</button></div></form>
</div></div>
@push('scripts')
<script>
(function () {
  var f = document.getElementById('empForm'), em = document.getElementById('em-email'), hint = document.getElementById('emHint'), send = document.getElementById('emSend'), btn = document.getElementById('emCheck');
  var boxes = f.querySelectorAll('input[name="roles[]"]');
  var checked = '', ok = false, base = hint.textContent;
  function anyRole() { return Array.prototype.some.call(boxes, function (b) { return b.checked; }); }
  function sync() { send.disabled = !(ok && anyRole()); }
  function show(cls, msg) { hint.className = 'hint'; hint.style.color = cls === 'ok' ? 'var(--green)' : cls === 'bad' ? 'var(--red)' : ''; hint.style.fontWeight = cls ? '600' : ''; hint.textContent = msg; }
  function check() {
    var v = em.value.trim().toLowerCase();
    if (!v) { ok = false; sync(); show('', base); return Promise.resolve(); }
    if (v === checked) return Promise.resolve();
    show('', 'Checking…');
    var fd = new FormData(); fd.append('email', v); fd.append('_token', f.querySelector('[name=_token]').value);
    return fetch(@json(route('supplier.team.check')), { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        checked = v; ok = d.state === 'existing' || d.state === 'new';
        sync();
        show(ok ? 'ok' : 'bad', d.message + (ok && !anyRole() ? ' Now tick at least one role.' : ''));
      })
      .catch(function () { checked = ''; ok = false; sync(); show('bad', 'Could not check the email. Please try again.'); });
  }
  // the order roles are ticked in decides the main role
  var order = [];
  Array.prototype.forEach.call(boxes, function (b) {
    b.addEventListener('change', function () {
      order = order.filter(function (x) { return x !== b.value; });
      if (b.checked) order.push(b.value);
      sync();
    });
  });
  f.addEventListener('submit', function () {
    Array.prototype.forEach.call(boxes, function (b) { b.disabled = true; });
    order.forEach(function (v) { var h = document.createElement('input'); h.type = 'hidden'; h.name = 'roles[]'; h.value = v; f.appendChild(h); });
  });
  btn.addEventListener('click', check);
  em.addEventListener('blur', check);
  em.addEventListener('input', function () { if (em.value.trim().toLowerCase() !== checked) { ok = false; sync(); show('', base); } });
  em.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); check().then(function () { if (ok && anyRole()) f.requestSubmit ? f.requestSubmit() : f.submit(); }); } });
  if (em.value) check();
})();
</script>
@endpush

@foreach($members as $m)
  @continue($m['role'] === 'org_admin')
  @php($mem = $m['member'])
  <div class="modal-backdrop hidden" id="empRole{{ $m['user']->id }}"><div class="modal" role="dialog" aria-modal="true"><div class="modal-head"><h3>Roles · {{ $m['user']->name }}</h3><button class="icon-btn" data-close aria-label="Close">{{ icon('x') }}</button></div>
    <form method="POST" action="{{ route('supplier.team.update', $m['user']) }}">@csrf @method('PUT')<div class="modal-body">
      <div class="field"><label>Main role <span class="muted small">(opens first in Business Mode)</span></label><select class="input" name="roles[]">@foreach($scope['roles'] as $r)<option value="{{ $r }}" @selected($r === $mem->role)>{{ $R[$r] }}</option>@endforeach</select></div>
      <div class="field"><label>Also works as</label>
        @foreach($scope['roles'] as $r)
          <label class="check mb-1"><input type="checkbox" name="roles[]" value="{{ $r }}" @checked($r !== $mem->role && in_array($r, $m['roles'], true))> <span><b>{{ $R[$r] }}</b><span class="small muted" style="display:block">{{ \App\Models\ShopStaff::hints($s)[$r] }}</span></span></label>
        @endforeach
      </div>
    </div>
      @if($multi)
        <input type="hidden" name="branches_set" value="1">
        <div class="field"><label>Branches</label>
          @foreach($branches as $b)<label class="check mb-1"><input type="checkbox" name="branches[]" value="{{ $b->id }}" @checked(in_array($b->id, array_map('intval', (array) $mem->branch_ids), true))> <span>{{ $b->name }}</span></label>@endforeach
          <div class="hint">{{ $scope['branches'] === null ? 'None ticked = every branch (organization level).' : 'Only your branches are listed.' }}</div>
        </div>
      @endif
    </div>
    <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Save</button></div></form>
  </div></div>

  @php($have = $mem->perms())
  @php($def = $mem->rolePerms())
  <div class="modal-backdrop hidden" id="empPerm{{ $m['user']->id }}"><div class="modal" role="dialog" aria-modal="true"><div class="modal-head"><h3>Permissions · {{ $m['user']->name }}</h3><button class="icon-btn" data-close aria-label="Close">{{ icon('x') }}</button></div>
    <form method="POST" action="{{ route('supplier.team.update', $m['user']) }}">@csrf @method('PUT')<input type="hidden" name="perms_set" value="1"><div class="modal-body">
      <p class="small muted">Starts from the defaults of <b>{{ $mem->label() }}</b>. Tick or untick to change what this person may do. Pages they gain show in their menu under "Also allowed".</p>
      @foreach($P as $k => $l)
        @continue(in_array($k, \App\Models\ShopStaff::OWNER_ONLY, true))
        @php($isDef = in_array($k, $def, true))
        @php($on = in_array($k, $have, true))
        <label class="check mb-1"><input type="checkbox" name="perms[]" value="{{ $k }}" @checked($on)> <span>{{ $l }} @if($isDef && ! $on)<span class="badge b-red plain">removed</span>@elseif(! $isDef && $on)<span class="badge b-gold plain">added</span>@elseif($isDef)<span class="small muted">· role default</span>@endif</span></label>
      @endforeach
    </div>
    <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Save permissions</button></div></form>
  </div></div>
@endforeach
@endsection
