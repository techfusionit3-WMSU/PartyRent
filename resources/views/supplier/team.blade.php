@extends('layouts.dash', ['title' => 'Team & Roles', 'nav' => 'team'])
@php($R = \App\Models\ShopStaff::ORG_ROLES)
@section('content')
<div class="page-head"><div><h1>Team & Roles</h1><p>@if($s->type === 'organization')Your organization's employees. Each uses their own PartyRent account and switches to <b>Business Mode</b> to work. Everyone works under <b>{{ $s->name }}</b> and only sees this shop's data.@else Employee accounts are <b>optional</b> for individual suppliers — you can handle payments and inventory yourself. Add a cashier or inventory staff when your business grows.@endif</p></div><button class="btn btn-primary" data-modal="empAdd">{{ icon('plus') }}Add employee</button></div>

<div class="card pad-0 mb-3"><div class="table-wrap"><table class="table cards"><thead><tr><th>Member</th><th>Role</th><th>Status</th><th>Added</th><th></th></tr></thead><tbody>
@foreach($members as $m)
  <tr>
    <td data-label="Member"><div class="row"><span class="avatar">{{ $m['user']->initials() }}</span><div><b>{{ $m['user']->name }}</b><div class="small muted">{{ $m['user']->email }}</div></div></div></td>
    <td data-label="Role"><span class="badge {{ \App\Models\ShopStaff::ROLE_BADGE[$m['role']] ?? 'b-gray' }}">{{ $R[$m['role']] ?? $m['role'] }}</span></td>
    <td data-label="Status"><span class="badge {{ $m['status'] === 'active' ? 'b-green' : 'b-gray' }}">{{ ucfirst($m['status']) }}</span></td>
    <td data-label="Added"><span class="small">{{ fmt_date($m['since']?->toDateString()) }}</span></td>
    <td data-label="">@if($m['role'] === 'org_admin')<span class="small muted">Account owner</span>@else
      <div class="btn-group">
        <button class="btn btn-xs" data-modal="empRole{{ $m['user']->id }}">Change role</button>
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
    <td data-label="Role"><span class="badge {{ \App\Models\ShopStaff::ROLE_BADGE[$i->role] ?? 'b-gray' }}">{{ $i->roleLabel() }}</span></td>
    <td data-label="Status">@if($expired)<span class="badge b-gray">Expired</span>@else<span class="badge b-gold">Invited · waiting</span>@endif</td>
    <td data-label="Added"><span class="small">{{ fmt_date($i->last_sent_at->toDateString()) }}</span></td>
    <td data-label=""><div class="btn-group">
      <form method="POST" action="{{ route('supplier.team.resend', $i) }}">@csrf<button class="btn btn-xs">Resend</button></form>
      <form method="POST" action="{{ route('supplier.team.cancel', $i) }}" data-confirm-title="Cancel the invitation to {{ $i->email }}?" data-confirm="The link in their email stops working." data-confirm-ok="Cancel invitation" data-danger>@csrf @method('DELETE')<button class="btn btn-xs btn-ghost">Cancel</button></form>
    </div></td>
  </tr>
@endforeach
</tbody></table></div></div>

<div class="card"><h3>What each role can do</h3><div class="table-wrap"><table class="table"><thead><tr><th>Permission</th>@foreach($R as $l)<th class="center">{{ $l }}</th>@endforeach</tr></thead><tbody>
@foreach(\App\Models\ShopStaff::ORG_PERM_LIST as $k => $l)
  <tr><td>{{ $l }}</td>@foreach(array_keys($R) as $r)<td class="center">@if(in_array($k, \App\Models\ShopStaff::permsOf($r), true))<span class="perm-yes">✓</span>@else<span class="perm-no">—</span>@endif</td>@endforeach</tr>
@endforeach
</tbody></table></div><p class="small muted mb-0 mt-1">Cashiers handle this shop's payments, receipts and financial reports only — they cannot manage other suppliers, platform settings, customer accounts or organization administrators. Platform administration stays with PartyRent.</p></div>

{{-- Add employee --}}
<div class="modal-backdrop {{ $errors->any() ? '' : 'hidden' }}" id="empAdd"><div class="modal" role="dialog" aria-modal="true"><div class="modal-head"><h3>Add employee</h3><button class="icon-btn" data-close aria-label="Close">{{ icon('x') }}</button></div>
  <form method="POST" action="{{ route('supplier.team.store') }}" id="empForm" novalidate>@csrf<div class="modal-body">
    @error('team')<div class="alert alert-danger mb-2">{{ icon('alert') }}<div>{{ $message }}</div></div>@enderror
    <div class="field"><label for="em-email">Their email</label>
      <div class="row" style="gap:8px"><input class="input" id="em-email" type="email" name="email" required value="{{ old('email') }}" autocomplete="off" style="flex:1"><button type="button" class="btn" id="emCheck">Check</button></div>
      <div class="hint" id="emHint">We check the email first. An account that already works for another business can't be added. A new email gets a code to create its own account.</div>
    </div>
    <div class="field"><label for="em-role">Role</label><select class="input" id="em-role" name="role">@foreach(\App\Models\ShopStaff::EMPLOYEE_ROLES as $r)<option value="{{ $r }}" @selected(old('role') === $r)>{{ $R[$r] }}</option>@endforeach</select><div class="hint">Cashier: payments & receipts · Inventory Staff: products, stock & locations · Staff: assigned operations (deliveries, returns).</div></div>
    <p class="small muted mb-0">Nobody joins until they confirm from their inbox. The invitation expires in {{ \App\Models\StaffInvite::TTL_HOURS }} hours.</p>
  </div><div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary" id="emSend" disabled>{{ icon('send') }}Send invitation</button></div></form>
</div></div>
@push('scripts')
<script>
(function () {
  var f = document.getElementById('empForm'), em = document.getElementById('em-email'), hint = document.getElementById('emHint'), send = document.getElementById('emSend'), btn = document.getElementById('emCheck');
  var checked = '', ok = false, base = hint.textContent;
  function show(cls, msg) { hint.className = 'hint'; hint.style.color = cls === 'ok' ? 'var(--green)' : cls === 'bad' ? 'var(--red)' : ''; hint.style.fontWeight = cls ? '600' : ''; hint.textContent = msg; }
  function check() {
    var v = em.value.trim().toLowerCase();
    if (!v) { ok = false; send.disabled = true; show('', base); return Promise.resolve(); }
    if (v === checked) return Promise.resolve();
    show('', 'Checking…');
    var fd = new FormData(); fd.append('email', v); fd.append('_token', f.querySelector('[name=_token]').value);
    return fetch(@json(route('supplier.team.check')), { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        checked = v; ok = d.state === 'existing' || d.state === 'new';
        send.disabled = !ok;
        show(ok ? 'ok' : 'bad', d.message);
      })
      .catch(function () { checked = ''; ok = false; send.disabled = true; show('bad', 'Could not check the email. Please try again.'); });
  }
  btn.addEventListener('click', check);
  em.addEventListener('blur', check);
  em.addEventListener('input', function () { if (em.value.trim().toLowerCase() !== checked) { ok = false; send.disabled = true; show('', base); } });
  em.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); check().then(function () { if (ok) f.requestSubmit ? f.requestSubmit() : f.submit(); }); } });
  if (em.value) check();
})();
</script>
@endpush

@foreach($members as $m)
  @continue($m['role'] === 'org_admin')
  <div class="modal-backdrop hidden" id="empRole{{ $m['user']->id }}"><div class="modal sm" role="dialog" aria-modal="true"><div class="modal-head"><h3>Change role · {{ $m['user']->name }}</h3><button class="icon-btn" data-close aria-label="Close">{{ icon('x') }}</button></div>
    <form method="POST" action="{{ route('supplier.team.update', $m['user']) }}">@csrf @method('PUT')<div class="modal-body"><div class="field"><label>Role</label><select class="input" name="role">@foreach(\App\Models\ShopStaff::EMPLOYEE_ROLES as $r)<option value="{{ $r }}" @selected($r === $m['role'])>{{ $R[$r] }}</option>@endforeach</select></div></div>
    <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Save</button></div></form>
  </div></div>
@endforeach
@endsection
