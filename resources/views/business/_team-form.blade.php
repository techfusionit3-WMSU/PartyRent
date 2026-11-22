{{-- Add / edit a team. $t EventTeam|null, $people (owner + active members) --}}
@php($fid = $t?->id ?? 'new')
<form method="POST" action="{{ $t ? route('business.teams.update', $t) : route('business.teams.store') }}" class="mt-1">@csrf
  <div class="field"><label for="tm-n-{{ $fid }}">Team name</label><input class="input" id="tm-n-{{ $fid }}" name="name" required minlength="2" maxlength="60" value="{{ $t?->name }}" placeholder="e.g. Team A"></div>
  <div class="field"><label>Who is in it</label>
    <div class="row wrap gap-sm">@foreach($people as $u)<label class="check card card-flat" style="padding:6px 10px"><input type="checkbox" name="members[]" value="{{ $u->id }}" @checked(in_array($u->id, (array) ($t?->members ?? []), true))> {{ $u->name }}</label>@endforeach</div>
    <div class="hint">People come from <a href="{{ route('supplier.team') }}">Team & Roles</a>.</div></div>
  @if($t)<label class="check mb-2"><input type="hidden" name="active" value="0"><input type="checkbox" name="active" value="1" @checked($t->active)> Working (takes events)</label>@endif
  <button class="btn btn-primary btn-sm">{{ icon('check') }}{{ $t ? 'Save team' : 'Add team' }}</button>
</form>
