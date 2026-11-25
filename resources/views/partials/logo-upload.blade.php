{{--
  Shop logo: an emoji (as before) or an uploaded image (App\Support\ShopExtras::storeLogo) with preview, replace and
  remove. Inputs: logo (emoji), logo_file, logo_remove. $emojis = emoji choices, $current = emoji, $path = logo_path.
--}}
<div class="field full" data-logo-up>
  <label>Business logo</label>
  <div class="logo-up row wrap" style="gap:14px;align-items:flex-start">
    <div class="logo-tile logo-prev" data-logo-prev style="width:72px;height:72px;font-size:2.2rem;background:var(--brand-50)">@if($path)<img src="{{ asset('storage/'.$path) }}" alt="Current logo">@else{{ $current }}@endif</div>
    <div class="grow">
      <div class="row wrap" style="gap:8px">
        <label class="btn btn-sm">{{ icon('upload') }}<span data-logo-btn>{{ $path ? 'Replace logo image' : 'Upload a logo image' }}</span><input type="file" name="logo_file" accept="image/jpeg,image/png,image/webp" hidden data-logo-file></label>
        <button type="button" class="btn btn-sm btn-ghost" data-logo-remove @unless($path) hidden @endunless>{{ icon('x') }}Remove image</button>
        <input type="hidden" name="logo_remove" value="0" data-logo-remove-in>
      </div>
      <div class="small muted mt-1">JPG, PNG or WEBP, up to 2 MB, at least 64 × 64 px. A square image looks best. Or pick an emoji:</div>
      @error('logo_file')<div class="error-text" style="display:block">{{ $message }}</div>@enderror
      <div class="error-text" data-logo-err></div>
      <div class="row wrap gap-sm mt-1">@foreach($emojis as $l)<label class="chip {{ $current === $l ? 'active' : '' }}" style="font-size:1.3rem"><input type="radio" name="logo" value="{{ $l }}" @checked($current === $l) hidden data-logo-emoji>{{ $l }}</label>@endforeach</div>
    </div>
  </div>
</div>
@once
@push('scripts')
<script>
(function () {
  document.querySelectorAll('[data-logo-up]').forEach(function (box) {
    var prev = box.querySelector('[data-logo-prev]'), file = box.querySelector('[data-logo-file]'), rm = box.querySelector('[data-logo-remove]'), rmIn = box.querySelector('[data-logo-remove-in]'), err = box.querySelector('[data-logo-err]'), btn = box.querySelector('[data-logo-btn]');
    var hasImg = !!prev.querySelector('img');
    var emoji = function () { var c = box.querySelector('[data-logo-emoji]:checked'); return c ? c.value : '🎈'; };
    var showEmoji = function () { prev.textContent = emoji(); };
    file.addEventListener('change', function () {
      var f = file.files[0]; err.textContent = ''; err.style.display = '';
      if (!f) return;
      var bad = !/^image\/(jpeg|png|webp)$/.test(f.type) ? 'Use a JPG, PNG or WEBP image.' : f.size > 2 * 1024 * 1024 ? 'The logo can be up to 2 MB.' : '';
      if (bad) { file.value = ''; err.textContent = bad; err.style.display = 'block'; return; }
      var url = URL.createObjectURL(f), img = new Image();
      img.onload = function () {
        if (img.width < 64 || img.height < 64) { file.value = ''; err.textContent = 'The logo should be at least 64 × 64 pixels.'; err.style.display = 'block'; URL.revokeObjectURL(url); return; }
        prev.innerHTML = ''; prev.appendChild(img); rm.hidden = false; rmIn.value = '0'; btn.textContent = 'Replace logo image'; hasImg = true;
      };
      img.alt = 'New logo'; img.src = url;
    });
    rm.addEventListener('click', function () { file.value = ''; rmIn.value = '1'; rm.hidden = true; hasImg = false; btn.textContent = 'Upload a logo image'; showEmoji(); });
    box.addEventListener('change', function (e) {
      if (!e.target.matches('[data-logo-emoji]')) return;
      box.querySelectorAll('[data-logo-emoji]').forEach(function (r) { r.closest('.chip').classList.toggle('active', r.checked); });
      if (!hasImg) showEmoji();
    });
  });
})();
</script>
@endpush
@endonce
