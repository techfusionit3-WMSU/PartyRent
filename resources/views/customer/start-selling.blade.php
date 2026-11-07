@extends('layouts.market', ['title' => 'Start Selling', 'nav' => 'profile'])

@section('content')
<div class="page-head"><div><h1>Start Selling on PartyRent</h1><p>Turn your party supplies into income — using your existing account.
  @if($existing && $existing->status === 'rejected')<span class="badge b-red">Previous application rejected: {{ $existing->reject_reason }}</span>@endif</p></div></div>
<div class="wizard-steps" id="wsteps">@foreach(['Business info', 'Contact', 'Fulfillment', 'Payments', 'Policies', 'Review'] as $i => $t)<div class="ws {{ $i === 0 ? 'on' : '' }}" data-ws="{{ $i }}"><span>Step {{ $i + 1 }}</span>{{ $t }}</div>@endforeach</div>
<form class="card" id="wf" method="POST" action="{{ route('start-selling.submit') }}" style="max-width:760px" novalidate>
  @csrf
  @include('partials.shop-fields')
  <section data-step="5">
    <h2>Review & submit</h2>
    <p>Check the details in the previous steps. Your supplier profile will be linked to this account ({{ auth()->user()->email }}) and you can keep renting as a customer.</p>
    @if(setting('requireSupplierApproval', true))<div class="alert alert-info mt-2">{{ icon('info') }}<div>PartyRent reviews new suppliers. Your application will be <b>Pending Review</b> until an admin approves it. You keep full access to your customer account meanwhile.</div></div>@endif
  </section>
  <div class="row between mt-3"><button type="button" class="btn" id="back">{{ icon('left') }}Back</button><button type="button" class="btn btn-primary" id="next">Continue{{ icon('right') }}</button><button class="btn btn-primary hidden" id="submitApp" data-busy="Submitting…">Submit application</button></div>
</form>
@push('scripts')
<script>
(function () {
  var f = document.getElementById('wf'), steps = f.querySelectorAll('[data-step]'), step = 0;
  @if($errors->any())
    // jump to the first step with an error
    var bad = f.querySelector('.error-text'); if (bad) { var s = bad.closest('[data-step]'); if (s) step = +s.dataset.step; }
  @endif
  function draw() {
    steps.forEach(function (s) { s.classList.toggle('hidden', +s.dataset.step !== step); });
    document.querySelectorAll('[data-ws]').forEach(function (w) { var i = +w.dataset.ws; w.className = 'ws ' + (i === step ? 'on' : i < step ? 'done' : ''); });
    document.getElementById('back').disabled = step === 0;
    document.getElementById('next').classList.toggle('hidden', step === steps.length - 1);
    document.getElementById('submitApp').classList.toggle('hidden', step !== steps.length - 1);
  }
  function valid() {
    var ok = true; steps[step].querySelectorAll('input,select,textarea').forEach(function (i) { if (!i.checkValidity()) { ok = false; i.closest('.field') && i.closest('.field').classList.add('invalid'); } else { i.closest('.field') && i.closest('.field').classList.remove('invalid'); } });
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
