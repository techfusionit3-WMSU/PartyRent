@extends('layouts.dash', ['title' => 'System Settings', 'nav' => 'settings'])

@section('content')
<div class="page-head"><div><h1>System settings</h1><p>Platform-wide rules. Every change is written to the audit log.</p></div></div>
<form method="POST" action="{{ route('super.settings.update') }}" class="stack" style="max-width:760px" data-confirm-title="Save system settings?" data-confirm="These settings affect every user on the platform." data-confirm-ok="Save">
  @csrf
  <div class="card"><h3>Platform</h3><div class="form-grid">
    <div class="field"><label>Platform name</label><input class="input" name="platformName" required value="{{ old('platformName', $s['platformName']) }}"></div>
    <div class="field"><label>Commission (%)</label><input class="input" type="number" min="0" max="50" step="0.1" name="commissionPct" required value="{{ old('commissionPct', $s['commissionPct']) }}"><div class="hint">Taken from items only, like Shopee: delivery fees and damage charges pay no commission. Payouts already released keep their rate.</div></div>
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
  <div class="card"><h3>Delivery: PartyRent standard rates</h3>
    <p class="small muted">Used by suppliers who choose "PartyRent standard rates" (like Shopee). Fee = first kg + each extra kg (rounded up), by route. A product's charged weight is its weight or its size weight (L × W × H ÷ divisor), whichever is bigger.</p>
    <div class="table-wrap mb-1"><table class="table"><thead><tr><th>Route</th><th>First kg (₱)</th><th>Each extra kg (₱)</th></tr></thead><tbody>
      @foreach(['local' => 'Same province (Metro Manila = one)', 'island' => 'Same island group', 'inter' => 'Another island group'] as $t => $tl)
        <tr><td>{{ $tl }}</td><td><input class="input input-sm" type="number" min="0" step="0.01" name="shipFirstKg[{{ $t }}]" required value="{{ old('shipFirstKg.'.$t, $s['shipFirstKg'][$t] ?? 0) }}" aria-label="First kg, {{ $tl }}"></td><td><input class="input input-sm" type="number" min="0" step="0.01" name="shipPerKg[{{ $t }}]" required value="{{ old('shipPerKg.'.$t, $s['shipPerKg'][$t] ?? 0) }}" aria-label="Each extra kg, {{ $tl }}"></td></tr>
      @endforeach
    </tbody></table></div>
    <div class="form-grid">
      <div class="field"><label>Size weight divisor</label><input class="input" type="number" min="1000" max="10000" name="shipVolDivisor" required value="{{ old('shipVolDivisor', $s['shipVolDivisor']) }}"><div class="hint">L × W × H (cm) ÷ this = size weight in kg. Couriers commonly use 3500.</div></div>
      <div class="field"><label>Weight for products with none set (kg)</label><input class="input" type="number" min="0.1" max="1000" step="0.1" name="shipDefaultKg" required value="{{ old('shipDefaultKg', $s['shipDefaultKg']) }}"></div>
    </div>
  </div>
  <div class="card"><h3>Free Shipping program</h3>
    <label class="check mb-1"><input type="checkbox" name="freeShipEnabled" value="1" @checked($s['freeShipEnabled'])> Program is on (suppliers can join; customers get vouchers)</label>
    <p class="small muted">Like Shopee: suppliers who join pay extra commission. Their customers get a delivery voucher paid by PartyRent, and the supplier still receives the full delivery fee.</p>
    <div class="form-grid">
      <div class="field"><label>Extra commission for members (%)</label><input class="input" type="number" min="0" max="20" step="0.1" name="freeShipExtraPct" required value="{{ old('freeShipExtraPct', $s['freeShipExtraPct']) }}"></div>
      <div class="field"><label>Minimum items total (₱)</label><input class="input" type="number" min="0" step="1" name="freeShipMinSpend" required value="{{ old('freeShipMinSpend', $s['freeShipMinSpend']) }}"></div>
      <div class="field"><label>Voucher up to (₱)</label><input class="input" type="number" min="0" step="1" name="freeShipCap" required value="{{ old('freeShipCap', $s['freeShipCap']) }}"><div class="hint">Off the delivery fee. Handling fees are not covered.</div></div>
    </div>
  </div>
  <div class="card"><h3>Where customers send payments</h3>
    <p class="small mb-0">{{ $accountCount }} active account{{ $accountCount === 1 ? '' : 's' }}. GCash, Maya and bank accounts, their QR codes and monthly limits are managed on <a href="{{ route('super.pay-accounts') }}">Payment accounts</a>.</p>
  </div>
  <div class="card"><h3>Online payments</h3>
    @if($gateway === 'xendit')
      <p class="small mb-1"><span class="badge {{ $xendit['live'] ? 'b-green' : 'b-gold' }}">{{ $xendit['live'] ? 'Live' : 'Test mode' }}</span> <b>Xendit.</b> Customers see <b>Pay online</b> ({{ $xendit['methods'] ? implode(', ', $xendit['methods']) : 'every channel turned on in your Xendit dashboard' }}). Paid invoices are verified automatically, with no cashier step.</p>
      <p class="small muted mb-0">Webhook: in the Xendit dashboard (Settings → Webhooks) set <b>Invoices → Invoice paid</b> to <code>{{ route('webhooks.xendit') }}</code> and copy the verification token into <code>XENDIT_CALLBACK_TOKEN</code>. {{ $xendit['webhook'] ? 'Callback token is set.' : 'Callback token is not set yet. Payments still confirm when the customer returns from checkout.' }} Also set <b>Refunds</b> to the same URL.</p>
      <p class="small mt-1 mb-0">Refunds: @if($xendit['refunds'] === true)<span class="badge b-green">Allowed</span> Refunds from shortfall cases go back to the customer's original online payment automatically.@elseif($xendit['refunds'] === false)<span class="badge b-red">Not allowed</span> This key cannot refund. Give it the <b>Refunds</b> permission (read + write) in Xendit → Settings → API keys; until then refunds wait for the shop's cashier.@else<span class="badge b-gray">Unknown</span> Xendit could not be reached to check.@endif</p>
    @elseif(\App\Domain\OnlinePay::practice())
      <p class="small mb-1"><span class="badge b-purple">Practice mode</span> <b>Pay online</b> opens a built-in practice checkout with Pay / Fail buttons. No gateway account, no real money. Everything after paying (automatic verification, receipts, the supplier's "Down payment received") works like the real thing.</p>
      <p class="small muted mb-0">To take real payments, sign up at Xendit and put your secret key in <code>.env</code> as <code>XENDIT_SECRET_KEY</code> (the <code>xnd_development_…</code> key is free to test with). It replaces practice mode as soon as it is set. Practice mode never runs on a production server.</p>
    @elseif($gateway === 'paymongo')
      <p class="small mb-1"><span class="badge {{ $paymongo['live'] ? 'b-green' : 'b-gold' }}">{{ $paymongo['live'] ? 'Live' : 'Test mode' }}</span> <b>PayMongo.</b> Customers see <b>Pay online</b> ({{ implode(', ', $paymongo['methods']) }}). Paid checkouts are verified automatically, with no cashier step.</p>
      <p class="small muted mb-0">Webhook: register <code>{{ route('webhooks.paymongo') }}</code> for <code>checkout_session.payment.paid</code> in the PayMongo dashboard, then set <code>PAYMONGO_WEBHOOK_SECRET</code>. {{ $paymongo['webhook'] ? 'Webhook secret is set.' : 'Webhook secret is not set yet. Payments still confirm when the customer returns from checkout.' }}</p>
    @else
      <p class="small muted mb-0">Off. To turn it on, put your Xendit secret key in <code>.env</code> as <code>XENDIT_SECRET_KEY</code> (the <code>xnd_development_…</code> key is free to test with), then reload.</p>
    @endif
  </div>
  <div class="card"><h3>Supplier payouts</h3>
    <label class="check"><input type="checkbox" name="payoutAuto" value="1" @checked($s['payoutAuto']) @disabled(! \App\Domain\XenditPayouts::enabled())> Send payouts automatically through Xendit</label>
    <p class="small muted mb-0 mt-1">@if(\App\Domain\XenditPayouts::enabled())When a rental is completed, the supplier's share goes straight to their GCash or bank account, like Shopee. Off: admins click <b>Send via Xendit</b> on Supplier payouts. Needs a Xendit key with <b>Money-out: Write</b>.@else Needs Xendit (<code>XENDIT_SECRET_KEY</code>). Until then, admins send payouts by hand and record the reference.@endif</p>
  </div>
  <div class="row"><button class="btn btn-primary btn-lg">Save settings</button></div>
</form>
<div class="card mt-3" style="max-width:760px"><h3>Data reset</h3><p class="small muted mb-0">To start fresh, run <code>php artisan migrate:fresh --seed</code> on the server: it deletes every account and rental and keeps only the staff accounts, settings and categories. For the full demo set add <code>--seeder=DemoSeeder</code>. Neither is available from this page.</p></div>
@endsection
