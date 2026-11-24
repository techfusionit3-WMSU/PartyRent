{{--
  Customer discount on a rental (shop owner): add one of the shop's custom discounts for THIS customer before they pay,
  with the breakdown shown first; or see / remove the one already added. The server checks and works out everything
  again (App\Domain\CustomDiscounts). Needs $r.
--}}
@php
    $CD = \App\Domain\CustomDiscounts::class;
    $applied = $r->pricing['customDiscount'] ?? null;
    $blocked = $CD::rentalBlocker($r);
    $mine = \App\Models\Discount::where('supplier_id', $r->supplier_id)->where('customer_id', $r->customer_id)->get();
    $usable = $applied || $blocked ? collect() : $CD::availableFor($r);
    $show = $applied || ($mine->isNotEmpty() && in_array($r->status, ['pending', 'accepted'], true)) || (! $blocked);
@endphp
@if($show)
<div class="card" id="customDiscount"><h3>{{ icon('tag') }} Customer discount</h3>
  @if($applied)
    <p class="small mt-0 mb-1"><b>{{ $applied['name'] }}</b> · {{ $applied['label'] }}</p>
    <div class="sum-line"><span class="muted">Original amount</span><span>{{ money($applied['totalBefore']) }}</span></div>
    <div class="sum-line" style="color:var(--green)"><span>Discount</span><span>−{{ money($applied['amount']) }}</span></div>
    <div class="sum-line strong"><span>Customer pays</span><span>{{ money($applied['totalAfter']) }}</span></div>
    <p class="small muted mb-1">Added by {{ $applied['appliedBy'] }} · {{ fmt_datetime($applied['appliedAt']) }}</p>
    @if(! $blocked)
      <form method="POST" action="{{ route('supplier.requests.custom-discount.remove', $r) }}" data-confirm-title="Remove the discount?" data-confirm="{{ $r->customer->firstName() }} will pay {{ money($applied['totalBefore']) }} again." data-confirm-ok="Remove discount">@csrf @method('DELETE')<button class="btn btn-sm btn-ghost">Remove discount</button></form>
    @else<p class="small muted mb-0">It can't be removed anymore: {{ lcfirst($blocked) }}</p>@endif
  @elseif($blocked)
    <p class="small muted mb-0">{{ $blocked }}</p>
  @elseif($usable->isEmpty())
    <p class="small muted mb-0">{{ $mine->isEmpty() ? 'You have no custom discount for '.$r->customer->firstName().'.' : 'None of '.$r->customer->firstName().'\'s custom discounts can be used now (turned off, not started, ended or used up).' }}
      <a href="{{ route('supplier.discounts', ['tab' => 'custom']) }}#custom">{{ $mine->isEmpty() ? 'Create one' : 'Manage custom discounts' }}</a></p>
  @else
    <p class="small muted mt-0">A private discount you made for {{ $r->customer->firstName() }}. Add it before they pay; their payments follow the new total.</p>
    <form method="POST" action="{{ route('supplier.requests.custom-discount', $r) }}" data-cd-apply data-confirm-title="Add this discount?" data-confirm="{{ $r->customer->firstName() }} will be told about the new total." data-confirm-ok="Add discount">@csrf
      <div class="field mb-1"><label for="cdPick">Discount</label>
        <select class="input" id="cdPick" name="discount_id" required>
          @foreach($usable as $d)
            @php($pv = $CD::preview($r, $d))
            <option value="{{ $d->id }}" data-label="{{ $pv['label'] }}" data-amount="{{ money($pv['amount']) }}" data-after="{{ money($pv['totalAfter']) }}" data-error="{{ $pv['error'] }}">{{ $d->name }} · {{ $pv['label'] }}</option>
          @endforeach
        </select></div>
      @php($first = $CD::preview($r, $usable->first()))
      <div class="cd-breakdown">
        <div class="sum-line"><span class="muted">Original amount</span><span>{{ money($first['totalBefore']) }}</span></div>
        <div class="sum-line"><span class="muted">Discount</span><span data-cd-label>{{ $first['label'] }}</span></div>
        <div class="sum-line" style="color:var(--green)"><span>Takes off</span><span data-cd-amount>−{{ money($first['amount']) }}</span></div>
        <div class="sum-line total"><span>Customer pays</span><span class="price" data-cd-after>{{ money($first['totalAfter']) }}</span></div>
      </div>
      <div class="error-text" data-cd-error @if(! $first['error']) hidden @endif style="display:block">{{ $first['error'] }}</div>
      <p class="small muted">The discount comes off the rental items ({{ money($first['base']) }}); delivery and services stay the same.</p>
      <button class="btn btn-primary btn-block" data-cd-submit @disabled($first['error'])>Add discount</button>
    </form>
    @push('scripts')
    <script>
    (function () {
      var f = document.querySelector('[data-cd-apply]'); if (!f) return;
      var sel = f.querySelector('select'), q = function (k) { return f.querySelector('[data-cd-' + k + ']'); };
      sel.addEventListener('change', function () {
        var o = sel.selectedOptions[0];
        q('label').textContent = o.dataset.label; q('amount').textContent = '−' + o.dataset.amount; q('after').textContent = o.dataset.after;
        q('error').textContent = o.dataset.error; q('error').hidden = !o.dataset.error; q('submit').disabled = !!o.dataset.error;
      });
    })();
    </script>
    @endpush
  @endif
</div>
@endif
