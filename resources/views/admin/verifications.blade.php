@extends('layouts.dash', ['title' => 'Customer Verification', 'nav' => 'verifications'])

{{-- Admin review of customer verifications (App\Domain\Verifications). --}}
@section('content')
<div class="page-head"><div><h1>Customer verification</h1><p>Customers send a government ID and proof of their bank or e-wallet account before they can rent; insurance is optional. Check that the names match, the ID is valid and readable, then approve, reject or ask them to send it again.</p></div></div>

<div class="row wrap between mb-2" style="gap:10px">
  <div class="tabs" role="tablist">
    @foreach(['pending' => 'To review ('.$counts['pending'].')', 'attention' => 'Rejected / resubmit ('.$counts['attention'].')', 'approved' => 'Approved', 'all' => 'All'] as $k => $l)
      <a class="tab {{ $tab === $k ? 'active' : '' }}" href="{{ route('admin.verifications', ['tab' => $k, 'q' => $q ?: null]) }}">{{ $l }}</a>
    @endforeach
  </div>
  <form method="GET" class="row" style="gap:6px"><input type="hidden" name="tab" value="{{ $tab }}"><input class="input input-sm" name="q" value="{{ $q }}" placeholder="Name or email" aria-label="Search customers"><button class="btn btn-sm">Search</button></form>
</div>

@if($open)
  @php($u = $open->user)
  @php($f = fn ($w) => route('admin.verifications.file', [$open, $w]))
  <div class="card mb-3" id="review">
    <div class="row wrap between" style="gap:8px">
      <div><h3 class="mb-0">{{ $u->name }}</h3><div class="small muted">{{ $u->email }} · {{ $u->phone ?: 'no phone' }} · member since {{ fmt_date($u->created_at) }}</div></div>
      <div><span class="badge {{ \App\Models\CustomerVerification::STATUS_BADGE[$open->status] ?? 'b-gray' }}">{{ $open->statusLabel() }}</span> @foreach($open->trustBadges() as [$l, $c])<span class="badge {{ $c }}">{{ $l }}</span>@endforeach</div>
    </div>
    <div class="grid grid-3 mt-2" style="gap:12px">
      <div class="card card-flat soft" style="padding:12px">
        <div class="strong">Government ID</div>
        <div class="small">{{ \App\Models\CustomerVerification::ID_TYPES[$open->id_type] ?? '—' }} · ends in <b>{{ $open->id_last4 ?: '—' }}</b></div>
        <div class="row wrap gap-sm mt-1">
          @foreach(['id_front' => 'Front', 'id_back' => 'Back'] as $w => $l)@if($open->{$w.'_path'})<a href="{{ $f($w) }}" target="_blank" rel="noopener" class="ver-thumb">@if(! str_ends_with($open->{$w.'_path'}, '.pdf'))<img src="{{ $f($w) }}" alt="{{ $l }} of the ID" loading="lazy">@else{{ icon('file') }}@endif<span class="small">{{ $l }}</span></a>@endif @endforeach
        </div>
      </div>
      <div class="card card-flat soft" style="padding:12px">
        <div class="strong">Payment account</div>
        <div class="small">{{ $open->pay_provider ?: '—' }} · {{ $open->pay_account_name }} · •••• {{ $open->pay_last4 }}</div>
        @if($open->pay_account_name && mb_strtolower(trim($open->pay_account_name)) !== mb_strtolower(trim($u->name)))<div class="small" style="color:var(--red)">Account name differs from the customer's name: check it.</div>@endif
        @if($open->pay_proof_path)<a href="{{ $f('pay_proof') }}" target="_blank" rel="noopener" class="ver-thumb mt-1">@if(! str_ends_with($open->pay_proof_path, '.pdf'))<img src="{{ $f('pay_proof') }}" alt="Account screenshot" loading="lazy">@else{{ icon('file') }}@endif<span class="small">Screenshot</span></a>@endif
      </div>
      <div class="card card-flat soft" style="padding:12px">
        <div class="strong">Insurance <span class="muted small">(optional)</span></div>
        @if($open->insurance_path)
          <div class="small">{{ ucfirst($open->insurance_status) }}{{ $open->insurer ? ' · '.$open->insurer : '' }}{{ $open->insurance_expires ? ' · until '.fmt_date($open->insurance_expires->toDateString()) : '' }}</div>
          <a href="{{ $f('insurance') }}" target="_blank" rel="noopener" class="ver-thumb mt-1">@if(! str_ends_with($open->insurance_path, '.pdf'))<img src="{{ $f('insurance') }}" alt="Proof of insurance" loading="lazy">@else{{ icon('file') }}@endif<span class="small">Proof</span></a>
        @else<div class="small muted">Not sent.</div>@endif
      </div>
    </div>
    @if($canDecide && ($open->status === 'pending' || $open->insurance_status === 'submitted'))
      <form method="POST" action="{{ route('admin.verifications.decide', $open) }}" class="mt-2">@csrf
        @if($open->insurance_path && $open->insurance_status === 'submitted')
          <div class="field"><label>Insurance</label><div class="row wrap" style="gap:10px">@foreach(['verify' => 'Verify', 'reject' => 'Not accepted', 'keep' => 'Decide later'] as $k => $l)<label class="row" style="gap:6px"><input type="radio" name="insurance" value="{{ $k }}" @checked($k === 'keep')> {{ $l }}</label>@endforeach</div></div>
        @endif
        <div class="field"><label for="reason">Reason <span class="muted small">(needed to reject or ask again; the customer sees it)</span></label><input class="input" id="reason" name="reason" maxlength="500" placeholder="e.g. The ID photo is blurry; the account name doesn't match the ID"></div>
        <div class="btn-group">
          @if($open->status === 'pending')
            <button class="btn btn-primary" name="action" value="approve">{{ icon('check') }}Approve</button>
            <button class="btn" name="action" value="resubmit">Ask to send again</button>
            <button class="btn btn-ghost" style="color:var(--red)" name="action" value="reject">Reject</button>
          @else
            <button class="btn btn-primary" name="action" value="insurance">Save insurance decision</button>
          @endif
        </div>
      </form>
    @endif
    @if($open->history)
      <details class="mt-2"><summary class="small muted">History</summary><ul class="small">@foreach(array_reverse($open->history) as $h)<li>{{ \Illuminate\Support\Carbon::parse($h['at'])->format('M j, Y g:i A') }} · {{ $h['what'] }}{{ $h['by'] ? ' · '.$h['by'] : '' }}</li>@endforeach</ul></details>
    @endif
  </div>
@endif

@if($list->isEmpty())
  @include('partials.empty', ['emoji' => '🪪', 'title' => 'Nothing here', 'text' => $tab === 'pending' ? 'No verification is waiting for review.' : 'No customers in this list.'])
@else
  <div class="table-wrap card"><table class="table cards"><thead><tr><th>Customer</th><th>Status</th><th>Sent</th><th>Badges</th><th></th></tr></thead><tbody>
    @foreach($list as $v)<tr>
      <td data-label="Customer"><b>{{ $v->user->name }}</b><div class="small muted">{{ $v->user->email }}</div></td>
      <td data-label="Status"><span class="badge {{ \App\Models\CustomerVerification::STATUS_BADGE[$v->status] ?? 'b-gray' }}">{{ $v->statusLabel() }}</span>@if($v->insurance_status === 'submitted') <span class="badge b-sky">Insurance to review</span>@endif</td>
      <td data-label="Sent" class="small">{{ $v->submitted_at ? $v->submitted_at->diffForHumans() : '—' }}@if($v->reviewer)<div class="muted">by {{ $v->reviewer->name }}</div>@endif</td>
      <td data-label="Badges">@foreach($v->trustBadges() as [$l, $c])<span class="badge {{ $c }}">{{ $l }}</span> @endforeach</td>
      <td class="right"><a class="btn btn-sm" href="{{ route('admin.verifications', ['tab' => $tab, 'q' => $q ?: null, 'id' => $v->id]) }}#review">Open</a></td>
    </tr>@endforeach
  </tbody></table></div>
  {{ $list->links() }}
@endif
@endsection
