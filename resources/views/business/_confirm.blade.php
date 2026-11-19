{{-- Confirm or send back a customer's payment proof. $p = EventPayment (pending). --}}
<div class="btn-group">
  <form method="POST" action="{{ route('business.payments.confirm', $p) }}" data-confirm-title="Confirm {{ money($p->amount) }}?" data-confirm="Only confirm once the money is in your account (ref {{ $p->reference }})." data-confirm-ok="Confirm payment">@csrf<input type="hidden" name="decision" value="confirm"><button class="btn btn-xs btn-success">Confirm</button></form>
  <button class="btn btn-xs btn-ghost" data-modal="rej{{ $p->id }}">Not received</button>
</div>
<div class="modal-backdrop hidden" id="rej{{ $p->id }}"><div class="modal sm" role="dialog" aria-modal="true"><div class="modal-head"><h3>Payment not received</h3><button class="icon-btn" data-close aria-label="Close">{{ icon('x') }}</button></div>
  <form method="POST" action="{{ route('business.payments.confirm', $p) }}">@csrf<input type="hidden" name="decision" value="reject"><div class="modal-body"><div class="field"><label>What's wrong?</label><input class="input" name="reason" required minlength="3" maxlength="255" placeholder="e.g. No transfer with that reference"></div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Back</button><button class="btn btn-danger">Send back</button></div></form></div></div>
