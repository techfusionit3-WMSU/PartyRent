@extends('layouts.market', ['title' => 'Apply as a Supplier', 'nav' => 'profile'])

{{-- Reference C.startSelling: Individual / Organization application wizard (8 steps, one form). --}}
@php
    use App\Domain\Applications as A;
    $o = fn ($k, $def = null) => old($k, data_get($d, $k, $def));
    $ao = fn ($k, $def = '') => old('app.'.$k, data_get($app, $k, $def));
    $err = fn ($k) => $errors->has($k) ? '<div class="error-text" style="display:block">'.e($errors->first($k)).'</div>' : '';
    $type = old('app.type', $app['type'] ?? '');
    $ctype = $ao('company.companyType', 'corporation');
    $methods = old('methods', $d['methods'] ?? ['cod', 'bank']);
    $pol = $d['policy'] ?? [];
    $applicable = old('app.applicable', $app['applicable'] ?? []);
    $docs = (array) ($app['docs'] ?? []);
    $fld = function ($label, $name, $val, $attrs = 'required', $cls = '') use ($err) {
        $key = str_replace(['[', ']'], ['.', ''], $name);

        return '<div class="field '.$cls.'"><label>'.e($label).'</label><input class="input" name="'.$name.'" value="'.e($val).'" '.$attrs.'>'.$err($key).'</div>';
    };
    $needTag = ['required' => '<span class="badge b-red plain">Required</span>', 'conditional' => '<span class="badge b-gold plain">If applicable</span>', 'recommended' => '<span class="badge b-sky plain">Recommended</span>', 'optional' => '<span class="badge b-gray plain">Optional</span>'];
    $docRows = function (array $reqs) use ($docs, $applicable, $needTag) {
        $html = '';
        foreach (collect($reqs)->groupBy('group') as $g => $list) {
            $html .= '<h3 class="mt-2">'.e($g).'</h3>';
            foreach ($list as $q) {
                $have = $docs[$q['key']] ?? null;
                $html .= '<div class="doc-row" data-docrow="'.$q['key'].'" data-need="'.$q['need'].'" data-label="'.e($q['label']).'"><div class="grow"><b>'.e($q['label']).'</b> '.$needTag[$q['need']]
                    .($q['note'] ? '<div class="small muted">'.e($q['note']).'</div>' : '')
                    .($q['need'] === 'conditional' ? '<label class="check small mt-1"><input type="checkbox" name="app[applicable][]" value="'.$q['key'].'" data-applies'.(in_array($q['key'], $applicable, true) ? ' checked' : '').'> This applies to my business</label>' : '')
                    .'</div><div class="row" style="gap:6px">'
                    .'<span class="badge b-green" data-docname'.($have ? '' : ' hidden').' data-had="'.($have ? 1 : 0).'">'.icon('check', 'width:12px;height:12px').' <span>'.e($have['name'] ?? '').'</span></span>'
                    .'<label class="btn btn-sm">'.icon('upload').'Upload<input type="file" name="docs['.$q['key'].']" accept="image/*,.pdf" hidden data-doc></label></div></div>';
            }
        }

        return $html;
    };
@endphp
@section('content')
<div class="page-head"><div><h1>Apply as a Supplier</h1><p>Join PartyRent and start offering your rental products — using your existing account.
  @if($existing && $existing->status === 'rejected')<span class="badge b-red">Previous application rejected: {{ $existing->reject_reason }}</span>@endif
  @if($existing && $existing->status === 'revision')<span class="badge b-purple">Requires revision: {{ $existing->revision_note }}</span>@endif</p></div></div>
<div class="wizard-steps">@foreach($steps as $i => $t)<div class="ws {{ $i === 0 ? 'on' : '' }}" data-ws="{{ $i }}"><span>Step {{ $i + 1 }}</span>{{ $t }}</div>@endforeach</div>

<form class="card" id="wf" method="POST" action="{{ route('start-selling.submit') }}" enctype="multipart/form-data" style="max-width:820px" novalidate
      data-confirm-title="{{ $existing ? 'Resubmit' : 'Submit' }} supplier application?" data-confirm-ok="{{ $existing ? 'Resubmit' : 'Submit' }}"
      data-confirm="Your supplier profile will be linked to this account ({{ auth()->user()->email }}). You can keep renting as a customer.">
  @csrf
  <h2 id="stepTitle">{{ $steps[0] }}</h2>

  {{-- 1 · Supplier type --}}
  <section data-step="0">
    <p class="muted">Choose how you want to register your rental business. Requirements differ for individuals and companies.</p>
    <div class="grid grid-2">
      <label class="type-card {{ $type === 'individual' ? 'on' : '' }}"><input type="radio" name="app[type]" value="individual" @checked($type === 'individual') hidden data-type>
        <div style="font-size:2rem">🧑‍💼</div><h3 class="mt-1">Individual Supplier</h3><p class="small muted">For individual owners and small rental businesses — even if you have a few helpers.</p>
        <ul class="small"><li>Valid government ID (+ selfie)</li><li>Personal and business information</li><li>DTI, barangay or mayor's permit <i>if applicable</i></li></ul><span class="badge b-pink">Single owner account</span></label>
      <label class="type-card {{ $type === 'organization' ? 'on' : '' }}"><input type="radio" name="app[type]" value="organization" @checked($type === 'organization') hidden data-type>
        <div style="font-size:2rem">🏢</div><h3 class="mt-1">Organization / Company Supplier</h3><p class="small muted">For corporations, partnerships, cooperatives and companies with employees or branches.</p>
        <ul class="small"><li>Company registration documents (SEC / DTI / CDA)</li><li>BIR registration and business permits</li><li>Authorized representative's ID and proof of authority</li></ul><span class="badge b-purple">Multiple employee accounts</span></label>
    </div>
    {!! $err('app.type') !!}
    <p class="small muted mt-2 mb-0">Applications are subject to PartyRent administrator verification. Employees (cashier, inventory staff) are added after approval — not during the application.</p>
  </section>

  {{-- 2 · Applicant --}}
  <section data-step="1">
    <div data-for="individual">
      <h3>Personal information</h3>
      <div class="form-grid">
        {!! $fld('Full name', 'app[personal][fullName]', $ao('personal.fullName'), 'required minlength="3"') !!}
        {!! $fld('Date of birth', 'app[personal][birthDate]', $ao('personal.birthDate'), 'type="date" required max="'.add_days(today_iso(), -365 * 18).'"') !!}
        {!! $fld('Complete address', 'app[personal][address]', $ao('personal.address'), 'required minlength="8"', 'full') !!}
        {!! $fld('Contact number', 'app[personal][phone]', $ao('personal.phone'), 'required data-phone') !!}
        {!! $fld('Email address', 'app[personal][email]', $ao('personal.email'), 'type="email" required') !!}
      </div>
      <h3 class="mt-2">Identity verification</h3>
      <div class="form-grid">
        <div class="field"><label>Government-issued ID</label><select class="input" name="app[identity][idType]">@foreach(A::ID_TYPES as $x)<option @selected($x === $ao('identity.idType', A::ID_TYPES[0]))>{{ $x }}</option>@endforeach</select></div>
        {!! $fld('ID number', 'app[identity][idNumber]', $ao('identity.idNumber')) !!}
      </div>
      <p class="small muted mb-0">Upload a clear photo of the ID and a selfie holding it in the next step.</p>
    </div>
    <div data-for="organization">
      <h3>Company information</h3>
      <div class="form-grid">
        {!! $fld('Registered company / organization name', 'app[company][legalName]', $ao('company.legalName'), 'required minlength="3"', 'full') !!}
        <div class="field"><label>Company type</label><select class="input" name="app[company][companyType]" id="ctype">@foreach(A::COMPANY_TYPES as $k => $l)<option value="{{ $k }}" @selected($k === $ctype)>{{ $l }}</option>@endforeach</select></div>
        {!! $fld('Number of branches / rental locations', 'app[company][branches]', $ao('company.branches', 1), 'type="number" min="1" required') !!}
        {!! $fld('Complete business address', 'app[company][address]', $ao('company.address'), 'required minlength="8"', 'full') !!}
        {!! $fld('Official company email', 'app[company][email]', $ao('company.email'), 'type="email" required') !!}
        {!! $fld('Company contact number', 'app[company][phone]', $ao('company.phone'), 'required data-phone') !!}
        {!! $fld('Nature of business', 'app[company][natureOfBusiness]', $ao('company.natureOfBusiness', 'Party and event equipment rental'), 'required', 'full') !!}
        <div class="field full"><label>Business description</label><textarea class="input" name="app[company][description]" required minlength="20">{{ $ao('company.description') }}</textarea></div>
        {!! $fld('Website or social media page (optional)', 'app[company][website]', $ao('company.website'), '', 'full') !!}
      </div>
      <h3 class="mt-2">Authorized representative</h3>
      <p class="small muted">The person submitting this application. Once approved, they become the <b>Organization Admin</b> of the supplier account.</p>
      <div class="form-grid">
        {!! $fld('Full name', 'app[representative][name]', $ao('representative.name')) !!}
        {!! $fld('Position / designation', 'app[representative][position]', $ao('representative.position')) !!}
        <div class="field"><label>Government ID type</label><select class="input" name="app[representative][idType]">@foreach(A::ID_TYPES as $x)<option @selected($x === $ao('representative.idType', A::ID_TYPES[0]))>{{ $x }}</option>@endforeach</select></div>
        {!! $fld('Official email', 'app[representative][email]', $ao('representative.email'), 'type="email" required') !!}
        {!! $fld('Contact number', 'app[representative][phone]', $ao('representative.phone'), 'required data-phone') !!}
      </div>
    </div>
  </section>

  {{-- 3 · Documents --}}
  <section data-step="2">
    <p class="muted small">Upload clear scans or photos (PDF, JPG or PNG, up to 10 MB each). Mark "If applicable" documents that apply to your business — they then become required. Documents are stored privately and only PartyRent administrators can open them.</p>
    {!! $err('docs') !!}
    <div data-for="individual">{!! $docRows(A::requirements('individual')) !!}</div>
    @foreach(array_keys(A::COMPANY_TYPES) as $ct)
      <div data-for="organization" data-ctype="{{ $ct }}">{!! $docRows(A::requirements('organization', $ct)) !!}</div>
    @endforeach
  </section>

  {{-- 4 · Shop details --}}
  <section data-step="3">
    <div class="field"><label data-org-label="Shop / brand name on PartyRent">Business or rental shop name</label><input class="input" name="name" required minlength="3" value="{{ $o('name') }}" placeholder="e.g. Santos Party Rentals">{!! $err('name') !!}</div>
    <div class="field"><label>Shop description</label><textarea class="input" name="description" required minlength="20" placeholder="What do you rent out, and for which events?">{{ $o('description') }}</textarea>{!! $err('description') !!}</div>
    <div class="field" data-for="individual"><label>Types of rental products offered</label><input class="input" name="app[business][productTypes]" required value="{{ $ao('business.productTypes') }}" placeholder="e.g. chairs, tables, tents, sound system"></div>
    <div class="form-grid">
      <div class="field"><label>Business contact number</label><input class="input" name="phone" data-phone required value="{{ $o('phone') }}">{!! $err('phone') !!}</div>
      <div class="field"><label>Business email</label><input class="input" type="email" name="email" required value="{{ $o('email') }}">{!! $err('email') !!}</div>
      <div class="field full"><label>Operating hours</label><input class="input" name="hours" required value="{{ $o('hours') }}"></div>
    </div>
    @include('partials.location-fields')
    <div class="field"><label>Business logo</label><div class="row wrap gap-sm">@foreach($logos as $l)<label class="chip {{ $o('logo', '🎈') === $l ? 'active' : '' }}" style="font-size:1.3rem"><input type="radio" name="logo" value="{{ $l }}" @checked($o('logo', '🎈') === $l) hidden>{{ $l }}</label>@endforeach</div></div>
  </section>

  {{-- 5 · Delivery & payments --}}
  <section data-step="4">
    <div class="stack-sm">@foreach([['delivery', 'Delivery only', 'You bring items to the customer.'], ['pickup', 'Pickup only', 'Customers collect from your location.'], ['both', 'Both delivery and pickup', 'Let customers choose.']] as [$v, $l, $desc])
      <label class="card card-flat row" style="padding:12px 14px;cursor:pointer"><input type="radio" name="fulfillment" value="{{ $v }}" @checked($o('fulfillment', 'both') === $v) style="accent-color:var(--brand);width:18px;height:18px"><div><b>{{ $l }}</b><div class="small muted">{{ $desc }}</div></div></label>
    @endforeach</div>{!! $err('fulfillment') !!}
    <div class="field"><label>Accepted payment methods</label>
      @foreach(['cod' => 'Cash on Delivery', 'bank' => 'Online Bank Transfer', 'debit' => 'Debit'] as $k => $l)<label class="check"><input type="checkbox" name="methods[]" value="{{ $k }}" @checked(in_array($k, $methods, true))> {{ $l }}</label>@endforeach
      {!! $err('methods') !!}</div>
    <div class="alert alert-info">{{ icon('info') }}<div class="small">No delivery fee, down payment or deposit percentages are asked here. They differ per item, event and situation, so you set them in Shop Settings after approval and when you post each package.</div></div>
  </section>

  {{-- 6 · Payout --}}
  <section data-step="5">
    <p class="muted small">Where PartyRent sends your payouts.<span data-for="organization"> Use the company's own account, registered in the company name.</span></p>
    <div class="form-grid">
      <div class="field"><label>Preferred payout method</label><select class="input" name="app[payout][method]"><option value="bank" @selected($ao('payout.method', 'bank') === 'bank')>Bank account</option><option value="ewallet" @selected($ao('payout.method') === 'ewallet')>E-wallet (GCash / Maya)</option></select></div>
      {!! $fld('Bank or e-wallet', 'app[payout][provider]', $ao('payout.provider'), 'required placeholder="e.g. BDO, GCash"') !!}
      <div class="field"><label data-org-label="Account holder's registered company name">Account holder name</label><input class="input" name="app[payout][accountName]" required value="{{ $ao('payout.accountName') }}"></div>
      {!! $fld('Account / mobile number', 'app[payout][accountNumber]', $ao('payout.accountNumber')) !!}
      <div class="field full" data-for="organization"><label>Person responsible for financial transactions</label><input class="input" name="app[payout][financeContact]" required value="{{ $ao('payout.financeContact') }}"></div>
    </div>
  </section>

  {{-- 7 · Policies --}}
  <section data-step="6">
    <div class="field"><label>Free cancellation window (days before rental)</label><input class="input" type="number" min="0" max="30" name="cancelDays" value="{{ old('cancelDays', $pol['cancelDays'] ?? 3) }}"></div>
    <div class="field"><label>Cancellation policy</label><textarea class="input" name="cancellation" required>{{ old('cancellation', $pol['cancellation'] ?? '') }}</textarea>{!! $err('cancellation') !!}</div>
    <div class="field"><label>Return policy</label><textarea class="input" name="returns" required>{{ old('returns', $pol['returns'] ?? '') }}</textarea></div>
    <div class="field"><label>Rental conditions</label><textarea class="input" name="conditions" required>{{ old('conditions', $pol['conditions'] ?? '') }}</textarea></div>
  </section>

  {{-- 8 · Review --}}
  <section data-step="7"><div id="reviewSum"></div></section>

  <div class="row between mt-3"><button type="button" class="btn" id="back">{{ icon('left') }}Back</button><button type="button" class="btn btn-primary" id="next">Continue{{ icon('right') }}</button><button class="btn btn-primary hidden" id="submitApp" data-busy="Submitting…">{{ $existing ? 'Resubmit application' : 'Submit application' }}{{ icon('right') }}</button></div>
</form>

@push('head')<style>
.type-card { display: block; text-align: left; padding: 18px; border: 1.5px solid var(--line); border-radius: var(--radius); background: var(--surface); cursor: pointer; }
.type-card.on { border-color: var(--brand); box-shadow: 0 0 0 3px var(--brand-50); }
.doc-row { display: flex; align-items: center; gap: 12px; padding: 10px 0; border-bottom: 1px solid var(--line); }
#reviewSum dl { margin-top: 8px; }
</style>@endpush
@push('scripts')
<script>
(function () {
  var f = document.getElementById('wf'), steps = f.querySelectorAll('section[data-step]'), titles = @json($steps), step = 0;
  var $$ = function (s, r) { return Array.prototype.slice.call((r || f).querySelectorAll(s)); };
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var val = function (n) { var i = f.querySelector('[name="' + n + '"]'); return i ? i.value.trim() : ''; };
  var type = function () { var r = f.querySelector('[data-type]:checked'); return r ? r.value : ''; };
  var org = function () { return type() === 'organization'; };
  // Show the fields for the chosen supplier type (and company type); hidden fields are disabled so they are not validated or sent.
  function applyType() {
    var t = type(), ct = (f.querySelector('#ctype') || {}).value;
    $$('[data-for]').forEach(function (el) {
      var on = el.dataset.for === t && (!el.dataset.ctype || el.dataset.ctype === ct);
      el.hidden = !on; $$('input,select,textarea', el).forEach(function (i) { i.disabled = !on; });
    });
    $$('[data-type]').forEach(function (r) { r.closest('.type-card').classList.toggle('on', r.checked); });
    $$('[data-org-label]').forEach(function (l) { if (!l.dataset.indLabel) l.dataset.indLabel = l.textContent; l.textContent = org() ? l.dataset.orgLabel : l.dataset.indLabel; });
    $$('[data-docrow]').forEach(function (row) { var c = row.querySelector('[data-applies]'); row.dataset.req = row.dataset.need === 'required' || (c && c.checked) ? '1' : ''; });
  }
  function missingDocs() {
    return $$('[data-docrow]').filter(function (row) { return !row.closest('[hidden]') && row.dataset.req && row.querySelector('[data-docname]').hidden; }).map(function (row) { return row.dataset.label; });
  }
  function review() {
    var rows = [];
    if (org()) { rows.push(['Company', val('app[company][legalName]') + ' (' + f.querySelector('#ctype').selectedOptions[0].text + ') · ' + val('app[company][branches]') + ' location(s)']); rows.push(['Representative', val('app[representative][name]') + ', ' + val('app[representative][position]') + ' · ' + val('app[representative][email]')]); }
    else { rows.push(['Applicant', val('app[personal][fullName]') + ' · born ' + (val('app[personal][birthDate]') || '—')]); rows.push(['ID', val('app[identity][idType]') + ' · ' + val('app[identity][idNumber]')]); }
    rows.push(['Shop contact', val('phone') + ' · ' + val('email')]);
    rows.push(['Address', [val('shop_addr[street]'), val('shop_addr[barangay]')].filter(Boolean).join(', ')]);
    rows.push(['Methods', $$('[name="methods[]"]:checked').map(function (c) { return c.parentNode.textContent.trim(); }).join(', ')]);
    rows.push(['Payout', [val('app[payout][provider]'), val('app[payout][accountName]'), val('app[payout][accountNumber]')].join(' · ')]);
    var docs = $$('[data-docrow]').filter(function (r) { return !r.closest('[hidden]') && !r.querySelector('[data-docname]').hidden; }).map(function (r) { return '<span class="tag">' + esc(r.dataset.label) + '</span>'; }).join(' ');
    var logo = f.querySelector('[name=logo]:checked'), ful = f.querySelector('[name=fulfillment]:checked'), miss = missingDocs();
    document.getElementById('reviewSum').innerHTML = '<div class="row mb-2"><div class="logo-tile" style="background:var(--brand-50)">' + esc(logo ? logo.value : '🎈') + '</div><div><h2 class="mb-0">' + esc(val('name')) + '</h2><div class="muted small">' + esc((org() ? 'Organization / Company Supplier' : 'Individual Supplier') + (ful ? ' · ' + ful.parentNode.querySelector('b').textContent : '')) + '</div></div></div>'
      + '<dl class="kv">' + rows.map(function (r) { return '<dt>' + esc(r[0]) + '</dt><dd>' + esc(r[1] || '—') + '</dd>'; }).join('') + '<dt>Documents</dt><dd>' + (docs || '—') + '</dd></dl>'
      + (miss.length ? '<div class="alert alert-danger mt-2"><div><b>Missing required documents:</b> ' + esc(miss.join(', ')) + '. Go back to Documents to upload them.</div></div>'
        : '<div class="alert alert-info mt-2"><div>PartyRent verifies your information and documents. Your application will be <b>Pending Review</b> until an administrator approves it, rejects it or asks for corrections. You keep full access to your customer account meanwhile.</div></div>');
  }
  function draw() {
    applyType();
    steps.forEach(function (s) { s.classList.toggle('hidden', +s.dataset.step !== step); });
    $$('[data-ws]', document).forEach(function (w) { var i = +w.dataset.ws; w.className = 'ws ' + (i === step ? 'on' : i < step ? 'done' : ''); });
    document.getElementById('stepTitle').innerHTML = esc(titles[step]) + (step && type() ? ' <span class="badge ' + (org() ? 'b-purple' : 'b-pink') + ' plain">' + (org() ? 'Organization / Company Supplier' : 'Individual Supplier') + '</span>' : '');
    document.getElementById('back').disabled = step === 0;
    document.getElementById('next').classList.toggle('hidden', step === steps.length - 1);
    document.getElementById('submitApp').classList.toggle('hidden', step !== steps.length - 1);
    if (step === steps.length - 1) review();
  }
  var toast = function (m) { if (window.PR && window.PR.toast) window.PR.toast(m, 'error'); else alert(m); };
  var PHONE_MSG = 'Enter a valid PH mobile number (e.g. 0917 555 0101).';
  // Same format the server accepts: 09XX XXX XXXX, +639XX XXX XXXX or 639XXXXXXXXX.
  $$('[data-phone]').forEach(function (i) { i.pattern = '(\\+?63|0)9\\d{2}[\\s\\-]?\\d{3}[\\s\\-]?\\d{4}'; i.setAttribute('inputmode', 'tel'); i.placeholder = i.placeholder || '0917 555 0101'; });
  // Error text under a field (reuses the server-rendered one when there is one).
  function showErr(i, msg) {
    var fd = i && i.closest('.field'); if (!fd) return;
    var et = fd.querySelector('.error-text'); if (!et) { et = document.createElement('div'); et.className = 'error-text'; fd.appendChild(et); }
    et.textContent = msg; et.style.display = ''; fd.classList.add('invalid');
  }
  function clearErr(i) { var fd = i && i.closest('.field'); if (!fd) return; fd.classList.remove('invalid'); var et = fd.querySelector('.error-text'); if (et) et.style.display = 'none'; }
  // "app.personal.phone" → the input named app[personal][phone] (or methods[] for "methods.0").
  function inputFor(key) {
    var p = key.split('.'); if (/^\d+$/.test(p[p.length - 1])) p.pop();
    var n = p[0] + p.slice(1).map(function (x) { return '[' + x + ']'; }).join('');
    return f.querySelector('[name="' + n + '"]:not([disabled])') || f.querySelector('[name="' + n + '[]"]:not([disabled])');
  }
  function showServerErrors(errs) {
    var first = null;
    Object.keys(errs || {}).forEach(function (k) { var m = [].concat(errs[k])[0], i = inputFor(k); showErr(i, m); if (!first) first = { i: i, m: m }; });
    if (first) { toast(first.m); if (first.i && first.i.type !== 'hidden') { first.i.focus({ preventScroll: true }); first.i.scrollIntoView({ block: 'center', behavior: 'smooth' }); } }
  }
  function valid() {
    var ok = true, firstBad = null;
    $$('input,select,textarea', steps[step]).forEach(function (i) {
      if (i.disabled || i.type === 'file' || i.closest('[hidden], .hidden, .is-closed')) return;
      if (!i.checkValidity()) { ok = false; showErr(i, i.hasAttribute('data-phone') && i.validity.patternMismatch ? PHONE_MSG : i.validationMessage); firstBad = firstBad || i; } else clearErr(i);
    });
    if (step === 0 && !type()) { toast('Choose Individual Supplier or Organization / Company Supplier.'); return false; }
    if (step === 2 && missingDocs().length) { toast('Please upload: ' + missingDocs().join(', ') + '.'); return false; }
    if (step === 4) { if (!f.querySelector('[name="methods[]"]:checked')) { toast('Select at least one payment method.'); return false; } }
    if (!ok) { toast('Please fix the highlighted fields before continuing.'); firstBad.focus({ preventScroll: true }); firstBad.scrollIntoView({ block: 'center', behavior: 'smooth' }); }
    return ok;
  }
  // Ask the server to check this step with the same rules as the final submit (documents are checked above, not uploaded yet).
  function serverCheck() {
    var fd = new FormData(f);
    Array.from(fd.keys()).forEach(function (k) { if (k.indexOf('docs[') === 0) fd.delete(k); });
    fd.append('step', step);
    return fetch(@json(route('start-selling.check')), { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.json().catch(function () { return {}; }).then(function (d) { return { ok: r.ok, status: r.status, d: d }; }); });
  }
  f.addEventListener('input', function (e) { if (e.target.closest('.field.invalid') && e.target.checkValidity()) clearErr(e.target); });
  f.addEventListener('change', function (e) {
    if (e.target.matches('[data-doc]')) { var file = e.target.files[0], row = e.target.closest('[data-docrow]'), b = row.querySelector('[data-docname]');
      if (file && file.size > 10 * 1024 * 1024) { toast('Files must be 10 MB or smaller.'); e.target.value = ''; return; }
      if (file) { b.hidden = false; b.querySelector('span').textContent = file.name; } else if (b.dataset.had !== '1') b.hidden = true; }
    if (e.target.matches('[data-type], #ctype, [data-applies], [name=fulfillment]')) draw();
  });
  var nextBtn = document.getElementById('next');
  nextBtn.addEventListener('click', function () {
    if (!valid()) return;
    nextBtn.disabled = true;
    serverCheck().then(function (res) {
      nextBtn.disabled = false;
      if (res.ok) { step++; draw(); window.scrollTo({ top: 0, behavior: 'smooth' }); return; }
      if (res.status === 422 && res.d.errors) {
        if (typeof res.d.step === 'number' && res.d.step < step) { step = res.d.step; draw(); }
        showServerErrors(res.d.errors); return;
      }
      toast(res.status === 419 ? 'Your session expired. Reload the page and sign in again.' : 'Could not check this step. Please try again.');
    }).catch(function () { nextBtn.disabled = false; toast('Could not check this step. Check your connection and try again.'); });
  });
  document.getElementById('back').addEventListener('click', function () { step--; draw(); });
  @if($errors->any())
    step = {{ $errorStep }};
  @elseif($existing)
    step = 1;
  @endif
  draw();
  @if($errors->any())
    showServerErrors(@json($errors->toArray()));
  @endif
})();
</script>
@endpush
@endsection
