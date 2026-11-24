{{--
  Custom discounts for selected customers (family, regulars): private, never shown to other customers and never
  applied by themselves. The shop applies one on that customer's rental (rental page → "Customer discount").
  Vars: $custom (discounts with customer), $customers (people who rented from the shop), $canCustom.
--}}
@php($CK = \App\Domain\CustomDiscounts::KINDS)
<div class="card mb-3 custom-disc" id="custom">
  <div class="card-head"><h3>{{ icon('users') }} Custom discounts for selected customers <span class="badge b-purple plain">Private</span></h3>
    @if($canCustom)<button class="btn btn-sm btn-primary" data-modal="cdNew">{{ icon('plus') }}New custom discount</button>@endif</div>
  <p class="small muted mt-0">A discount for one person, like family, relatives or a loyal customer. Only that customer can get it, it is not shown to anyone else, and it is never added automatically: you add it to one of their rentals yourself, before they pay.</p>
  @unless($canCustom)<div class="alert alert-info mb-2">{{ icon('info') }}<div class="small">Only the shop owner can create or apply custom discounts.</div></div>@endunless
  @if($custom->isEmpty())
    @include('partials.empty', ['emoji' => '💝', 'title' => 'No custom discounts yet', 'text' => 'Example: "Family discount: 20% off the rental for my cousin Ana."'])
  @else
    <div class="table-wrap"><table class="table cards"><thead><tr><th>For</th><th>Discount</th><th>Takes off</th><th>Used</th><th>Valid</th><th>Status</th><th></th></tr></thead><tbody>
      @foreach($custom as $d)
        @php($st = ['running' => ['Can be used', 'b-green'], 'scheduled' => ['Not started', 'b-sky'], 'ended' => ['Ended', 'b-gray'], 'off' => ['Turned off', 'b-gray']][$d->state()])
        @php($usedUp = $d->max_uses && $d->used_count >= $d->max_uses)
        <tr>
          <td data-label="For"><b>{{ $d->customer?->name ?? 'Deleted account' }}</b><div class="small muted">{{ $d->customer?->email }}</div></td>
          <td data-label="Discount">{{ $d->name }}@if($d->note)<div class="small muted">{{ $d->note }}</div>@endif</td>
          <td data-label="Takes off"><b>{{ \App\Domain\CustomDiscounts::label($d) }}</b></td>
          <td data-label="Used">{{ $d->used_count }}{{ $d->max_uses ? ' of '.$d->max_uses : '' }} rental{{ ($d->max_uses ?: $d->used_count) === 1 ? '' : 's' }}</td>
          <td data-label="Valid">{{ $d->starts_on ? fmt_date($d->starts_on->format('Y-m-d')) : 'Now' }} – {{ $d->ends_on ? fmt_date($d->ends_on->format('Y-m-d')) : 'no end' }}</td>
          <td data-label="Status"><span class="badge {{ $usedUp ? 'b-gray' : $st[1] }}">{{ $usedUp ? 'Used up' : $st[0] }}</span></td>
          <td class="right">@if($canCustom)<div class="btn-group">
            <button class="btn btn-xs" data-modal="cd{{ $d->id }}">{{ icon('edit') }}Edit</button>
            <form method="POST" action="{{ route('supplier.discounts.custom.active', $d) }}">@csrf<button class="btn btn-xs">{{ $d->active ? 'Turn off' : 'Turn on' }}</button></form>
            @if(! $d->used_count)<form method="POST" action="{{ route('supplier.discounts.custom.delete', $d) }}" data-confirm-title="Delete {{ $d->name }}?" data-confirm="{{ $d->customer?->name }} won't be able to get it anymore." data-confirm-ok="Delete" data-danger>@csrf @method('DELETE')<button class="btn btn-xs btn-ghost" aria-label="Delete {{ $d->name }}">{{ icon('trash') }}</button></form>@endif
          </div>@endif</td>
        </tr>
      @endforeach
    </tbody></table></div>
  @endif
</div>

@if($canCustom)
@foreach($custom->prepend(new \App\Models\Discount(['kind' => 'order_percent'])) as $d)
  @php($id = $d->exists ? 'cd'.$d->id : 'cdNew')
  @php($old = fn ($k, $def = null) => $d->exists ? ($d->{$k} ?? $def) : old($k, $def))
  <div class="modal-backdrop hidden" id="{{ $id }}" @if(! $d->exists && $errors->any() && old('kind') && str_starts_with((string) old('kind'), 'order_') && old('customer_email', old('customer_id')) !== null) data-autoopen @endif><div class="modal" role="dialog" aria-modal="true" aria-label="{{ $d->exists ? 'Edit custom discount' : 'New custom discount' }}"><div class="modal-head"><h3>{{ $d->exists ? 'Edit '.$d->name : 'New custom discount' }}</h3><button class="icon-btn" data-close aria-label="Close">{{ icon('x') }}</button></div>
    <form method="POST" action="{{ $d->exists ? route('supplier.discounts.custom.update', $d) : route('supplier.discounts.custom.store') }}" data-cd-form>@csrf @if($d->exists) @method('PUT') @endif<div class="modal-body">
      <div class="field"><span class="label">1. Who gets it? <span class="req">*</span></span>
        @if($d->exists)
          <div class="input" style="background:var(--surface-2)">{{ $d->customer?->name }} · {{ $d->customer?->email }}</div><input type="hidden" name="customer_id" value="{{ $d->customer_id }}">
          @if($customers->doesntContain('id', $d->customer_id))<input type="hidden" name="customer_email" value="{{ $d->customer?->email }}">@endif
        @else
          @if($customers->isNotEmpty())
            <select class="input" name="customer_id" data-cd-who><option value="">Choose a customer who rented from you…</option>@foreach($customers as $c)<option value="{{ $c->id }}" @selected((string) old('customer_id') === (string) $c->id)>{{ $c->name }} · {{ $c->email }}</option>@endforeach<option value="email" @selected(old('customer_email'))>Someone else (type their email)</option></select>
          @endif
          <div data-cd-email @if($customers->isNotEmpty() && ! old('customer_email')) hidden @endif><input class="input mt-1" type="email" name="customer_email" value="{{ old('customer_email') }}" placeholder="Their PartyRent email, e.g. ana@gmail.com" maxlength="180" @if($customers->isNotEmpty() && ! old('customer_email')) disabled @endif><div class="hint">They need a PartyRent account. Only an exact email match is accepted.</div></div>
        @endif
      </div>
      <div class="form-grid">
        <div class="field"><label for="{{ $id }}n">2. Name of the discount <span class="req">*</span></label><input class="input" id="{{ $id }}n" name="name" required maxlength="80" value="{{ $old('name') }}" placeholder="e.g. Family discount"></div>
        <div class="field"><label for="{{ $id }}note">Private note <span class="muted small">(optional)</span></label><input class="input" id="{{ $id }}note" name="note" maxlength="160" value="{{ $old('note') }}" placeholder="e.g. My cousin"><div class="hint">Only you see this.</div></div>
      </div>
      <div class="field"><span class="label">3. How much off? <span class="req">*</span></span>
        <div class="disc-kinds" role="radiogroup">
          @foreach($CK as $k => [$kl, $kh])<label class="disc-kind {{ $old('kind', 'order_percent') === $k ? 'on' : '' }}"><input type="radio" name="kind" value="{{ $k }}" @checked($old('kind', 'order_percent') === $k) data-cd-kind><span><b>{{ $kl }}</b><small>{{ $kh }}</small></span></label>@endforeach
        </div>
        <div class="input-affix mt-1"><span data-pre>₱</span><input class="input" type="number" name="value" min="0.01" step="0.01" required value="{{ $old('value') }}" placeholder="e.g. 20" aria-label="Discount amount"><span data-suf>%</span></div>
        <div class="hint" data-cd-example></div>
      </div>
      <div class="form-grid">
        <div class="field"><label for="{{ $id }}u">Can be used on <span class="muted small">(optional)</span></label><div class="row" style="gap:8px"><input class="input" id="{{ $id }}u" type="number" name="max_uses" min="1" max="1000" value="{{ $old('max_uses') }}" placeholder="Any number" style="max-width:130px"><span class="small muted">rental(s)</span></div></div>
        <div class="field"><label>Valid <span class="muted small">(optional)</span></label><div class="row" style="gap:6px"><input class="input" type="date" name="starts_on" value="{{ $d->starts_on?->format('Y-m-d') ?? old('starts_on') }}" aria-label="First day"><span class="small muted">to</span><input class="input" type="date" name="ends_on" value="{{ $d->ends_on?->format('Y-m-d') ?? old('ends_on') }}" aria-label="Last day"></div></div>
      </div>
    </div><div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">{{ $d->exists ? 'Save changes' : 'Save discount' }}</button></div></form>
  </div></div>
@endforeach
@php($custom->shift())
@push('scripts')
<script>
document.querySelectorAll('[data-cd-form]').forEach(function (f) {
  var val = f.querySelector('input[name=value]'), ex = f.querySelector('[data-cd-example]');
  var peso = function (n) { return '₱' + (Math.round(n * 100) / 100).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
  function sync() {
    var k = (f.querySelector('[data-cd-kind]:checked') || {}).value || 'order_percent', pct = k === 'order_percent', v = +val.value || 0;
    f.querySelector('[data-pre]').hidden = pct; f.querySelector('[data-suf]').hidden = !pct; val.max = pct ? 100 : '';
    f.querySelectorAll('[data-cd-kind]').forEach(function (r) { r.closest('.disc-kind').classList.toggle('on', r.checked); });
    ex.textContent = !v ? 'Enter an amount to see an example.' : pct ? 'Example: a ₱5,000 rental becomes ' + peso(5000 * (1 - Math.min(v, 100) / 100)) + '.' : 'Example: a ₱5,000 rental becomes ' + peso(Math.max(0, 5000 - v)) + '. It can never be more than the rental items cost.';
  }
  f.querySelectorAll('[data-cd-kind]').forEach(function (r) { r.addEventListener('change', sync); }); val.addEventListener('input', sync); sync();
  var who = f.querySelector('[data-cd-who]'), em = f.querySelector('[data-cd-email]');
  if (who && em) who.addEventListener('change', function () { var on = who.value === 'email'; em.hidden = !on; em.querySelector('input').disabled = !on; if (on) em.querySelector('input').focus(); });
  // "Someone else": send the email, not the select value
  f.addEventListener('submit', function () { if (who && who.value === 'email') who.disabled = true; });
});
document.querySelectorAll('[data-autoopen]').forEach(function (m) { var b = document.querySelector('[data-modal="' + m.id + '"]'); if (b) b.click(); });
</script>
@endpush
@endif
