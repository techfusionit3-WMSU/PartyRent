{{--
  Bulk discount tiers: "from N pieces, X% off each". Used by the product form and the Discounts page.
  The base price is the form's [name=base_price]; the server re-checks everything (Pricing::tiersFrom).
  Vars: $rows [['min' => n, 'pct' => %], …], $stock (int|null: tiers above it never apply; the product form reads its
  stock fields live instead), $errorText (string|null).
--}}
@php
  $max = \App\Domain\Pricing::MAX_BULK_TIERS;
  $maxPct = \App\Domain\Pricing::MAX_BULK_PCT;
  $row = fn ($i, $min, $pct) => '<div class="bulk-row" data-bulk-row>'
    .'<label class="bulk-f"><span class="bulk-lbl">From</span><input class="input input-sm" type="number" min="2" max="100000" step="1" inputmode="numeric" name="bulk['.$i.'][min]" value="'.e($min).'" placeholder="10" aria-label="From how many pieces" data-min><span class="bulk-suf">or more</span></label>'
    .'<label class="bulk-f"><span class="bulk-lbl">Discount</span><input class="input input-sm" type="number" min="1" max="'.$maxPct.'" step="0.5" inputmode="decimal" name="bulk['.$i.'][pct]" value="'.e($pct).'" placeholder="10" aria-label="Percent off" data-pct><span class="bulk-suf">% off</span></label>'
    .'<span class="bulk-each" data-each aria-live="polite">—</span>'
    .'<button type="button" class="icon-btn bulk-rm" data-bulk-rm aria-label="Remove this discount">'.icon('trash').'</button></div>';
@endphp
<div class="bulk-ed" data-bulk-editor data-max="{{ $max }}" data-max-pct="{{ $maxPct }}" @if(! empty($stock)) data-stock="{{ (int) $stock }}" @endif>
  <div class="label">Bulk discount <span class="muted">(optional)</span></div>
  <p class="small muted mt-0">Give a lower price when a customer rents many of this item for the same day. Example: <b>5 or more: 5% off</b>, <b>10 or more: 10% off</b>, <b>20 or more: 15% off</b>. A customer gets only the biggest discount they reach.</p>
  <div class="bulk-cols" aria-hidden="true" @if(! $rows) hidden @endif><span>When renting</span><span>Discount</span><span>New price each</span><span></span></div>
  <div data-bulk-rows>@foreach($rows as $i => $t){!! $row($i, $t['min'] ?? '', isset($t['pct']) ? \App\Domain\Pricing::pctText((float) $t['pct']) : '') !!}@endforeach</div>
  <template data-bulk-tpl>{!! $row('__i__', '', '') !!}</template>
  <div class="error-text bulk-err" data-bulk-err role="alert" @if(empty($errorText)) hidden @endif>{{ $errorText ?? '' }}</div>
  <button type="button" class="btn btn-sm" data-bulk-add>{{ icon('plus') }}Add a discount</button>
  <span class="small muted" data-bulk-left></span>

  <div class="bulk-prev" data-bulk-preview>
    <div class="bulk-prev-head"><b>Try it out</b>
      <label class="small">A customer rents <input class="input input-sm" type="number" min="1" step="1" value="{{ $rows ? max(1, (int) end($rows)['min']) : 10 }}" data-prev-qty aria-label="Preview quantity"> pieces</label></div>
    <dl class="bulk-prev-grid">
      <div><dt>Original price</dt><dd data-p-orig>—</dd></div>
      <div><dt>Discount</dt><dd data-p-pct>—</dd></div>
      <div><dt>Price each</dt><dd data-p-each>—</dd></div>
      <div><dt data-p-total-lbl>Total</dt><dd data-p-total>—</dd></div>
    </dl>
    <div class="bulk-list" data-p-list></div>
  </div>
</div>
