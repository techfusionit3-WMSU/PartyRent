{{-- Installment schedule (customer view, reference installmentCard + proposeModal). $r --}}
@php($ip = $r->installment_plan)
@php($open = in_array($r->status, array_merge(['pending'], \App\Domain\Finance::ACTIVE), true))
@php($max = (int) \App\Domain\Finance::planConfig($r->supplier)['installment']['maxInstallments'])
<div class="card"><div class="card-head"><h3>Installment schedule</h3>
  @if($ip)<span class="badge {{ ['approved' => 'b-green', 'proposed' => 'b-gold', 'rejected' => 'b-red'][$ip['status']] ?? 'b-gray' }}">{{ ['approved' => 'Approved', 'proposed' => 'Awaiting supplier approval', 'rejected' => 'Rejected'][$ip['status']] ?? '' }}</span>@else<span class="badge b-gray">Not proposed</span>@endif</div>
  @if($ip)
    <div class="table-wrap"><table class="table cards"><thead><tr><th>Installment</th><th>Due date</th><th class="num">Amount</th></tr></thead><tbody>
      @foreach($ip['items'] as $i => $x)<tr><td data-label="Installment">Installment {{ $i + 1 }} of {{ count($ip['items']) }}</td><td data-label="Due date">{{ fmt_date($x['due']) }}</td><td data-label="Amount" class="num"><b>{{ money($x['amount']) }}</b></td></tr>@endforeach
    </tbody></table></div>
  @endif
  @if($ip && $ip['status'] === 'rejected')<div class="alert alert-danger mt-1">{{ icon('alert') }}<div><b>Not approved:</b> {{ $ip['reason'] ?? '' }}</div></div>@endif
  <p class="small muted mt-1 mb-0">Installment balance {{ money($r->pricing['finalBalance']) }} · every installment must be paid on or before {{ fmt_date($r->event_date) }}.</p>
  @if($open && (! $ip || $ip['status'] === 'rejected'))<button class="btn btn-primary btn-sm mt-1" data-modal="proposeModal">{{ icon('edit') }}Propose a new schedule</button>@endif
</div>
@if($open && (! $ip || $ip['status'] === 'rejected'))
  @php($sug = \App\Domain\Finance::suggestInstallments((float) $r->pricing['finalBalance'], min(3, $max), add_days(today_iso(), 1), $r->event_date))
  <div class="modal-backdrop hidden" id="proposeModal"><div class="modal" role="dialog" aria-modal="true"><div class="modal-head"><h3>Propose installment schedule · {{ $r->code }}</h3><button class="icon-btn" data-close aria-label="Close">{{ icon('x') }}</button></div>
    <form method="POST" action="{{ route('rentals.installments', $r) }}">@csrf<div class="modal-body">
      <p class="small muted">Divide {{ money($r->pricing['finalBalance']) }} into up to {{ $max }} payments, all due on or before {{ fmt_date($r->event_date) }}.</p>
      @for($i = 0; $i < $max; $i++)
        <div class="row mb-1" style="gap:8px"><span class="small muted" style="width:26px">#{{ $i + 1 }}</span><input class="input input-sm" type="date" name="items[{{ $i }}][due]" value="{{ $sug[$i]['due'] ?? '' }}" min="{{ add_days(today_iso(), 1) }}" max="{{ $r->event_date }}" style="width:auto" aria-label="Due date {{ $i + 1 }}"><input class="input input-sm" type="number" step="0.01" min="0" name="items[{{ $i }}][amount]" value="{{ $sug[$i]['amount'] ?? '' }}" style="width:130px" aria-label="Amount {{ $i + 1 }}"></div>
      @endfor
      <p class="small muted mb-0">Leave unused rows empty. The amounts must add up to {{ money($r->pricing['finalBalance']) }}.</p>
    </div><div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Send to supplier</button></div></form>
  </div></div>
@endif
