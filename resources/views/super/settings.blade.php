@extends('layouts.dash', ['title' => 'System Settings', 'nav' => 'settings'])

@section('content')
<div class="page-head"><div><h1>System settings</h1><p>Platform-wide rules. Every change is written to the audit log.</p></div></div>
<form method="POST" action="{{ route('super.settings.update') }}" class="stack" style="max-width:760px" enctype="multipart/form-data">
  @csrf
  <div class="card"><h3>Platform</h3><div class="form-grid">
    <div class="field"><label>Platform name</label><input class="input" name="platformName" required value="{{ old('platformName', $s['platformName']) }}"></div>
    <div class="field"><label>Commission (%)</label><input class="input" type="number" min="0" max="50" step="0.1" name="commissionPct" required value="{{ old('commissionPct', $s['commissionPct']) }}"><div class="hint">Deducted from supplier payouts when a rental is completed. Payouts already released keep their rate.</div></div>
    <div class="field"><label>Support email</label><input class="input" type="email" name="supportEmail" required value="{{ old('supportEmail', $s['supportEmail']) }}"></div>
    <div class="field"><label>Support phone</label><input class="input" name="supportPhone" required value="{{ old('supportPhone', $s['supportPhone']) }}"></div>
  </div></div>
  <div class="card"><h3>Rentals & payments</h3><div class="form-grid">
    <div class="field"><label>Overdue grace period (days)</label><input class="input" type="number" min="0" max="30" name="overdueGraceDays" required value="{{ old('overdueGraceDays', $s['overdueGraceDays']) }}"><div class="hint">Days after a due date before a payment counts as overdue.</div></div>
    <div class="field"><label>Maximum rental dates per item</label><input class="input" type="number" min="1" max="90" name="maxRentalDays" required value="{{ old('maxRentalDays', $s['maxRentalDays']) }}"></div>
  </div>
    <label class="check"><input type="checkbox" name="requireSupplierApproval" value="1" @checked($s['requireSupplierApproval'])> New suppliers need admin approval before going live</label>
    <label class="check"><input type="checkbox" name="aiEnabled" value="1" @checked($s['aiEnabled'])> PartyRent Assist (AI chat & planner) is enabled</label>
  </div>
  <div class="card"><h3>Where customers send payments</h3>
    <p class="small muted">PartyRent's own accounts, shown in the customer's payment form for transfers. Customers pay PartyRent, never the supplier. Leave a field empty to hide it.</p>
    <div class="form-grid">
      <div class="field"><label>GCash number</label><input class="input" name="payGcashNumber" maxlength="40" value="{{ old('payGcashNumber', $s['payGcashNumber']) }}" placeholder="0917 123 4567"></div>
      <div class="field"><label>GCash account name</label><input class="input" name="payGcashName" maxlength="80" value="{{ old('payGcashName', $s['payGcashName']) }}"></div>
      <div class="field"><label>Bank</label><input class="input" name="payBankName" maxlength="60" value="{{ old('payBankName', $s['payBankName']) }}" placeholder="BDO, BPI…"></div>
      <div class="field"><label>Bank account name</label><input class="input" name="payBankAccountName" maxlength="80" value="{{ old('payBankAccountName', $s['payBankAccountName']) }}"></div>
      <div class="field"><label>Bank account number</label><input class="input" name="payBankAccountNo" maxlength="40" value="{{ old('payBankAccountNo', $s['payBankAccountNo']) }}"></div>
      <div class="field"><label>Payment QR code (GCash / Maya / QR Ph)</label><input class="input" type="file" name="payQr" accept="image/*">
        @if($s['payQrPath'])<div class="row mt-1" style="gap:10px"><img src="{{ route('payments.qr') }}" alt="Current QR code" style="width:72px;height:72px;object-fit:contain;background:#fff;border-radius:6px"><label class="check"><input type="checkbox" name="removeQr" value="1"> Remove QR code</label></div>@endif</div>
    </div>
    <div class="hint">Reference numbers are checked automatically. A bank or GCash reference that's already on another payment is refused.</div>
  </div>
  <div class="card"><h3>Online payments (PayMongo)</h3>
    @if($paymongo['enabled'])
      <p class="small mb-1"><span class="badge {{ $paymongo['live'] ? 'b-green' : 'b-gold' }}">{{ $paymongo['live'] ? 'Live' : 'Test mode' }}</span> Customers see <b>Pay online</b> ({{ implode(', ', $paymongo['methods']) }}). Paid checkouts are verified automatically; no cashier step.</p>
      <p class="small muted mb-0">Webhook: register <code>{{ route('webhooks.paymongo') }}</code> for <code>checkout_session.payment.paid</code> in the PayMongo dashboard, then set <code>PAYMONGO_WEBHOOK_SECRET</code>. {{ $paymongo['webhook'] ? 'Webhook secret is set.' : 'Webhook secret is not set yet. Payments still confirm when the customer returns from checkout.' }}</p>
    @else
      <p class="small muted mb-0">Off. To turn it on, put your PayMongo secret key in <code>.env</code> as <code>PAYMONGO_SECRET_KEY</code> (use the <code>sk_test_…</code> key to try it for free), then reload.</p>
    @endif
  </div>
  <div class="row"><button class="btn btn-primary btn-lg">Save settings</button></div>
</form>
<div class="card mt-3" style="max-width:760px"><h3>Demo data</h3><p class="small muted mb-0">To reset all data to the demo set, run <code>php artisan migrate:fresh --seed</code> on the server. This deletes every account and rental, so it isn't available from the web.</p></div>
@endsection
