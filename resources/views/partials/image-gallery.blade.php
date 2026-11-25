{{--
  Photo gallery for a form (products): current photos (keep / remove / make primary) and new photos picked from the
  device, previewed at once, removable before saving. The first photo is the primary one (shown in listings).
  Sends: keep_images[] (current photos kept), {name}[] (new files), primary_image ("keep:<path>" or "new:<n>").
  Params: $name, $current (paths), $max, $label. Script: public/js/app.js [data-gallery].
--}}
@php($current = array_values($current ?? []))
<div class="field" data-gallery data-max="{{ $max ?? 8 }}">
  <label>{{ $label ?? 'Photos' }} <span class="muted small">(up to {{ $max ?? 8 }}; the first one is the main photo)</span></label>
  <input type="hidden" name="primary_image" value="{{ $current ? 'keep:'.$current[0] : '' }}" data-primary>
  <div class="gal-grid" data-gal-grid>
    @foreach($current as $i => $im)
      <figure class="gal-item {{ $i === 0 ? 'is-primary' : '' }}" data-key="keep:{{ $im }}">
        <img src="{{ asset('storage/'.$im) }}" alt="Photo {{ $i + 1 }}" loading="lazy">
        <input type="hidden" name="keep_images[]" value="{{ $im }}">
        <figcaption><button type="button" class="gal-btn" data-gal-primary title="Make this the main photo">★<span>{{ $i === 0 ? 'Main photo' : 'Make main' }}</span></button><button type="button" class="gal-btn gal-x" data-gal-remove aria-label="Remove this photo">×</button></figcaption>
      </figure>
    @endforeach
    <label class="gal-add" data-gal-add>{{ icon('plus') }}<span>Add photos</span><input type="file" accept="image/jpeg,image/png,image/webp" multiple data-gal-input hidden></label>
  </div>
  <input type="file" name="{{ $name }}[]" multiple hidden data-gal-files>
  <div class="hint" data-gal-hint>JPG, PNG or WebP, up to 8 MB each. Drag-free: tap ★ to choose the main photo, × to remove one. Without photos, a category picture is shown.</div>
  @error($name)<div class="error-text" style="display:block">{{ $message }}</div>@enderror
  @error($name.'.*')<div class="error-text" style="display:block">{{ $message }}</div>@enderror
</div>
