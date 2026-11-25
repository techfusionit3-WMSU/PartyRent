{{--
  A shop's own policies, each a title + description (App\Support\ShopExtras::policies): add, edit, remove.
  Input: custom_policies[i][title|body]. $value = saved list.
--}}
@php($cp = array_values(old('custom_policies', $value ?? [])))
<div class="field full" data-policies>
  <label>Shop policies <span class="muted small">(optional)</span></label>
  <p class="small muted mt-0">Add any other rules customers should know, e.g. "Late returns", "Setup and venue access", "Weather". Shown on your shop page.</p>
  <div data-pol-rows>
    @foreach($cp as $i => $p)
      <div class="pol-row card card-flat" data-pol-row>
        <div class="row between" style="gap:8px"><input class="input" name="custom_policies[{{ $i }}][title]" maxlength="80" value="{{ $p['title'] ?? '' }}" placeholder="Policy title, e.g. Late returns" aria-label="Policy title"><button type="button" class="btn btn-xs btn-ghost" data-pol-remove aria-label="Remove this policy">{{ icon('trash', 'width:14px;height:14px') }}</button></div>
        @error('custom_policies.'.$i.'.title')<div class="error-text" style="display:block">{{ $message }}</div>@enderror
        <textarea class="input mt-1" name="custom_policies[{{ $i }}][body]" maxlength="1500" rows="2" placeholder="What the policy says" aria-label="Policy description">{{ $p['body'] ?? '' }}</textarea>
        @error('custom_policies.'.$i.'.body')<div class="error-text" style="display:block">{{ $message }}</div>@enderror
      </div>
    @endforeach
  </div>
  <template data-pol-tpl>
    <div class="pol-row card card-flat" data-pol-row>
      <div class="row between" style="gap:8px"><input class="input" name="custom_policies[__i__][title]" maxlength="80" placeholder="Policy title, e.g. Late returns" aria-label="Policy title"><button type="button" class="btn btn-xs btn-ghost" data-pol-remove aria-label="Remove this policy">{{ icon('trash', 'width:14px;height:14px') }}</button></div>
      <textarea class="input mt-1" name="custom_policies[__i__][body]" maxlength="1500" rows="2" placeholder="What the policy says" aria-label="Policy description"></textarea>
    </div>
  </template>
  <button type="button" class="btn btn-sm" data-pol-add>{{ icon('plus') }}Add a policy</button>
  <div class="error-text" data-pol-error @error('custom_policies') style="display:block" @enderror>{{ $errors->first('custom_policies') }}</div>
</div>
@once
@push('scripts')
<script>
(function () {
  document.querySelectorAll('[data-policies]').forEach(function (box) {
    var list = box.querySelector('[data-pol-rows]'), tpl = box.querySelector('[data-pol-tpl]'), n = list.children.length + 1000;
    box.querySelector('[data-pol-add]').addEventListener('click', function () {
      if (list.children.length >= {{ \App\Support\ShopExtras::MAX_POLICIES }}) return window.PR && window.PR.toast('Up to {{ \App\Support\ShopExtras::MAX_POLICIES }} policies.', 'warn');
      list.insertAdjacentHTML('beforeend', tpl.innerHTML.replace(/__i__/g, String(n++)));
      list.lastElementChild.querySelector('input').focus();
    });
    box.addEventListener('click', function (e) { var b = e.target.closest('[data-pol-remove]'); if (b) b.closest('[data-pol-row]').remove(); });
    // the wizard / form checks: no half-filled rows, no duplicate titles
    box.validate = function () {
      var seen = {}, msg = '';
      box.querySelectorAll('[data-pol-row]').forEach(function (r, i) {
        var t = r.querySelector('input').value.trim(), b = r.querySelector('textarea').value.trim();
        r.classList.remove('invalid');
        if (!t && !b) return;
        var k = t.toLowerCase();
        var bad = t.length < 3 ? 'Give policy ' + (i + 1) + ' a title.' : b.length < 10 ? 'Describe "' + t + '" (at least 10 characters).' : seen[k] ? '"' + t + '" is listed twice.' : '';
        seen[k] = 1;
        if (bad) { r.classList.add('invalid'); if (!msg) msg = bad; }
      });
      var err = box.querySelector('[data-pol-error]'); err.textContent = msg; err.style.display = msg ? 'block' : '';
      return !msg;
    };
  });
})();
</script>
@endpush
@endonce
