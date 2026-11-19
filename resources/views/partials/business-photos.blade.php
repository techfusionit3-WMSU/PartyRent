{{-- Business photos (App\Domain\BusinessProfile): logo, cover and a portfolio of past events.
     $photos = ['logo' => ?path, 'cover' => ?path, 'portfolio' => [paths]] already saved / uploaded. --}}
@php
    $min = \App\Models\Supplier::PORTFOLIO_MIN;
    $max = \App\Models\Supplier::PORTFOLIO_MAX;
    $keepOld = old('portfolio_keep');
@endphp
<h3 class="mt-2 mb-1">Photos</h3>
<div class="form-grid">
  <div class="field"><label>Logo <span class="muted small">(optional)</span></label>
    @if($photos['logo'])<div class="row mb-1" style="gap:10px"><img src="{{ asset('storage/'.$photos['logo']) }}" alt="Current logo" class="bp-logo"><label class="check small"><input type="checkbox" name="remove_logo" value="1"> Remove</label></div>@endif
    <input class="input" type="file" name="logo_file" accept="image/*"><div class="hint">Square works best. Without one, your emoji logo is used.</div>{!! $errors->has('logo_file') ? '<div class="error-text" style="display:block">'.e($errors->first('logo_file')).'</div>' : '' !!}</div>
  <div class="field"><label>Cover photo <span class="muted small">(optional)</span></label>
    @if($photos['cover'])<div class="row mb-1" style="gap:10px"><img src="{{ asset('storage/'.$photos['cover']) }}" alt="Current cover" class="bp-cover"><label class="check small"><input type="checkbox" name="remove_cover" value="1"> Remove</label></div>@endif
    <input class="input" type="file" name="cover_file" accept="image/*"><div class="hint">Shown at the top of your shop page.</div>{!! $errors->has('cover_file') ? '<div class="error-text" style="display:block">'.e($errors->first('cover_file')).'</div>' : '' !!}</div>
</div>
<div class="field {{ $errors->has('portfolio') || $errors->has('portfolio.*') ? 'invalid' : '' }}" data-portfolio data-min="{{ $min }}" data-max="{{ $max }}">
  <label>Portfolio: photos of events you have done <span class="badge b-red plain">At least {{ $min }}</span></label>
  @if($photos['portfolio'])
    <div class="bp-grid mb-1">@foreach($photos['portfolio'] as $ph)
      <label class="bp-thumb"><img src="{{ asset('storage/'.$ph) }}" alt="Portfolio photo"><span class="check small"><input type="checkbox" name="portfolio_keep[]" value="{{ $ph }}" @checked($keepOld === null || in_array($ph, (array) $keepOld, true)) data-pf-keep> Keep</span></label>
    @endforeach</div>
  @endif
  <input class="input" type="file" name="portfolio[]" accept="image/*" multiple data-pf-new>
  <div class="hint" data-pf-count>Real setups help customers trust you and help PartyRent verify your business. Up to {{ $max }} photos, 8 MB each.</div>
  @if($errors->has('portfolio') || $errors->has('portfolio.*'))<div class="error-text" style="display:block">{{ $errors->first('portfolio') ?: $errors->first('portfolio.*') }}</div>@endif
</div>
@once
@push('scripts')
<script>
(function () {
  var box = document.querySelector('[data-portfolio]'); if (!box) return;
  var min = +box.dataset.min, max = +box.dataset.max, hint = box.querySelector('[data-pf-count]'), base = hint.textContent;
  function count() {
    var kept = [].filter.call(box.querySelectorAll('[data-pf-keep]'), function (c) { return c.checked && !c.disabled; }).length;
    var inp = box.querySelector('[data-pf-new]');
    return kept + (inp && !inp.disabled && inp.files ? inp.files.length : 0);
  }
  window.PR_portfolioCount = count;
  function show() {
    var n = count();
    hint.textContent = n ? n + ' photo' + (n === 1 ? '' : 's') + ' · ' + (n < min ? 'add ' + (min - n) + ' more' : n > max ? 'remove ' + (n - max) : 'good') : base;
    hint.style.color = n && (n < min || n > max) ? 'var(--red)' : n ? 'var(--green)' : '';
  }
  box.addEventListener('change', show);
  show();
})();
</script>
@endpush
@endonce
