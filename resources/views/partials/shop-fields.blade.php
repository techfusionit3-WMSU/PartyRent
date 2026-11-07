{{-- Supplier profile fields, shared by Start Selling (wizard steps) and Shop Profile. $d = attributes, $p = policy --}}
@php
    $o = fn ($k, $def = null) => old($k, $d[$k] ?? $def);
    $po = fn ($k) => old($k, $p[$k] ?? null);
    $methods = old('methods', $d['methods'] ?? []);
    $arrs = old('arrangements', $d['arrangements'] ?? []);
    $err = fn ($k) => $errors->has($k) ? '<div class="error-text" style="display:block">'.e($errors->first($k)).'</div>' : '';
@endphp
<section data-step="0">
  <h2>Business info</h2>
  <div class="field"><label>Business name</label><input class="input" name="name" required minlength="3" value="{{ $o('name') }}" placeholder="e.g. Santos Party Rentals">{!! $err('name') !!}</div>
  <div class="field"><label>Business description</label><textarea class="input" name="description" required minlength="20" placeholder="What do you rent out, and for which events?">{{ $o('description') }}</textarea>{!! $err('description') !!}</div>
  <div class="field"><label>Business logo</label><div class="row wrap gap-sm">@foreach($logos as $l)<label class="chip {{ $o('logo', '🎈') === $l ? 'active' : '' }}" style="font-size:1.3rem"><input type="radio" name="logo" value="{{ $l }}" @checked($o('logo', '🎈') === $l) hidden>{{ $l }}</label>@endforeach</div></div>
</section>
<section data-step="1">
  <h2>Contact</h2>
  <div class="form-grid">
    <div class="field"><label>Contact person</label><input class="input" name="contact_person" required value="{{ $o('contact_person') }}">{!! $err('contact_person') !!}</div>
    <div class="field"><label>Contact number</label><input class="input" name="phone" required value="{{ $o('phone') }}">{!! $err('phone') !!}</div>
    <div class="field"><label>Business email</label><input class="input" type="email" name="email" required value="{{ $o('email') }}">{!! $err('email') !!}</div>
    <div class="field"><label>City / municipality</label><select class="input" name="city" required><option value="">Select…</option>@foreach($cities as $c)<option @selected($o('city') === $c)>{{ $c }}</option>@endforeach</select>{!! $err('city') !!}</div>
    <div class="field full"><label>Business address</label><input class="input" name="address" required minlength="8" value="{{ $o('address') }}">{!! $err('address') !!}</div>
    <div class="field full"><label>Operating hours</label><input class="input" name="hours" required value="{{ $o('hours') }}"></div>
  </div>
</section>
<section data-step="2">
  <h2>Fulfillment</h2>
  <div class="stack-sm">@foreach([['delivery', 'Delivery only', 'You bring items to the customer.'], ['pickup', 'Pickup only', 'Customers collect from your location.'], ['both', 'Both delivery and pickup', 'Let customers choose.']] as [$v, $l, $desc])
    <label class="card card-flat row" style="padding:12px 14px;cursor:pointer"><input type="radio" name="fulfillment" value="{{ $v }}" @checked($o('fulfillment', 'both') === $v) style="accent-color:var(--brand);width:18px;height:18px"><div><b>{{ $l }}</b><div class="small muted">{{ $desc }}</div></div></label>
  @endforeach</div>
  <div class="field mt-2"><label>Delivery fee (₱ per trip)</label><input class="input" type="number" min="0" name="delivery_fee" value="{{ $o('delivery_fee', 0) }}"><div class="hint">Ignored for pickup-only shops.</div></div>
</section>
<section data-step="3">
  <h2>Payments</h2>
  <div class="field"><label>Accepted payment methods</label>@foreach(['cod' => 'Cash on Delivery', 'bank' => 'Online Bank Transfer', 'debit' => 'Debit'] as $v => $l)<label class="check"><input type="checkbox" name="methods[]" value="{{ $v }}" @checked(in_array($v, $methods, true))> {{ $l }}</label>@endforeach{!! $err('methods') !!}</div>
  <div class="field"><label>Payment arrangements you offer</label>@foreach(\App\Models\Payment::ARR_LABEL as $v => $l)<label class="check"><input type="checkbox" name="arrangements[]" value="{{ $v }}" @checked(in_array($v, $arrs, true))> {{ $l }}</label>@endforeach{!! $err('arrangements') !!}</div>
  <div class="form-grid">
    <div class="field"><label>Reservation fee (₱, 0 = none)</label><input class="input" type="number" min="0" name="reservationFee" value="{{ $po('reservationFee') }}">{!! $err('reservationFee') !!}</div>
    <div class="field"><label>Reservation fee is…</label><select class="input" name="reservationCredited"><option value="1" @selected((string) (int) $po('reservationCredited') === '1')>Credited toward the down payment</option><option value="0" @selected((string) (int) $po('reservationCredited') === '0')>An additional charge</option></select></div>
    <div class="field"><label>Down payment type</label><select class="input" name="downType"><option value="percent" @selected($po('downType') === 'percent')>Percentage of rental</option><option value="fixed" @selected($po('downType') === 'fixed')>Fixed amount</option></select></div>
    <div class="field"><label>Down payment value</label><input class="input" type="number" min="0" name="downValue" value="{{ $po('downValue') }}">{!! $err('downValue') !!}</div>
    <div class="field"><label>Number of installments</label><input class="input" type="number" min="2" max="6" name="installments" value="{{ $po('installments') }}"></div>
    <div class="field"><label>Security deposit</label><div class="row" style="gap:6px"><select class="input" name="depositType" style="width:120px"><option value="fixed" @selected($po('depositType') === 'fixed')>₱ fixed</option><option value="percent" @selected($po('depositType') === 'percent')>% of items</option></select><input class="input" type="number" min="0" name="depositValue" value="{{ $po('depositValue') }}"></div>{!! $err('depositValue') !!}</div>
  </div>
</section>
<section data-step="4">
  <h2>Policies</h2>
  <div class="field"><label>Free cancellation window (days before rental)</label><input class="input" type="number" min="0" max="30" name="cancelDays" value="{{ $po('cancelDays') }}"></div>
  <div class="field"><label>Cancellation policy</label><textarea class="input" name="cancellation" required>{{ $po('cancellation') }}</textarea></div>
  <div class="field"><label>Return policy</label><textarea class="input" name="returns" required>{{ $po('returns') }}</textarea></div>
  <div class="field"><label>Rental conditions</label><textarea class="input" name="conditions" required>{{ $po('conditions') }}</textarea></div>
</section>
