<div class="field"><label for="insurance">Policy or certificate</label><input class="input" type="file" id="insurance" name="insurance" accept="image/jpeg,image/png,image/webp,application/pdf" data-file-preview @if($required ?? false) required @endif>@error('insurance')<div class="error-text">{{ $message }}</div>@enderror</div>
<div class="form-grid">
  <div class="field"><label for="insurer">Insurer <span class="muted small">(optional)</span></label><input class="input" id="insurer" name="insurer" maxlength="80" value="{{ old('insurer', $v->insurer) }}"></div>
  <div class="field"><label for="insurance_expires">Valid until <span class="muted small">(optional)</span></label><input class="input" type="date" id="insurance_expires" name="insurance_expires" value="{{ old('insurance_expires', $v->insurance_expires?->toDateString()) }}">@error('insurance_expires')<div class="error-text">{{ $message }}</div>@enderror</div>
</div>
