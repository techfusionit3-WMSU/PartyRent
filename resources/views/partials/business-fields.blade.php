{{-- Personal vs Business choice + business documents. $d = rental partner attributes (may be empty); $choose = show the Personal/Business cards --}}
@php
    $bo = fn ($k, $def = null) => old($k, $d[$k] ?? $def);
    $type = $bo('account_type', 'personal');
    $berr = fn ($k) => $errors->has($k) ? '<div class="error-text" style="display:block">'.e($errors->first($k)).'</div>' : '';
@endphp
<div data-biz-box>
  @if($choose ?? true)
    <div class="field mb-1"><label>Account type</label></div>
    <div class="grid grid-2 mb-2" style="gap:10px">
      @foreach([['personal', 'Personal', 'You rent out your own items, one-to-one with customers. Just you, no staff accounts.'], ['business', 'Business', 'A registered business (DTI, SEC or CDA). Add staff such as a cashier, warehouse or coordinator, and get a "Verified Business" badge.']] as [$v, $l, $desc])
        <label class="card card-flat row" style="padding:12px 14px;cursor:pointer;align-items:flex-start"><input type="radio" name="account_type" value="{{ $v }}" @checked($type === $v) style="accent-color:var(--brand);width:18px;height:18px;margin-top:3px"><div><b>{{ $l }}</b><div class="small muted">{{ $desc }}</div></div></label>
      @endforeach
    </div>{!! $berr('account_type') !!}
  @else
    <input type="hidden" name="account_type" value="business">
  @endif
  <div data-biz-fields @if($type !== 'business' && ($choose ?? true)) hidden @endif>
    <div class="form-grid">
      <div class="field"><label for="biz_reg_type">Registered with</label><select class="input" id="biz_reg_type" name="biz_reg_type"><option value="">Choose…</option>@foreach(\App\Models\Supplier::REG_TYPES as $k => $l)<option value="{{ $k }}" @selected($bo('biz_reg_type') === $k)>{{ $l }}</option>@endforeach</select>{!! $berr('biz_reg_type') !!}</div>
      <div class="field"><label for="biz_reg_no">Registration number</label><input class="input" id="biz_reg_no" name="biz_reg_no" maxlength="60" value="{{ $bo('biz_reg_no') }}" placeholder="e.g. DTI 1234567">{!! $berr('biz_reg_no') !!}</div>
      <div class="field"><label for="biz_tin">TIN</label><input class="input" id="biz_tin" name="biz_tin" maxlength="30" value="{{ $bo('biz_tin') }}" placeholder="000-000-000-000">{!! $berr('biz_tin') !!}</div>
      <div class="field"><label for="biz_doc">Business permit or registration certificate</label><input class="input" type="file" id="biz_doc" name="biz_doc" accept="image/*,application/pdf">
        <div class="hint">Photo or PDF, up to 8 MB. Only PartyRent admins see it.@if(! empty($d['biz_doc_path'])) A file is already on record; upload only to replace it.@endif</div>{!! $berr('biz_doc') !!}</div>
    </div>
  </div>
</div>
@once
@push('scripts')
<script>
(function () {
  document.querySelectorAll('[data-biz-box]').forEach(function (box) {
    var fields = box.querySelector('[data-biz-fields]');
    box.querySelectorAll('input[name="account_type"][type=radio]').forEach(function (r) {
      r.addEventListener('change', function () { fields.hidden = r.value !== 'business' || !r.checked; });
    });
  });
})();
</script>
@endpush
@endonce
