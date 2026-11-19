{{-- Personal shops: return & damage report (minor / repairable / not working / beyond repair / lost),
     the customer's 48-hour accept-or-dispute step and the PartyRent admin decision. $viewer = customer | supplier | admin. --}}
@php
    $status = \App\Domain\Damage::reviewStatus($r);
    $x = $r->inspection;
    $rev = $x['review'] ?? null;
    $dec = $rev['decisions'] ?? [];
    $before = $r->fulfillment_log['releasePhotos'] ?? [];
    $after = $x['photos'] ?? [];
    $affected = collect($x['items'] ?? [])->filter(fn ($it) => ($it['total'] ?? 0) > 0 || ($it['minor'] ?? 0) || ($it['repairable'] ?? 0) || ($it['notWorking'] ?? 0) || ($it['beyond'] ?? 0) || ($it['lost'] ?? 0));
    $canAnswer = ($viewer ?? null) === 'customer' && $status === 'pending';
    $stateBadge = ['open' => ['Waiting for the customer', 'b-gold'], 'accepted' => ['Accepted', 'b-green'], 'disputed' => ['Disputed', 'b-red'], 'resolved' => ['Decided by PartyRent', 'b-purple']];
@endphp
<div class="card" id="damage">
  <div class="row between mb-1"><h3 class="mb-0">Return & damage report</h3><span class="small muted">Inspected {{ fmt_datetime($x['confirmedAt'] ?? $x['at'] ?? null) }}</span></div>

  @if($status === 'pending')
    <div class="alert alert-warn">{{ icon('clock') }}<div>@if(($viewer ?? null) === 'customer')<b>Please review the charge below.</b> Accept or dispute each item by <b>{{ fmt_datetime($rev['deadline']) }}</b>. If you don't answer by then, the charge is accepted.@else Waiting for the customer to accept or dispute by <b>{{ fmt_datetime($rev['deadline']) }}</b>. With no answer it is accepted.@endif</div></div>
  @elseif($status === 'disputed')
    <div class="alert alert-info">{{ icon('info') }}<div><b>Disputed.</b> A PartyRent admin is comparing the release and return photos, the purchase record and the repair cost, and will set the final amount. The deposit stays held until then.</div></div>
  @elseif($status === 'done' && $rev)
    <div class="alert alert-success">{{ icon('check') }}<div>{{ ! empty($rev['waived']) ? 'The shop waived the damage charge.' : (! empty($rev['resolvedAt']) ? 'Decided by PartyRent on '.fmt_datetime($rev['resolvedAt']).'.' : (! empty($rev['auto']) ? 'Accepted automatically (no answer within '.\App\Domain\Damage::REVIEW_HOURS.' hours).' : 'Accepted by the customer.')) }}</div></div>
  @endif

  @if($canAnswer)<form method="POST" action="{{ route('rentals.damage', $r) }}">@csrf @endif
  @forelse($affected as $it)
    @php($pid = (string) $it['productId'])
    @php($d = $dec[$pid] ?? null)
    @php($rows = array_values(array_filter([
        $it['minor'] ? ['Minor', $it['minor'].' × '.money($it['minorFee'] ?? 0).' minor fee', $it['amounts']['minor']] : null,
        $it['repairable'] ? ['Repairable', $it['repairable'].' unit(s): repair cost'.(($it['amounts']['repairable'] ?? 0) < ($it['repairCost'] ?? 0) ? ' '.money($it['repairCost']).', capped at the replacement value' : ''), $it['amounts']['repairable']] : null,
        $it['notWorking'] ? ['Not working', $it['notWorking'].' unit(s): '.($it['misuse'] ? 'misuse, repair cost' : 'normal fault, no charge'), $it['amounts']['notWorking']] : null,
        $it['beyond'] ? ['Beyond repair', $it['beyond'].' × '.money($it['value']).' replacement value', $it['amounts']['beyond']] : null,
        $it['lost'] ? ['Lost / not returned', $it['lost'].' × '.money($it['value']).' replacement value', $it['amounts']['lost']] : null,
    ])))
    <div class="card card-flat mb-1" style="padding:14px">
      <div class="row between wrap"><b>{{ inv()->product($it['productId'])?->name }}</b>
        <span class="row gap-sm">@if($d)<span class="badge {{ $stateBadge[$d['state']][1] ?? 'b-gray' }}">{{ $stateBadge[$d['state']][0] ?? $d['state'] }}</span>@endif<b>{{ money($d && $d['state'] === 'resolved' ? $d['amount'] : $it['total']) }}</b></span></div>
      <div class="small muted">Rented {{ $it['rented'] }} · returned {{ $it['returned'] }} · replacement value {{ money($it['value']) }}</div>
      @if($rows)<div class="table-wrap mt-1"><table class="table"><thead><tr><th>Outcome</th><th>How it is worked out</th><th class="num">Charge</th></tr></thead><tbody>
        @foreach($rows as [$l, $how, $amt])<tr><td>{{ $l }}</td><td class="small">{{ $how }}</td><td class="num">{{ money($amt) }}</td></tr>@endforeach
      </tbody></table></div>@endif
      @if(! empty($it['description']))<p class="small mb-0 mt-1"><b>What happened:</b> {{ $it['description'] }}</p>@endif
      @if($d && $d['reason'] !== '')<p class="small mb-0 mt-1"><b>Customer's dispute:</b> {{ $d['reason'] }}</p>@endif
      @if($d && $d['state'] === 'resolved')<p class="small mb-0 mt-1"><b>PartyRent decision:</b> {{ money($d['amount']) }} (the shop charged {{ money($it['total']) }}){{ ! empty($d['note']) ? '. '.$d['note'] : '' }}</p>@endif
      @if($canAnswer && $d)
        <div class="row wrap gap-sm mt-1" data-answer>
          <label class="check"><input type="radio" name="answers[{{ $pid }}][action]" value="accept" checked> Accept {{ money($it['total']) }}</label>
          <label class="check"><input type="radio" name="answers[{{ $pid }}][action]" value="dispute"> Dispute</label>
        </div>
        <textarea class="input mt-1" name="answers[{{ $pid }}][reason]" rows="2" maxlength="1000" placeholder="Why do you dispute it? e.g. The scratch was already there in the release photos." hidden data-reason></textarea>
      @endif
    </div>
  @empty
    <p class="muted">All items returned in good condition.</p>
  @endforelse
  @if($canAnswer)
    <button class="btn btn-primary">Send my answer</button>
    </form>
    <script>document.addEventListener('change', function (e) { var g = e.target.closest('[data-answer]'); if (!g) return; var t = g.nextElementSibling; t.hidden = e.target.value !== 'dispute'; t.required = !t.hidden; });</script>
  @endif

  @if($before || $after)
    <div class="grid grid-2 mt-2">
      <div><div class="label">At release (before the event)</div>@if($before)<div class="thumbs">@foreach($before as $ph)<a href="{{ asset('storage/'.$ph) }}" target="_blank" rel="noopener"><img src="{{ asset('storage/'.$ph) }}" alt="Condition at release"></a>@endforeach</div>@else<p class="small muted">No photos.</p>@endif</div>
      <div><div class="label">At return</div>@if($after)<div class="thumbs">@foreach($after as $ph)<a href="{{ asset('storage/'.$ph) }}" target="_blank" rel="noopener"><img src="{{ asset('storage/'.$ph) }}" alt="Condition at return"></a>@endforeach</div>@else<p class="small muted">No photos.</p>@endif</div>
    </div>
  @endif
  @if(! empty($x['notes']))<p class="mt-1"><b>Notes:</b> {{ $x['notes'] }}</p>@endif
  <div class="sum-line total"><span>{{ $status === 'done' ? 'Final compensation' : 'Compensation charged' }}</span><span>{{ money($x['compensation'] ?? 0) }}</span></div>
  @if(isset($x['assessed']) && $x['assessed'] != $x['compensation'])<p class="small muted mb-0">The shop first charged {{ money($x['assessed']) }}.</p>@endif
</div>
