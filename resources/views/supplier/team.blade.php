@extends('layouts.dash', ['title' => 'Team & Roles', 'nav' => 'team'])
@php($R = \App\Models\ShopStaff::ORG_ROLES)
@section('content')
<div class="page-head"><div><h1>Team & Roles</h1><p>@if($s->type === 'organization')Your organization's employee accounts. Everyone works under <b>{{ $s->name }}</b> and only sees this shop's data.@else Employee accounts are <b>optional</b> for individual suppliers — you can handle payments and inventory yourself. Add a cashier or inventory staff when your business grows.@endif</p></div><button class="btn btn-primary" data-modal="empAdd">{{ icon('plus') }}Add employee</button></div>

<div class="card pad-0 mb-3"><div class="table-wrap"><table class="table cards"><thead><tr><th>Member</th><th>Role</th><th>Status</th><th>Added</th><th></th></tr></thead><tbody>
@foreach($members as $m)
  <tr>
    <td data-label="Member"><div class="row"><span class="avatar">{{ $m['user']->initials() }}</span><div><b>{{ $m['user']->name }}</b><div class="small muted">{{ $m['user']->email }}</div></div></div></td>
    <td data-label="Role"><span class="badge {{ \App\Models\ShopStaff::ROLE_BADGE[$m['role']] ?? 'b-gray' }}">{{ $R[$m['role']] ?? $m['role'] }}</span></td>
    <td data-label="Status"><span class="badge {{ $m['user']->status === 'active' ? 'b-green' : 'b-gray' }}">{{ ucfirst($m['user']->status) }}</span></td>
    <td data-label="Added"><span class="small">{{ fmt_date($m['user']->created_at?->toDateString()) }}</span></td>
    <td data-label="">@if($m['role'] === 'org_admin')<span class="small muted">Account owner</span>@else
      <div class="btn-group">
        <button class="btn btn-xs" data-modal="empRole{{ $m['user']->id }}">Change role</button>
        <button class="btn btn-xs" data-modal="empPw{{ $m['user']->id }}">Reset password</button>
        @php($on = $m['user']->status !== 'active')
        <form method="POST" action="{{ route('supplier.team.update', $m['user']) }}" data-confirm-title="{{ ($on ? 'Reactivate ' : 'Deactivate ').$m['user']->name }}?" data-confirm="{{ $on ? 'They can sign in again.' : 'They will no longer be able to sign in. Their past actions stay in the records.' }}" data-confirm-ok="{{ $on ? 'Reactivate' : 'Deactivate' }}" @if(! $on) data-danger @endif>@csrf @method('PUT')<input type="hidden" name="status" value="{{ $on ? 'active' : 'deactivated' }}"><button class="btn btn-xs {{ $on ? 'btn-success' : 'btn-ghost' }}">{{ $on ? 'Reactivate' : 'Deactivate' }}</button></form>
      </div>
    @endif</td>
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
  <form method="POST" action="{{ route('supplier.team.store') }}" novalidate>@csrf<div class="modal-body">
    <div class="field"><label for="em-name">Full name</label><input class="input" id="em-name" name="name" required minlength="3" value="{{ old('name') }}"></div>
    <div class="form-grid"><div class="field"><label for="em-email">Email (their login)</label><input class="input" id="em-email" type="email" name="email" required value="{{ old('email') }}"></div><div class="field"><label for="em-phone">Contact number</label><input class="input" id="em-phone" name="phone" data-phone value="{{ old('phone') }}"></div></div>
    <div class="field"><label for="em-role">Role</label><select class="input" id="em-role" name="role">@foreach(\App\Models\ShopStaff::EMPLOYEE_ROLES as $r)<option value="{{ $r }}" @selected(old('role') === $r)>{{ $R[$r] }}</option>@endforeach</select><div class="hint">Cashier: payments & receipts · Inventory Staff: products, stock & locations · Staff: assigned operations (deliveries, returns).</div></div>
    <div class="field"><label for="em-pw">Temporary password</label><input class="input" id="em-pw" name="password" required minlength="8" value="{{ old('password', $tempPw) }}"></div>
  </div><div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Create account</button></div></form>
</div></div>

@foreach($members as $m)
  @continue($m['role'] === 'org_admin')
  <div class="modal-backdrop hidden" id="empRole{{ $m['user']->id }}"><div class="modal sm" role="dialog" aria-modal="true"><div class="modal-head"><h3>Change role · {{ $m['user']->name }}</h3><button class="icon-btn" data-close aria-label="Close">{{ icon('x') }}</button></div>
    <form method="POST" action="{{ route('supplier.team.update', $m['user']) }}">@csrf @method('PUT')<div class="modal-body"><div class="field"><label>Role</label><select class="input" name="role">@foreach(\App\Models\ShopStaff::EMPLOYEE_ROLES as $r)<option value="{{ $r }}" @selected($r === $m['role'])>{{ $R[$r] }}</option>@endforeach</select></div></div>
    <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Save</button></div></form>
  </div></div>
  <div class="modal-backdrop hidden" id="empPw{{ $m['user']->id }}"><div class="modal sm" role="dialog" aria-modal="true"><div class="modal-head"><h3>Reset password · {{ $m['user']->name }}</h3><button class="icon-btn" data-close aria-label="Close">{{ icon('x') }}</button></div>
    <form method="POST" action="{{ route('supplier.team.update', $m['user']) }}">@csrf @method('PUT')<div class="modal-body"><div class="field"><label>New temporary password (8+ characters)</label><input class="input" name="password" required minlength="8" value="{{ $tempPw }}"></div></div>
    <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Reset</button></div></form>
  </div></div>
@endforeach
@endsection
