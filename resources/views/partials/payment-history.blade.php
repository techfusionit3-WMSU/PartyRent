@php($pays = $r->fin()['payments'])
@if($pays->isEmpty())
  <div class="muted small">No payments recorded yet.</div>
@else
  <div class="table-wrap"><table class="table cards"><thead><tr><th>Transaction</th><th>Type</th><th>Method</th><th class="num">Amount</th><th>Status</th><th></th></tr></thead><tbody>
    @foreach($pays as $p)
      <tr>
        <td data-label="Transaction"><b>{{ $p->code }}</b><div class="small muted">{{ fmt_date($p->date) }}</div></td>
        <td data-label="Type">{{ $p->typeLabel() }}</td>
        <td data-label="Method">{{ $p->methodLabel() }}@if($p->payAccount)<div class="small muted">to {{ $p->payAccount->display() }}</div>@endif @if($p->reference)<div class="small muted">{{ $p->reference }}</div>@endif</td>
        <td data-label="Amount" class="num"><b>{{ $p->isOutflow() ? '− ' : '' }}{{ money($p->amount) }}</b></td>
        <td data-label="Status">@include('partials.badges', ['ver' => $p->status])@if($p->reject_reason)<div class="small" style="color:var(--red)">{{ $p->reject_reason }}</div>@endif</td>
        <td data-label="">@if($p->receipt_no)<a class="btn btn-xs" href="{{ route('payments.receipt', $p) }}" target="_blank">{{ $p->receipt_no }}</a>@endif @if($p->proof_path)<a class="btn btn-xs btn-ghost" href="{{ route('payments.proof', $p) }}" target="_blank">Proof</a>@endif
          @if(! empty($inlineVerify) && $p->status === 'pending' && ! $p->isOutflow() && ! \App\Domain\Rentals::canVerify($p, auth()->user()))
            <div class="small muted mt-1">PartyRent is checking this transfer.</div>
          @endif
          @if($p->disputed_at)<div class="small mt-1" style="color:var(--red)">{{ icon('flag', 'width:12px') }} Reported by the customer · PartyRent support is reviewing</div>@endif
          @if(auth()->id() === $r->customer_id && \App\Domain\Payouts::isCash($p->method) && $p->status === 'verified' && $p->type !== 'refund' && ! $p->disputed_at)
            <details class="inline-reject mt-1"><summary class="btn btn-xs btn-ghost">Not right?</summary>
              <form method="POST" action="{{ route('rentals.dispute', [$r, $p]) }}" class="mt-1">@csrf<p class="small muted mb-1">The shop recorded this cash payment. If you did not pay it, or paid a different amount, tell PartyRent support.</p><textarea class="input" name="why" rows="2" required minlength="5" maxlength="1000" placeholder="e.g. I paid ₱2,000, not ₱5,000"></textarea><button class="btn btn-xs btn-danger mt-1">Report to PartyRent</button></form>
            </details>
          @endif
          @if(! empty($inlineVerify) && $p->status === 'pending' && ! $p->isOutflow() && \App\Domain\Rentals::canVerify($p, auth()->user()))
            <div class="inline-verify">
              <form method="POST" action="{{ route('supplier.payments.verify', $p) }}" data-confirm-title="Verify {{ $p->code }}?" data-confirm="Confirm that {{ money($p->amount) }} ({{ $p->methodLabel() }}{{ $p->reference ? ', ref '.$p->reference : '' }}) was received. A receipt is issued and the customer's balance updated." data-confirm-ok="Verify">@csrf<button class="btn btn-xs btn-success">{{ icon('check') }}Verify</button></form>
              <details class="inline-reject"><summary class="btn btn-xs btn-ghost">Reject</summary>
                <form method="POST" action="{{ route('supplier.payments.reject', $p) }}" class="mt-1">@csrf<textarea class="input" name="reason" rows="2" required maxlength="500" placeholder="Reason sent to the customer, e.g. reference not found"></textarea><button class="btn btn-xs btn-danger mt-1">Reject payment</button></form>
              </details>
            </div>
          @endif
          @if($p->status === 'awaiting' && $p->gateway_url && auth()->id() === $r->customer_id)<a class="btn btn-xs btn-primary" href="{{ $p->gateway_url }}">Continue payment</a> <a class="btn btn-xs" href="{{ route('rentals.online.return', [$r, $p]) }}">Check status</a>@endif</td>
      </tr>
    @endforeach
  </tbody></table></div>
@endif
