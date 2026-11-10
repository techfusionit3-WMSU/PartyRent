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
    <div class="field"><label>Operating hours</label><input class="input" name="hours" required value="{{ $o('hours') }}"></div>
  </div>
  @include('partials.location-fields')
</section>
<section data-step="2">
  <h2>Fulfillment</h2>
  <div class="stack-sm">@foreach([['delivery', 'Delivery only', 'You bring items to the customer.'], ['pickup', 'Pickup only', 'Customers collect from your location.'], ['both', 'Both delivery and pickup', 'Let customers choose.']] as [$v, $l, $desc])
    <label class="card card-flat row" style="padding:12px 14px;cursor:pointer"><input type="radio" name="fulfillment" value="{{ $v }}" @checked($o('fulfillment', 'both') === $v) style="accent-color:var(--brand);width:18px;height:18px"><div><b>{{ $l }}</b><div class="small muted">{{ $desc }}</div></div></label>
  @endforeach</div>{!! $err('fulfillment') !!}
  @include('partials.delivery-settings', ['dl' => $d['delivery'] ?? null, 'previewCity' => $previewCity ?? null, 'freeShip' => (bool) ($d['free_ship'] ?? false)])
</section>
<section data-step="3">
  <h2>Payments</h2>
  <div class="field"><label>Accepted payment methods</label>@foreach(['cod' => 'Cash on Delivery', 'bank' => 'Online Bank Transfer', 'debit' => 'Debit'] as $v => $l)<label class="check"><input type="checkbox" name="methods[]" value="{{ $v }}" @checked(in_array($v, $methods, true))> {{ $l }}</label>@endforeach{!! $err('methods') !!}</div>
  <div class="field" id="payArr"><label>Payment arrangements you offer</label>
    <div class="arr-list">@foreach(\App\Models\Payment::ARR_LABEL as $v => $l)
      <div class="arr-opt">
        <label class="check"><input type="checkbox" name="arrangements[]" value="{{ $v }}" data-arr="{{ $v }}" @checked(in_array($v, $arrs, true))> {{ $l }}</label>
        @if($v === 'down')
        <div class="arr-panel" data-arr-panel="down"><div><div class="pay-box">
          <div class="field" data-amount="down" data-sample-label="rental">
            <label>Down payment</label>
            <input type="hidden" name="downType" value="{{ $po('downType') === 'fixed' ? 'fixed' : 'percent' }}" data-amount-type>
            <div class="seg pay-seg" role="group" aria-label="Down payment type"><button type="button" data-type="percent">Percentage of the rental</button><button type="button" data-type="fixed">Fixed amount (₱)</button></div>
            <div class="affix"><span class="affix-pre">₱</span><input class="input" type="number" min="0" step="any" name="downValue" value="{{ $po('downValue') }}" data-amount-value data-min-positive aria-label="Down payment value"><span class="affix-post">%</span></div>
            <div class="hint">How much the customer pays upfront to confirm the booking. The rest is paid later.</div>
            <div class="pay-example" data-example aria-live="polite"></div>
            <div class="error-text" data-msg></div>{!! $err('downValue') !!}
          </div>
        </div></div></div>
        @elseif($v === 'installment')
        <div class="arr-panel" data-arr-panel="installment"><div><div class="pay-box">
          <div class="field" style="margin:0"><label>Number of installments</label>
            <input class="input" type="number" min="2" max="6" step="1" name="installments" value="{{ $po('installments') }}" style="max-width:140px" data-c-required>
            <div class="hint">The customer splits the rental into 2 to 6 equal payments.</div>
            <div class="error-text">Enter a number from 2 to 6.</div>{!! $err('installments') !!}
          </div>
        </div></div></div>
        @elseif($v === 'reservation')
        <div class="arr-panel" data-arr-panel="reservation"><div><div class="pay-box">
          <div class="field"><label>Reservation fee (₱)</label>
            <div class="affix" style="max-width:220px"><span class="affix-pre">₱</span><input class="input" type="number" min="0" step="any" name="reservationFee" value="{{ $po('reservationFee') }}" data-min-positive></div>
            <div class="hint">A small amount the customer pays to hold their dates.</div>
            <div class="error-text">Enter a reservation fee greater than ₱0.</div>{!! $err('reservationFee') !!}
          </div>
          <div class="field" style="margin:0"><label>Reservation fee is…</label>
            <div class="radio-cards">@foreach(['1' => ['Deducted from the down payment', "The customer's reservation fee counts toward what they pay upfront."], '0' => ['Charged on top', 'The reservation fee is an extra, separate charge (non-refundable).']] as $rv => [$rl, $rd])
              <label class="radio-card"><input type="radio" name="reservationCredited" value="{{ $rv }}" @checked((string) (int) $po('reservationCredited') === (string) $rv)><div><b>{{ $rl }}</b><div class="small muted">{{ $rd }}</div></div></label>
            @endforeach</div>
            <div class="pay-example" data-res-example aria-live="polite"></div>
          </div>
        </div></div></div>
        @endif
      </div>
    @endforeach</div>{!! $err('arrangements') !!}
  </div>
  <div class="pay-box mt-2">
    <div class="field" data-amount="deposit" style="margin:0">
      <label>Security deposit</label>
      <input type="hidden" name="depositType" value="{{ $po('depositType') === 'percent' ? 'percent' : 'fixed' }}" data-amount-type>
      <div class="seg pay-seg" role="group" aria-label="Security deposit type"><button type="button" data-type="fixed">₱ fixed</button><button type="button" data-type="percent">% of items</button></div>
      <div class="affix"><span class="affix-pre">₱</span><input class="input" type="number" min="0" step="any" name="depositValue" value="{{ $po('depositValue') }}" data-amount-value aria-label="Security deposit value"><span class="affix-post">%</span></div>
      <div class="hint">Refundable amount held in case items are damaged or missing. Returned after the items are checked.</div>
      <div class="pay-example" data-example aria-live="polite"></div>
      <div class="error-text" data-msg></div>{!! $err('depositValue') !!}
    </div>
  </div>
</section>
@once
@push('head')
<style>
.arr-list { display: flex; flex-direction: column; gap: 10px; }
.arr-opt > .check { padding: 2px 0; }
.arr-panel { display: grid; grid-template-rows: 1fr; opacity: 1; transition: grid-template-rows .22s ease, opacity .22s ease; }
.arr-panel > div { overflow: hidden; min-height: 0; }
.arr-panel.is-closed { grid-template-rows: 0fr; opacity: 0; visibility: hidden; transition: grid-template-rows .22s ease, opacity .18s ease, visibility 0s .22s; }
.arr-panel .pay-box { margin: 8px 0 4px 26px; }
.pay-box { border: 1px solid var(--line); border-left: 3px solid var(--brand-100); background: var(--surface-2); border-radius: var(--radius-sm); padding: 14px 16px; }
.pay-box .field:last-child { margin-bottom: 0; }
.pay-seg { align-self: flex-start; flex-wrap: wrap; border-radius: var(--radius-sm); background: var(--surface); border: 1px solid var(--line); }
.pay-seg button { border-radius: 8px; white-space: normal; text-align: left; }
.pay-seg button.active { background: var(--brand-50); }
.affix { position: relative; max-width: 220px; }
.affix .input { padding-left: 30px; padding-right: 34px; }
.affix-pre, .affix-post { position: absolute; top: 50%; transform: translateY(-50%); color: var(--muted); font-weight: 600; pointer-events: none; }
.affix-pre { left: 12px; } .affix-post { right: 12px; }
.affix[data-mode="percent"] .affix-pre, .affix[data-mode="fixed"] .affix-post { display: none; }
.affix[data-mode="percent"] .input { padding-left: 14px; }
.affix[data-mode="fixed"] .input { padding-right: 14px; }
.pay-example { font-size: .84rem; color: var(--ink-2); background: var(--surface); border: 1px dashed var(--line-2); border-radius: 8px; padding: 8px 10px; }
.pay-example:empty { display: none; }
.radio-cards { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; }
.radio-card { display: flex; gap: 10px; align-items: flex-start; padding: 10px 12px; border: 1.5px solid var(--line-2); border-radius: var(--radius-sm); background: var(--surface); cursor: pointer; }
.radio-card input { accent-color: var(--brand); width: 18px; height: 18px; margin: 2px 0 0; flex: none; }
.radio-card:has(input:checked) { border-color: var(--brand); background: var(--brand-50); }
@media (max-width: 560px) { .radio-cards { grid-template-columns: 1fr; } .arr-panel .pay-box { margin-left: 0; } .affix { max-width: none; } }
@media (prefers-reduced-motion: reduce) { .arr-panel, .arr-panel.is-closed { transition: none; } }
</style>
@endpush
@push('scripts')
<script>
(function () {
  var sec = document.querySelector('section[data-step="3"]'); if (!sec) return;
  var SAMPLE = 5000;
  var peso = function (n) { return '₱' + (Math.round(n * 100) / 100).toLocaleString('en-PH', { maximumFractionDigits: 2 }); };
  var num = function (el) { var v = parseFloat(el && el.value); return isFinite(v) ? v : 0; };

  /* Open/close each arrangement's settings with its checkbox. Closed panels lose their
     constraint attributes (so neither the wizard nor native validation checks them) but keep their values. */
  function setPanel(p, open) {
    p.classList.toggle('is-closed', !open);
    p.setAttribute('aria-hidden', open ? 'false' : 'true');
    p.querySelectorAll('input').forEach(function (i) {
      ['min', 'max'].forEach(function (a) {
        if (!open && i.hasAttribute(a)) { i.dataset['c' + a] = i.getAttribute(a); i.removeAttribute(a); }
        if (open && i.dataset['c' + a] != null) { i.setAttribute(a, i.dataset['c' + a]); delete i.dataset['c' + a]; }
      });
      if (i.hasAttribute('data-c-required')) i.required = open;
      i.tabIndex = open ? 0 : -1;
      if (!open) { var fl = i.closest('.field'); if (fl) fl.classList.remove('invalid'); }
    });
    check();
  }
  sec.querySelectorAll('[data-arr]').forEach(function (cb) {
    var p = sec.querySelector('[data-arr-panel="' + cb.dataset.arr + '"]');
    if (!p) return;
    setPanel(p, cb.checked);
    cb.addEventListener('change', function () { setPanel(p, cb.checked); });
  });

  /* "Must be more than 0" for fields whose arrangement is ticked (custom validity, so both the wizard and the browser respect it). */
  function check() {
    sec.querySelectorAll('[data-min-positive]').forEach(function (i) {
      var closed = i.closest('.is-closed');
      i.setCustomValidity(!closed && !(num(i) > 0) ? 'Must be more than 0.' : '');
    });
  }

  /* Amount editors: segmented type toggle + value with ₱ / % affix + live example. */
  sec.querySelectorAll('[data-amount]').forEach(function (box) {
    var kind = box.dataset.amount, type = box.querySelector('[data-amount-type]'), val = box.querySelector('[data-amount-value]');
    var affix = box.querySelector('.affix'), ex = box.querySelector('[data-example]'), msg = box.querySelector('[data-msg]');
    function draw() {
      var pct = type.value === 'percent', v = num(val);
      box.querySelectorAll('[data-type]').forEach(function (b) { var on = b.dataset.type === type.value; b.classList.toggle('active', on); b.setAttribute('aria-pressed', on ? 'true' : 'false'); });
      affix.dataset.mode = pct ? 'percent' : 'fixed';
      if (!val.closest('.is-closed')) { if (pct) val.max = 100; else val.removeAttribute('max'); }
      else if (pct) val.dataset.cmax = 100; else delete val.dataset.cmax;
      val.placeholder = pct ? 'e.g. 30' : 'e.g. 1000';
      if (msg) msg.textContent = pct ? 'Enter a percentage from 1 to 100.' : 'Enter an amount greater than ₱0.';
      if (kind === 'deposit' && msg) msg.textContent = pct ? 'Enter a percentage from 0 to 100.' : 'Enter an amount of ₱0 or more.';
      var amt = pct ? SAMPLE * Math.min(v, 100) / 100 : v;
      if (kind === 'down') {
        ex.textContent = v <= 0 ? '' : amt >= SAMPLE
          ? 'Example: on a ' + peso(SAMPLE) + ' rental, the customer pays the full ' + peso(SAMPLE) + ' now.'
          : 'Example: on a ' + peso(SAMPLE) + ' rental, the customer pays ' + peso(amt) + ' now and ' + peso(SAMPLE - amt) + ' later.';
      } else {
        ex.textContent = v <= 0 ? 'No deposit: nothing is held.' : 'On ' + peso(SAMPLE) + ' of items, you hold ' + peso(amt) + ' and return it after inspection.';
      }
      resExample(); check();
    }
    box.addEventListener('click', function (e) { var b = e.target.closest('[data-type]'); if (!b || type.value === b.dataset.type) return; type.value = b.dataset.type; draw(); val.focus(); });
    val.addEventListener('input', draw);
    draw();
  });

  /* Reservation example reacts to the fee, the radio choice and the down payment. */
  var fee = sec.querySelector('[name="reservationFee"]'), resEx = sec.querySelector('[data-res-example]');
  function resExample() {
    if (!resEx || !fee) return;
    var f = num(fee), cr = sec.querySelector('[name="reservationCredited"]:checked');
    if (f <= 0) { resEx.textContent = 'Enter a fee above to see an example.'; return; }
    if (!cr || cr.value === '1') {
      var dt = sec.querySelector('[name="downType"]').value, dv = num(sec.querySelector('[name="downValue"]'));
      var down = sec.querySelector('[data-arr="down"]').checked && dv > 0 ? Math.min(SAMPLE, dt === 'percent' ? SAMPLE * Math.min(dv, 100) / 100 : dv) : 0;
      resEx.textContent = down > f
        ? 'Example: with a ' + peso(down) + ' down payment, the customer pays ' + peso(f) + ' to reserve, then ' + peso(down - f) + ' more upfront. The ' + peso(SAMPLE) + ' total stays the same.'
        : 'Example: the customer pays ' + peso(f) + ' to reserve. It counts toward the ' + peso(SAMPLE) + ' rental, so the total stays ' + peso(SAMPLE) + '.';
    } else {
      resEx.textContent = 'Example: the customer pays ' + peso(f) + ' to reserve, plus the full ' + peso(SAMPLE) + ' rental: ' + peso(SAMPLE + f) + ' in total.';
    }
  }
  if (fee) fee.addEventListener('input', function () { resExample(); check(); });
  sec.addEventListener('change', function (e) { if (e.target.name === 'reservationCredited' || e.target.matches('[data-arr]')) resExample(); });
  resExample(); check();
})();
</script>
@endpush
@endonce
<section data-step="4">
  <h2>Policies</h2>
  <div class="field"><label>Free cancellation window (days before rental)</label><input class="input" type="number" min="0" max="30" name="cancelDays" value="{{ $po('cancelDays') }}"></div>
  <div class="field"><label>Cancellation policy</label><textarea class="input" name="cancellation" required>{{ $po('cancellation') }}</textarea></div>
  <div class="field"><label>Return policy</label><textarea class="input" name="returns" required>{{ $po('returns') }}</textarea></div>
  <div class="field"><label>Rental conditions</label><textarea class="input" name="conditions" required>{{ $po('conditions') }}</textarea></div>
</section>
