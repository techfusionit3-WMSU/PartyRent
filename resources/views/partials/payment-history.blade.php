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
          @if($p->status === 'awaiting' && $p->gateway_url && auth()->id() === $r->customer_id)<a class="btn btn-xs btn-primary" href="{{ $p->gateway_url }}">Continue payment</a> <a class="btn btn-xs" href="{{ route('rentals.paymongo.return', [$r, $p]) }}">Check status</a>@endif</td>
      </tr>
    @endforeach
  </tbody></table></div>
@endif
