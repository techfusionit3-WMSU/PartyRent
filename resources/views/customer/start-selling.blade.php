@extends('layouts.market', ['title' => 'Apply as a Supplier', 'nav' => 'profile'])

{{-- Reference C.startSelling: Individual / Organization application wizard (8 steps, one form). --}}
@php
    use App\Domain\Applications as A;
    $o = fn ($k, $def = null) => old($k, data_get($d, $k, $def));
    $ao = fn ($k, $def = '') => old('app.'.$k, data_get($app, $k, $def));
    $err = fn ($k) => $errors->has($k) ? '<div class="error-text" style="display:block">'.e($errors->first($k)).'</div>' : '';
    $type = old('app.type', $app['type'] ?? '');
    $ctype = $ao('company.companyType', '') ?: null; // no longer asked; kept for applications that already chose one
    $extra = array_values((array) old('app.branches', $app['branches'] ?? []));
    $methods = old('methods', $d['methods'] ?? ['cash', 'bank']);
    $pol = $d['policy'] ?? [];
    $applicable = old('app.applicable', $app['applicable'] ?? []);
    $docs = (array) ($app['docs'] ?? []);
    $bp = (array) ($d['business_profile'] ?? []);
    // Event-company module off: an Organization / Company supplier is a rental company and fills in the same shop
    // details, delivery, payments and policies as an individual (plus company papers and a representative).
    $shopFor = $eventCo ? 'individual' : 'individual organization';
    $evtFor = $eventCo ? 'organization' : 'event-company';
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
<div class="wiz-bar" style="max-width:820px" role="progressbar" aria-label="{{ __('Application progress') }}" aria-valuemin="1" aria-valuemax="{{ count($steps) }}" aria-valuenow="1"><span id="wizBar" style="width:{{ round(100 / count($steps)) }}%"></span></div>
<div class="wizard-steps">@foreach($steps as $i => $t)<div class="ws {{ $i === 0 ? 'on' : '' }}" data-ws="{{ $i }}"><span>Step {{ $i + 1 }}</span>{{ $t }}</div>@endforeach</div>

<form class="card" id="wf" data-draft="start-selling" method="POST" action="{{ route('start-selling.submit') }}" enctype="multipart/form-data" style="max-width:820px" novalidate
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
        <div style="font-size:2rem">🏢</div><h3 class="mt-1">Organization / Company Supplier</h3><p class="small muted">{{ $eventCo ? 'For corporations, partnerships, cooperatives and companies with employees or branches.' : 'For rental companies, corporations, partnerships and cooperatives with employees, warehouses or branches.' }}</p>
        <ul class="small"><li>Company registration documents (SEC / DTI / CDA)</li><li>BIR registration and business permits</li><li>Authorized representative's ID and proof of authority</li></ul><span class="badge b-purple">{{ $eventCo ? 'Multiple employee accounts' : 'Employee accounts & branches' }}</span></label>
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
        @if($ctype)<input type="hidden" name="app[company][companyType]" value="{{ $ctype }}">@endif
        {!! $fld('Registered business address (as shown on your permits)', 'app[company][address]', $ao('company.address'), 'required minlength="8"', 'full') !!}
        {!! $fld('Official company email', 'app[company][email]', $ao('company.email'), 'type="email" required') !!}
        {!! $fld('Company contact number', 'app[company][phone]', $ao('company.phone'), 'required data-phone') !!}
        {!! $fld('What your business does', 'app[company][natureOfBusiness]', $ao('company.natureOfBusiness', 'Party and event equipment rental'), 'required', 'full') !!}
        {!! $fld('Website or social media page (optional)', 'app[company][website]', $ao('company.website'), '', 'full') !!}
      </div>
      <h3 class="mt-2">Authorized representative</h3>
      <p class="small muted">The person filling in this application. Once approved, they manage the shop account and can add staff.</p>
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
    <div data-for="organization">{!! $docRows(A::requirements('organization', $ctype)) !!}</div>
  </section>

  {{-- 4 · Shop details --}}
  <section data-step="3">
    <div class="field"><label data-org-label="Shop / brand name on PartyRent">Business or rental shop name</label><input class="input" name="name" required minlength="3" value="{{ $o('name') }}" placeholder="e.g. Santos Party Rentals">{!! $err('name') !!}</div>
    <div class="field"><label data-org-label="About us">Shop description</label><textarea class="input" name="description" required minlength="20" placeholder="Tell customers about your shop: what you offer and for which events.">{{ $o('description', $ao('company.description')) }}</textarea>{!! $err('description') !!}</div>
    <div data-for="{{ $evtFor }}">
      <h3 class="mt-2 mb-1">What your business offers</h3>
      <p class="small muted mt-0">Customers and the AI planner use this to find you. Pick suggestions or type your own.</p>
      <div class="field @error('events_catered') invalid @enderror" data-tagfield="events_catered"><label>Events you cater</label>@include('partials.tag-input', ['name' => 'events_catered', 'values' => old('events_catered', $bp['events'] ?? []), 'suggest' => array_keys(\App\Domain\Ai::EVENTS), 'placeholder' => 'Type an event, e.g. Graduation'])<div class="error-text" @error('events_catered') style="display:block" @enderror>{{ $errors->first('events_catered') ?: 'Add at least one type of event.' }}</div></div>
      <div class="field @error('offers') invalid @enderror" data-tagfield="offers"><label>Services you offer</label>@include('partials.tag-input', ['name' => 'offers', 'values' => old('offers', $bp['offers'] ?? []), 'suggest' => \App\Models\Supplier::OFFER_SUGGESTIONS, 'placeholder' => 'Type a service, e.g. Balloon styling'])<div class="error-text" @error('offers') style="display:block" @enderror>{{ $errors->first('offers') ?: 'Add at least one service.' }}</div></div>
      <div class="form-grid">
        <div class="field"><label>Event size you can handle</label><select class="input" name="event_size" required><option value="">Choose…</option>@foreach(\App\Models\Supplier::EVENT_SIZES as $k => $l)<option value="{{ $k }}" @selected(old('event_size', $bp['eventSize'] ?? '') === $k)>{{ $l }}</option>@endforeach</select>{!! $err('event_size') !!}</div>
        <div class="field"><label>Packages start at (₱) <span class="muted small">(optional)</span></label><input class="input" type="number" min="0" step="1" name="packages_from" value="{{ old('packages_from', $bp['packagesFrom'] ?? '') }}" placeholder="e.g. 15000">{!! $err('packages_from') !!}</div>
      </div>
      @include('partials.business-photos', ['photos' => $photos])
    </div>
    <div class="field" data-for="{{ $shopFor }}"><label>Types of rental products offered</label><input class="input" name="app[business][productTypes]" required value="{{ $ao('business.productTypes') }}" placeholder="e.g. chairs, tables, tents, sound system"></div>
    <div class="form-grid">
      <div class="field"><label>Business contact number</label><input class="input" name="phone" data-phone required value="{{ $o('phone') }}">{!! $err('phone') !!}</div>
      <div class="field"><label>Business email</label><input class="input" type="email" name="email" required value="{{ $o('email') }}">{!! $err('email') !!}</div>
      <div class="field full"><label>Operating hours</label><input class="input" name="hours" required value="{{ $o('hours') }}"></div>
    </div>
    @include('partials.location-fields', ['locFor' => 'either'])
    {{-- the address above is the main branch; more branches are optional (each keeps its own stock once approved) --}}
    <div class="card card-flat mt-2" style="padding:14px" data-branches>
      <h3 class="mt-0 mb-1">Do you have other branches? <span class="muted small">(optional)</span></h3>
      <p class="small muted mt-0">The address above is your main branch. If you also rent from other places, add them here. Customers will still see one store. You can also add or change branches later from your dashboard.</p>
      <div data-branch-rows>
        @foreach($extra as $i => $b)@include('partials.branch-fields', ['i' => $i, 'b' => $b])@endforeach
      </div>
      <template data-branch-tpl>@include('partials.branch-fields', ['i' => '__i__', 'b' => []])</template>
      <button type="button" class="btn btn-sm" data-branch-add>{{ icon('plus') }}Add another branch</button>
    </div>
    <div class="field"><label>Business logo</label><div class="row wrap gap-sm">@foreach($logos as $l)<label class="chip {{ $o('logo', '🎈') === $l ? 'active' : '' }}" style="font-size:1.3rem"><input type="radio" name="logo" value="{{ $l }}" @checked($o('logo', '🎈') === $l) hidden>{{ $l }}</label>@endforeach</div></div>
  </section>

  {{-- 5 · Delivery & payments --}}
  <section data-step="4">
    <div data-for="{{ $evtFor }}">
      <div class="alert alert-info mb-2">{{ icon('info') }}<div class="small">As an event company you set up on-site at the customer's venue, so there is no delivery or pickup. Customers book you by asking for a quotation; you set the price per event.</div></div>
      @php($bset = array_replace(\App\Domain\Events::SETTINGS, (array) data_get($d, 'business_profile.settings', [])))
      <div class="field @error('serviceAreas') invalid @enderror" data-tagfield="serviceAreas"><label>Cities and areas you serve</label>@include('partials.tag-input', ['name' => 'serviceAreas', 'values' => old('serviceAreas', $bset['serviceAreas']), 'suggest' => [], 'placeholder' => 'Type a city and press Enter, e.g. Zamboanga City'])<div class="error-text" @error('serviceAreas') style="display:block" @enderror>{{ $errors->first('serviceAreas') ?: 'Add at least one city or area.' }}</div></div>
      <h3 class="mt-2 mb-1">Payment schedule for bookings</h3>
      <p class="small muted mt-0">Customers pay to your payout account (next step) in parts. You can change this later and per quotation.</p>
      <div class="form-grid">
        <div class="field"><label>Reservation fee (% to hold the date)</label><input class="input" type="number" min="0" max="100" step="0.5" name="reservePct" required value="{{ old('reservePct', $bset['reservePct']) }}">{!! $err('reservePct') !!}</div>
        <div class="field"><label>Deposit (% due {{ $bset['depositDays'] }} days before)</label><input class="input" type="number" min="0" max="100" step="0.5" name="depositPct" required value="{{ old('depositPct', $bset['depositPct']) }}">{!! $err('depositPct') !!}<div class="hint">The rest is the balance, due {{ $bset['balanceDays'] }} days before the event.</div></div>
      </div>
    </div>
    <div data-for="{{ $shopFor }}">
    <div class="stack-sm">@foreach([['delivery', 'Delivery only', 'You bring items to the customer.'], ['pickup', 'Pickup only', 'Customers collect from your location.'], ['both', 'Both delivery and pickup', 'Let customers choose.']] as [$v, $l, $desc])
      <label class="card card-flat row" style="padding:12px 14px;cursor:pointer"><input type="radio" name="fulfillment" value="{{ $v }}" @checked($o('fulfillment', 'both') === $v) style="accent-color:var(--brand);width:18px;height:18px"><div><b>{{ $l }}</b><div class="small muted">{{ $desc }}</div></div></label>
    @endforeach</div>{!! $err('fulfillment') !!}
    <div class="field"><label>Accepted payment methods</label>
      @foreach(['cash' => 'Cash', 'bank' => 'Online Bank Transfer', 'debit' => 'Debit'] as $k => $l)<label class="check"><input type="checkbox" name="methods[]" value="{{ $k }}" @checked(in_array($k, $methods, true))> {{ $l }}</label>@endforeach
      {!! $err('methods') !!}</div>
    </div>
    <div class="alert alert-info" data-for="{{ $shopFor }}">{{ icon('info') }}<div class="small">No delivery fee, down payment or deposit percentages are asked here. They differ per item, event and situation, so you set them in Shop Settings after approval and when you post each package.</div></div>
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
    <div class="field" data-for="{{ $shopFor }}"><label>Free cancellation window (days before rental)</label><input class="input" type="number" min="0" max="30" name="cancelDays" value="{{ old('cancelDays', $pol['cancelDays'] ?? 3) }}"></div>
    <div class="field"><label>Cancellation policy</label><textarea class="input" name="cancellation" required>{{ old('cancellation', $pol['cancellation'] ?? '') }}</textarea>{!! $err('cancellation') !!}</div>
    <div class="field" data-for="{{ $shopFor }}"><label>Return policy</label><textarea class="input" name="returns" required>{{ old('returns', $pol['returns'] ?? '') }}</textarea></div>
    <div class="field" data-for="{{ $shopFor }}"><label>Rental conditions</label><textarea class="input" name="conditions" required>{{ old('conditions', $pol['conditions'] ?? '') }}</textarea></div>
    <div class="field" data-for="{{ $evtFor }}"><label>Rescheduling policy</label><textarea class="input" name="rescheduling" required minlength="10" placeholder="e.g. One free date change if asked at least 14 days before the event.">{{ old('rescheduling', $pol['rescheduling'] ?? 'One free date change if asked at least 14 days before the event, subject to availability.') }}</textarea>{!! $err('rescheduling') !!}</div>
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
  var f = document.getElementById('wf'), steps = f.querySelectorAll('section[data-step]'), indTitles = @json($steps), orgTitles = @json($eventCo ? \App\Http\Controllers\Customer\StartSellingController::ORG_STEPS : \App\Http\Controllers\Customer\StartSellingController::RENTAL_CO_STEPS), eventCo = @json($eventCo), titles = indTitles, step = 0;
  var $$ = function (s, r) { return Array.prototype.slice.call((r || f).querySelectorAll(s)); };
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var val = function (n) { var i = f.querySelector('[name="' + n + '"]'); return i ? i.value.trim() : ''; };
  var type = function () { var r = f.querySelector('[data-type]:checked'); return r ? r.value : ''; };
  var org = function () { return type() === 'organization'; };
  var evt = function () { return org() && eventCo; }; // event company (module on) vs rental company
  // Show the fields for the chosen supplier type (and company type); hidden fields are disabled so they are not validated or sent.
  function applyType() {
    var t = type(), ct = (f.querySelector('#ctype') || {}).value;
    $$('[data-for]').forEach(function (el) {
      var on = el.dataset.for.split(' ').indexOf(t) >= 0 && (!el.dataset.ctype || el.dataset.ctype === ct);
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
    var nb = $$('[data-branch-row]').filter(function (r) { return r.querySelector('[name$="[name]"]').value.trim(); }).length;
    if (org()) { rows.push(['Company', val('app[company][legalName]')]); rows.push(['Representative', val('app[representative][name]') + ', ' + val('app[representative][position]') + ' · ' + val('app[representative][email]')]); }
    else { rows.push(['Applicant', val('app[personal][fullName]') + ' · born ' + (val('app[personal][birthDate]') || '—')]); rows.push(['ID', val('app[identity][idType]') + ' · ' + val('app[identity][idNumber]')]); }
    rows.push(['Shop contact', val('phone') + ' · ' + val('email')]);
    rows.push(['Branches', nb ? (1 + nb) + ' (main + ' + nb + ' more: ' + $$('[data-branch-row]').map(function (r) { return r.querySelector('[name$="[name]"]').value.trim(); }).filter(Boolean).join(', ') + ')' : 'One (the address above)']);
    rows.push(['Address', [val('shop_addr[street]'), val('shop_addr[barangay]')].filter(Boolean).join(', ')]);
    if (evt()) {
      rows.push(['Serves', $$('[name="serviceAreas[]"]:not([disabled])').map(function (i) { return i.value; }).join(', ')]);
      rows.push(['Payments', val('reservePct') + '% to reserve · ' + val('depositPct') + '% deposit · rest before the event']);
    } else rows.push(['Methods', $$('[name="methods[]"]:checked').map(function (c) { return c.parentNode.textContent.trim(); }).join(', ')]);
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
    titles = org() ? orgTitles : indTitles;
    $$('[data-ws]', document).forEach(function (w) { w.lastChild.textContent = titles[+w.dataset.ws]; });
    steps.forEach(function (s) { s.classList.toggle('hidden', +s.dataset.step !== step); });
    $$('[data-ws]', document).forEach(function (w) { var i = +w.dataset.ws; w.className = 'ws ' + (i === step ? 'on' : i < step ? 'done' : ''); });
    var bar = document.getElementById('wizBar'); if (bar) { bar.style.width = Math.round((step + 1) / steps.length * 100) + '%'; bar.parentNode.setAttribute('aria-valuenow', step + 1); }
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
    if (step === 3 && evt()) {
      var bad = null;
      ['events_catered', 'offers'].forEach(function (n) { var fd = f.querySelector('[data-tagfield="' + n + '"]'), none = !f.querySelector('[name="' + n + '[]"]:not([disabled])'); fd.classList.toggle('invalid', none); if (none && !bad) bad = fd; });
      if (bad) { toast(bad.querySelector('.error-text').textContent); bad.scrollIntoView({ block: 'center', behavior: 'smooth' }); return false; }
      var pc = window.PR_portfolioCount ? window.PR_portfolioCount() : 0;
      if (pc < {{ \App\Models\Supplier::PORTFOLIO_MIN }}) { toast('Add at least {{ \App\Models\Supplier::PORTFOLIO_MIN }} photos of events you have done (you have ' + pc + ').'); var pf = f.querySelector('[data-portfolio]'); if (pf) pf.scrollIntoView({ block: 'center', behavior: 'smooth' }); return false; }
    }
    if (step === 4 && !evt()) { if (!f.querySelector('[name="methods[]"]:checked')) { toast('Select at least one payment method.'); return false; } }
    if (step === 4 && evt()) {
      var sa = f.querySelector('[data-tagfield="serviceAreas"]'), none = !f.querySelector('[name="serviceAreas[]"]:not([disabled])');
      sa.classList.toggle('invalid', none); if (none) { toast('Add at least one city or area you serve.'); return false; }
      if ((+val('reservePct') || 0) + (+val('depositPct') || 0) > 100) { toast('The reservation fee and deposit together cannot be more than 100%.'); return false; }
    }
    if (!ok) { toast('Please fix the highlighted fields before continuing.'); firstBad.focus({ preventScroll: true }); firstBad.scrollIntoView({ block: 'center', behavior: 'smooth' }); }
    return ok;
  }
  // Ask the server to check this step with the same rules as the final submit (documents are checked above, not uploaded yet).
  function serverCheck() {
    var fd = new FormData(f);
    Array.from(fd.keys()).forEach(function (k) { if (k.indexOf('docs[') === 0 || k.indexOf('portfolio[') === 0 || k === 'logo_file' || k === 'cover_file') fd.delete(k); });
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
  // other branches: numbered after the main one (Branch 2, Branch 3…); add / remove rows
  function numberBranches() { f.querySelectorAll('[data-branch-rows] [data-branch-title]').forEach(function (t, i) { t.textContent = 'Branch ' + (i + 2); }); }
  numberBranches();
  f.addEventListener('click', function (e) {
    if (e.target.closest('[data-branch-add]')) { var box = f.querySelector('[data-branch-rows]'); if (box.children.length >= 20) return toast('Up to 20 branches here; add more later from your dashboard.'); box.insertAdjacentHTML('beforeend', f.querySelector('[data-branch-tpl]').innerHTML.replace(/__i__/g, 'n' + Date.now())); var row = box.lastElementChild; if (window.PR && window.PR.mountPlaces) window.PR.mountPlaces(row); applyType(); row.querySelector('input').focus(); }
    var rm = e.target.closest('[data-branch-rm]'); if (rm) rm.closest('[data-branch-row]').remove();
    numberBranches();
  });
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
