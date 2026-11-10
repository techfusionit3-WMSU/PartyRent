@extends('layouts.dash', ['title' => 'System Settings', 'nav' => 'settings'])

@section('content')
<div class="page-head"><div><h1>System settings</h1><p>Platform-wide rules. Every change is written to the audit log.</p></div></div>
<form method="POST" action="{{ route('super.settings.update') }}" class="stack" style="max-width:760px">
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
  <div class="row"><button class="btn btn-primary btn-lg">Save settings</button></div>
</form>
<div class="card mt-3" style="max-width:760px"><h3>Demo data</h3><p class="small muted mb-0">To reset all data to the demo set, run <code>php artisan migrate:fresh --seed</code> on the server. This deletes every account and rental, so it isn't available from the web.</p></div>
@endsection
