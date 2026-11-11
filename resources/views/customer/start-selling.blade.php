@extends('layouts.market', ['title' => 'Start Selling', 'nav' => 'profile'])

@section('content')
<div class="page-head"><div><h1>Start Selling on PartyRent</h1><p>Turn your party supplies into income — using your existing account.
  @if($existing && $existing->status === 'rejected')<span class="badge b-red">Previous application rejected: {{ $existing->reject_reason }}</span>@endif</p></div></div>
<div class="wizard-steps" id="wsteps">@foreach(['Business info', 'Contact', 'Fulfillment', 'Payments', 'Policies', 'Review'] as $i => $t)<div class="ws {{ $i === 0 ? 'on' : '' }}" data-ws="{{ $i }}"><span>Step {{ $i + 1 }}</span>{{ $t }}</div>@endforeach</div>
<form class="card" id="wf" method="POST" action="{{ route('start-selling.submit') }}" style="max-width:760px" novalidate data-confirm-title="Submit supplier application?" data-confirm="Your supplier profile will be linked to this account ({{ auth()->user()->email }}). You can keep renting as a customer." data-confirm-ok="Submit">
  @csrf
  @include('partials.shop-fields')
  <section data-step="5">
    <h2>Review</h2>
    <div class="review-sum" id="reviewSum"></div>
    <p class="small muted">Check the details, or go back to change them. Your supplier profile will be linked to this account ({{ auth()->user()->email }}) and you can keep renting as a customer.</p>
    @if(setting('requireSupplierApproval', true))<div class="alert alert-info mt-2">{{ icon('info') }}<div>PartyRent reviews new suppliers. Your application will be <b>Pending Review</b> until an admin approves it. You keep full access to your customer account meanwhile.</div></div>@endif
  </section>
  <div class="row between mt-3"><button type="button" class="btn" id="back">{{ icon('left') }}Back</button><button type="button" class="btn btn-primary" id="next">Continue{{ icon('right') }}</button><button class="btn btn-primary hidden" id="submitApp" data-busy="Submitting…">Submit application{{ icon('right') }}</button></div>
</form>
@push('head')<style>
.review-sum .rs-head { display: flex; align-items: center; gap: 12px; margin-bottom: 8px; }
.review-sum .rs-head h2 { margin: 0; }
.review-sum dl { display: grid; grid-template-columns: minmax(120px, 180px) minmax(0, 1fr); gap: 6px 16px; margin: 8px 0 16px; padding: 14px 16px; border: 1px solid var(--line); border-radius: var(--radius-sm); background: var(--surface-2); }
.review-sum dt { color: var(--muted); font-size: .86rem; font-weight: 600; }
.review-sum dd { margin: 0; overflow-wrap: anywhere; }
@media (max-width: 560px) { .review-sum dl { grid-template-columns: 1fr; gap: 2px; } .review-sum dd { margin-bottom: 8px; } }
</style>@endpush
@push('scripts')
<script>
(function () {
  var f = document.getElementById('wf'), steps = f.querySelectorAll('[data-step]'), step = 0;
  @if($errors->any())
    // jump to the first step with an error
    var bad = f.querySelector('.error-text[style*="block"]'); if (bad) { var s = bad.closest('[data-step]'); if (s) step = +s.dataset.step; }
  @endif
  function draw() {
    steps.forEach(function (s) { s.classList.toggle('hidden', +s.dataset.step !== step); });
    document.querySelectorAll('[data-ws]').forEach(function (w) { var i = +w.dataset.ws; w.className = 'ws ' + (i === step ? 'on' : i < step ? 'done' : ''); });
    document.getElementById('back').disabled = step === 0;
    document.getElementById('next').classList.toggle('hidden', step === steps.length - 1);
    document.getElementById('submitApp').classList.toggle('hidden', step !== steps.length - 1);
    if (step === steps.length - 1) review();
  }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function val(n) { var i = f.querySelector('[name="' + n + '"]'); return i ? i.value : ''; }
  function peso(n) { return '₱' + (Math.round((+n || 0) * 100) / 100).toLocaleString('en-PH', { maximumFractionDigits: 2 }); }
  function amount(type, v) { return type === 'percent' ? (+v || 0) + '%' : peso(v); }
  function labels(n) { return Array.prototype.map.call(f.querySelectorAll('[name="' + n + '[]"]:checked'), function (c) { return c.closest('label').textContent.trim(); }); }
  // Review: everything the applicant entered, like the prototype (only the policies for the arrangements offered).
  function optText(n) { var i = f.querySelector('[name="' + n + '"]'); return i && i.tagName === 'SELECT' && i.selectedIndex >= 0 && i.value ? i.options[i.selectedIndex].text : ''; }
  function checked(n) { var i = f.querySelector('[name="' + n + '"]:checked'); return i ? i.value : val(n); }
  function radioLabel(n) { var i = f.querySelector('[name="' + n + '"]:checked'); var l = i && i.closest('label'); return l ? (l.querySelector('b') || l).textContent.trim() : ''; }
  function review() {
    var on = function (a) { return !!f.querySelector('[data-arr="' + a + '"]:checked'); }, rows = [];
    var city = optText('shop_addr[cityCode]'), ful = radioLabel('fulfillment');
    var addr = [val('shop_addr[street]'), val('shop_addr[barangay]'), city].filter(Boolean).join(', ');
    rows.push(['Contact', [val('contact_person'), val('phone'), val('email')].filter(Boolean).join(' · ')]);
    if (addr) rows.push(['Address', addr]);
    rows.push(['Hours', val('hours')]);
    if (checked('fulfillment') !== 'pickup') {
      var mode = checked('delivery[mode]'), dl;
      if (mode === 'zones') { var n = f.querySelectorAll('#zoneRows .zone-row').length; dl = 'Your own fee by area (' + n + ' area' + (n === 1 ? '' : 's') + ')'; }
      else if (mode === 'distance') dl = 'Your own fee: ' + peso(val('delivery[baseFee]')) + ' + ' + peso(val('delivery[perKm]')) + ' per km';
      else dl = 'PartyRent standard rates (by weight) · ' + (optText('delivery[reach]') || '').toLowerCase();
      if (+val('delivery[freeOver]') > 0) dl += ' · free on items from ' + peso(val('delivery[freeOver]'));
      rows.push(['Delivery fee', dl]);
      var fs = f.querySelector('input[type=checkbox][name="free_ship"]');
      if (fs) rows.push(['Free Shipping program', fs.checked ? 'Joined' : 'Not joined']);
    }
    rows.push(['Methods', labels('methods').join(', ')]);
    rows.push(['Arrangements', labels('arrangements').join(', ')]);
    if (on('reservation')) { var cr = f.querySelector('[name="reservationCredited"]:checked'); rows.push(['Reservation fee', peso(val('reservationFee')) + ((!cr || cr.value === '1') ? ', deducted from the down payment' : ', charged on top (non-refundable)')]); }
    else rows.push(['Reservation fee', 'None']);
    if (on('down')) rows.push(['Down payment', amount(val('downType'), val('downValue')) + (val('downType') === 'percent' ? ' of the rental' : '') + ' upfront']);
    if (on('installment')) rows.push(['Installments', (+val('installments') || 0) + ' payments']);
    rows.push(['Deposit', (+val('depositValue') > 0 ? amount(val('depositType'), val('depositValue')) + (val('depositType') === 'percent' ? ' of items' : '') + ', refundable' : 'None')]);
    rows.push(['Cancellation', val('cancellation') + ' (' + (+val('cancelDays') || 0) + ' days)']);
    rows.push(['Returns', val('returns')]);
    rows.push(['Conditions', val('conditions')]);
    var head = '<div class="rs-head"><div class="logo-tile" style="background:var(--brand-50)">' + esc(checked('logo')) + '</div><div><h2>' + esc(val('name')) + '</h2><div class="small muted">' + esc([city, ful].filter(Boolean).join(' · ')) + '</div></div></div>';
    document.getElementById('reviewSum').innerHTML = head + (val('description') ? '<p>' + esc(val('description')) + '</p>' : '') + '<dl>' + rows.map(function (r) { return '<dt>' + esc(r[0]) + '</dt><dd>' + esc(r[1] || '—') + '</dd>'; }).join('') + '</dl>';
  }
  function valid() {
    var ok = true; steps[step].querySelectorAll('input,select,textarea').forEach(function (i) { if (i.closest('.is-closed, .hidden')) return; if (!i.checkValidity()) { ok = false; i.closest('.field') && i.closest('.field').classList.add('invalid'); } else { i.closest('.field') && i.closest('.field').classList.remove('invalid'); } });
    if (step === 3) { if (!f.querySelector('[name="methods[]"]:checked')) { ok = false; window.PRUI.toast('Select at least one payment method.', 'error'); } if (!f.querySelector('[name="arrangements[]"]:checked')) { ok = false; window.PRUI.toast('Select at least one payment arrangement.', 'error'); } }
    return ok;
  }
  document.getElementById('next').addEventListener('click', function () { if (!valid()) return; step++; draw(); window.scrollTo({ top: 0, behavior: 'smooth' }); });
  document.getElementById('back').addEventListener('click', function () { step--; draw(); });
  draw();
})();
</script>
@endpush
@endsection
