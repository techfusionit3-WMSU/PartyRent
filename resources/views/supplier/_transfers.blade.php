{{--
  Combined rental: units borrowed from other branches (App\Domain\Transfers). Each row: item, from → to, quantity,
  due date, status with who/when, and the step the user's branch can do. Needs $r (the rental).
--}}
@php
  $trs = $r->transfers()->with(['product', 'from', 'to', 'sender', 'receiver', 'closer'])->get();
  $probs = \App\Domain\Transfers::problems($r);
  $me = auth()->user();
@endphp
@if($trs->isNotEmpty())
  <div class="mt-2">
    <div class="label mb-1">Where items come from</div>
    <p class="small muted mt-0">{{ $r->branch?->name }} rents most of this order; the items below are lent by other branches and must reach {{ $r->branch?->name }} before {{ fmt_date($r->start_date) }}. The customer sees one rental and pays no extra.</p>
    @foreach($probs as $pr)
      <div class="alert alert-danger mb-1">{{ icon('alert') }}<div class="small"><b>{{ $pr['transfer']->product?->name }}:</b> {{ $pr['why'] }}.
        @if($me->orgCan('orders.manage') && \App\Support\BranchScope::covers($r->branch_id))
          <form method="POST" action="{{ route('supplier.requests.replan', $r) }}" class="mt-1">@csrf<button class="btn btn-xs">Re-plan branches</button></form>
        @else Ask {{ $r->branch?->name }} to re-plan this rental.@endif
      </div></div>
    @endforeach
    <div class="table-wrap"><table class="table cards">
      <thead><tr><th>Item</th><th>From → to</th><th class="num">Qty</th><th>Status</th><th></th></tr></thead>
      <tbody>
      @foreach($trs as $t)
        <tr>
          <td data-label="Item">{{ $t->product?->name }}</td>
          <td data-label="From → to">{{ $t->from?->name }} → {{ $t->to?->name }}@if($t->status === 'planned')<div class="small muted">Send by {{ fmt_date($t->due_on?->toDateString()) }}</div>@endif</td>
          <td data-label="Qty" class="num">{{ $t->qty }}</td>
          <td data-label="Status"><span class="badge {{ $t->badge() }}">{{ $t->statusLabel() }}</span>
            <div class="small muted">
              @if($t->sent_at)Sent {{ fmt_datetime($t->sent_at) }} by {{ $t->sender?->name ?? '—' }}<br>@endif
              @if($t->received_at)Received {{ fmt_datetime($t->received_at) }} by {{ $t->receiver?->name ?? '—' }}<br>@endif
              @if($t->closed_at){{ $t->status === 'kept' ? 'Kept' : 'Sent back' }} {{ fmt_datetime($t->closed_at) }} by {{ $t->closer?->name ?? '—' }}@endif
              @if($t->note && $t->status === 'cancelled'){{ $t->note }}@endif
            </div></td>
          <td data-label=""><div class="btn-group">
            @foreach(['send' => ['planned', 'Mark sent'], 'receive' => ['sent', 'Mark received'], 'return' => ['received', 'Sent back'], 'keep' => ['received', 'Keep here']] as $step => [$need, $label])
              @if($t->status === $need && \App\Domain\Transfers::can($me, $t, $step) && (! in_array($step, ['return', 'keep'], true) || in_array($r->status, ['completed', 'cancelled', 'declined'], true)))
                <form method="POST" action="{{ route('supplier.transfers.step', [$t, $step]) }}" @if($step === 'keep') data-confirm-title="Keep {{ $t->qty }} × {{ $t->product?->name }} at {{ $t->to?->name }}?" data-confirm="The units move from {{ $t->from?->name }}'s stock to {{ $t->to?->name }}'s stock." data-confirm-ok="Keep here" @endif>@csrf<button class="btn btn-xs {{ $step === 'send' || $step === 'receive' ? 'btn-primary' : '' }}">{{ $label }}</button></form>
              @endif
            @endforeach
            @if(! in_array($t->status, ['cancelled'], true))<a class="btn btn-xs btn-ghost" href="{{ route('supplier.transfers.slip', $t) }}" target="_blank" rel="noopener">{{ icon('print') }}Slip</a>@endif
          </div></td>
        </tr>
      @endforeach
      </tbody>
    </table></div>
  </div>
@endif
