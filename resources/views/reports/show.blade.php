@extends('layouts.dash', ['title' => 'Printable Reports', 'nav' => 'printable'])

@section('content')
@php
  $uses = \App\Domain\ReportCenter::TYPES[$f['type']][3];
  $dated = \App\Domain\ReportCenter::TYPES[$f['type']][4];
  $center = route($prefix.'center');
@endphp
<div class="page-head"><div><h1>Printable Reports</h1><p>Pick a report and filters, then view, print, save as PDF or download it for Excel.</p></div>
  <div class="row wrap" style="gap:6px">
    <a class="btn btn-sm" href="{{ route($prefix.'print', $query) }}" target="_blank" rel="noopener">{{ icon('print') }}Print</a>
    <a class="btn btn-sm btn-outline" href="{{ route($prefix.'print', $query + ['pdf' => 1]) }}" target="_blank" rel="noopener">{{ icon('file') }}Save as PDF</a>
    <a class="btn btn-sm btn-outline" href="{{ route($prefix.'export', $query) }}">{{ icon('file') }}Download CSV (Excel)</a>
  </div>
</div>

<form method="GET" action="{{ $center }}" class="card mb-2" id="rpForm">
  <div class="rp-form">
    <label class="rp-field rp-wide"><span>Report</span>
      <select class="input" name="type" onchange="this.form.submit()">
        @foreach(\App\Domain\ReportCenter::GROUPS as $g => $gl)
          @php $inGroup = array_filter($types, fn ($t) => $t[1] === $g); @endphp
          @if($inGroup)<optgroup label="{{ $gl }}">@foreach($inGroup as $k => $t)<option value="{{ $k }}" @selected($f['type'] === $k)>{{ $t[0] }}</option>@endforeach</optgroup>@endif
        @endforeach
      </select>
    </label>
    @if($dated)
      <label class="rp-field"><span>Period</span>
        <select class="input" name="period" id="rpPeriod">@foreach(\App\Domain\ReportCenter::PERIODS as $k => $l)<option value="{{ $k }}" @selected($f['period'] === $k)>{{ $l }}</option>@endforeach</select>
      </label>
      <label class="rp-field" data-when="anchor"><span>{{ ['day' => 'Day', 'week' => 'Any day in the week', 'month' => 'Any day in the month', 'year' => 'Any day in the year'][$f['period']] ?? 'Date' }}</span>
        <input class="input" type="date" name="date" value="{{ $f['date'] }}"></label>
      <label class="rp-field" data-when="custom"><span>From</span><input class="input" type="date" name="from" value="{{ $f['from'] }}"></label>
      <label class="rp-field" data-when="custom"><span>To</span><input class="input" type="date" name="to" value="{{ $f['to'] }}"></label>
    @endif
    @if($f['admin'] && $f['type'] !== 'suppliers')
      <label class="rp-field"><span>Supplier</span>
        <select class="input" name="supplier" onchange="this.form.querySelectorAll('[name=branch],[name=item]').forEach(e => e.value = ''); this.form.submit()"><option value="">All suppliers</option>@foreach($options['suppliers'] as $s)<option value="{{ $s->id }}" @selected($f['supplier']?->id === $s->id)>{{ $s->name }}</option>@endforeach</select>
      </label>
    @endif
    @if(in_array('branch', $uses) && $options['branches']->count() > 1)
      <label class="rp-field"><span>Branch</span>
        <select class="input" name="branch"><option value="{{ $f['admin'] ? '' : 'all' }}">{{ $f['admin'] ? 'All branches' : ($options['orgLevel'] ? 'All branches (whole organization)' : 'All my branches') }}</option>@foreach($options['branches'] as $b)<option value="{{ $b->id }}" @selected($f['branch']?->id === $b->id)>{{ $b->name }}{{ $b->active ? '' : ' (closed)' }}</option>@endforeach</select>
      </label>
    @endif
    @if(in_array('status', $uses))
      <label class="rp-field"><span>Rental status</span>
        <select class="input" name="status"><option value="">All statuses</option>@foreach(\App\Models\RentalRequest::STATUS as $k => $s)<option value="{{ $k }}" @selected($f['status'] === $k)>{{ $s[0] }}</option>@endforeach</select>
      </label>
    @endif
    @if(in_array('pay', $uses))
      <label class="rp-field"><span>Payment status</span>
        <select class="input" name="pay"><option value="">Any</option>@foreach(\App\Domain\ReportCenter::PAY_STATUSES as $s)<option @selected($f['pay'] === $s)>{{ $s }}</option>@endforeach</select>
      </label>
    @endif
    @if(in_array('pstatus', $uses))
      <label class="rp-field"><span>Payment status</span>
        <select class="input" name="pstatus"><option value="">Verified, pending and rejected</option>@foreach(['verified' => 'Verified (paid)', 'pending' => 'Waiting for verification', 'rejected' => 'Rejected'] as $k => $l)<option value="{{ $k }}" @selected($f['pstatus'] === $k)>{{ $l }}</option>@endforeach</select>
      </label>
    @endif
    @if(in_array('method', $uses))
      <label class="rp-field"><span>Method</span>
        <select class="input" name="method"><option value="">Any method</option>@foreach(array_unique(\App\Models\Payment::METHOD_LABEL) as $k => $l)<option value="{{ $k }}" @selected($f['method'] === $k)>{{ $l }}</option>@endforeach</select>
      </label>
    @endif
    @if(in_array('item', $uses) && $options['products']->isNotEmpty())
      <label class="rp-field rp-wide"><span>Item{{ in_array($f['type'], ['transactions', 'sales']) ? ' or package' : '' }}</span>
        <select class="input" name="item"><option value="">All</option>
          <optgroup label="Items">@foreach($options['products'] as $p)<option value="p:{{ $p->id }}" @selected(($f['item']['key'] ?? '') === 'p:'.$p->id)>{{ $p->name }}</option>@endforeach</optgroup>
          @if(in_array($f['type'], ['transactions', 'sales']) && $options['packages']->isNotEmpty())<optgroup label="Packages">@foreach($options['packages'] as $p)<option value="k:{{ $p->id }}" @selected(($f['item']['key'] ?? '') === 'k:'.$p->id)>{{ $p->name }}</option>@endforeach</optgroup>@endif
        </select>
      </label>
    @endif
    @if(in_array('category', $uses))
      <label class="rp-field"><span>Category</span>
        <select class="input" name="category"><option value="">All categories</option>@foreach($options['categories'] as $c)<option value="{{ $c->id }}" @selected($f['category']?->id === $c->id)>{{ $c->name }}</option>@endforeach</select>
      </label>
    @endif
  </div>
  <div class="row wrap mt-1" style="gap:6px">
    <button class="btn">Generate report</button>
    <a class="btn btn-outline" href="{{ route($prefix.'center', ['type' => $f['type']]) }}">Reset filters</a>
  </div>
  <p class="small muted mt-1 mb-0">{{ $report['about'] }}@if($f['admin'] && ! $f['supplier'] && array_intersect(['branch', 'item'], $uses)) Pick a supplier to filter by branch or item.@endif</p>
</form>

<div class="card rp-card">@include('reports._body')</div>

<p class="small muted mt-1">Charts and trends: <a href="{{ route(rtrim($prefix, '.')) }}">{{ ['supplier.reports.' => 'Business Reports', 'cashier.reports.' => 'Financial Reports'][$prefix] ?? 'Reports dashboard' }}</a>.</p>

@include('reports._styles')
<style>
  .rp-form { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 10px; }
  .rp-field { display: flex; flex-direction: column; gap: 4px; font-size: .82rem; font-weight: 600; }
  .rp-field .input { width: 100%; }
  .rp-wide { grid-column: span 2; }
  @media (max-width: 640px) { .rp-wide { grid-column: auto; } .rp-form { grid-template-columns: 1fr; } }
</style>
<script>
  (function () {
    var p = document.getElementById('rpPeriod');
    if (!p) return;
    var sync = function () {
      document.querySelectorAll('#rpForm [data-when]').forEach(function (el) {
        var show = (el.dataset.when === 'custom') === (p.value === 'custom');
        el.hidden = !show;
        el.querySelectorAll('input').forEach(function (i) { i.disabled = !show; });
      });
    };
    p.addEventListener('change', sync);
    sync();
  })();
</script>
@endsection
