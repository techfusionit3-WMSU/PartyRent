{{-- Add/edit a PartyRent collection account. $acc = PayAccount or null (add form; only that one refills old input). --}}
@php($val = fn ($k, $d = '') => $acc ? ($acc->$k ?? $d) : old($k, $d))
<form method="POST" action="{{ $action }}" enctype="multipart/form-data" class="mt-2">@csrf
  <div class="form-grid">
    <div class="field"><label>Type</label><select class="input" name="type">@foreach(\App\Models\PayAccount::TYPE_LABEL as $k => $l)<option value="{{ $k }}" @selected($val('type', 'gcash') === $k)>{{ $l }}</option>@endforeach</select></div>
    <div class="field"><label>Name shown to customers</label><input class="input" name="label" required maxlength="60" value="{{ $val('label') }}" placeholder="GCash, BDO, BPI QR Ph…"></div>
    <div class="field"><label>Account / mobile number</label><input class="input" name="account_no" maxlength="40" value="{{ $val('account_no') }}" placeholder="0917 123 4567"></div>
    <div class="field"><label>Account name</label><input class="input" name="account_name" maxlength="80" value="{{ $val('account_name') }}" placeholder="PartyRent Rentals Inc."></div>
    <div class="field"><label>Monthly limit (₱, optional)</label><input class="input" type="number" min="1" step="0.01" name="monthly_cap" value="{{ $val('monthly_cap') }}" placeholder="No limit"><div class="hint">The account hides itself before a payment would go over this.</div></div>
    <div class="field"><label>Order</label><input class="input" type="number" min="0" max="999" name="sort" value="{{ $val('sort', 0) }}"><div class="hint">Lower numbers show first.</div></div>
    <div class="field full"><label>QR code (optional)</label><input class="input" type="file" name="qr" accept="image/*">
      @if($acc?->qr_path)<label class="check mt-1"><input type="checkbox" name="remove_qr" value="1"> Remove the current QR code</label>@endif</div>
  </div>
  <div class="row" style="justify-content:flex-end"><button class="btn btn-primary btn-sm">{{ $submit }}</button></div>
</form>
