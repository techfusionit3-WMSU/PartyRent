<form class="card" method="POST" action="{{ route('account.update') }}">
  @csrf @method('PUT')
  <h3>Personal information</h3>
  @include('partials.person-fields', ['u' => $u, 'required' => ! $u->isStaff()])
  <div class="field @error('email') invalid @enderror"><label>Email address</label><input class="input" type="email" name="email" required value="{{ old('email', $u->email) }}">@error('email')<div class="error-text">{{ $message }}</div>@enderror</div>
  <div class="field @error('phone') invalid @enderror"><label>Contact number</label><input class="input" name="phone" required value="{{ old('phone', $u->phone) }}">@error('phone')<div class="error-text">{{ $message }}</div>@enderror</div>
  @if($u->isStaff())
    <div class="field @error('address') invalid @enderror"><label>Address</label><input class="input" name="address" required value="{{ old('address', $u->address) }}">@error('address')<div class="error-text">{{ $message }}</div>@enderror</div>
  @else
    <div class="label mb-1">Address <span class="muted small" style="text-transform:none;font-weight:400">· used for delivery estimates and as your default delivery address</span></div>
    @include('partials.address-fields', ['key' => 'addr', 'value' => $u->address_parts ?? [], 'full' => true, 'required' => true])
  @endif
  <button class="btn btn-primary">Save changes</button>
</form>
<form class="card" method="POST" action="{{ route('account.password') }}">
  @csrf @method('PUT')
  <h3>Change password</h3>
  <div class="field @error('cur') invalid @enderror"><label>Current password</label><input class="input" type="password" name="cur" required autocomplete="current-password">@error('cur')<div class="error-text">{{ $message }}</div>@enderror</div>
  <div class="field @error('np') invalid @enderror"><label for="np">New password</label><input class="input" id="np" type="password" name="np" required minlength="8" autocomplete="new-password">@error('np')<div class="error-text">{{ $message }}</div>@enderror</div>
  <div class="field"><label for="npc">Confirm new password</label><input class="input" id="npc" type="password" name="np_confirmation" required autocomplete="new-password"></div>
  @include('partials.password-rules', ['for' => 'np', 'confirm' => 'npc'])
  <button class="btn btn-primary">Update password</button>
</form>
