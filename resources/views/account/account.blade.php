@extends($layout, ['title' => 'Account settings', 'nav' => 'account'])

@section('content')
<div class="page-head"><div><h1>Account settings</h1><p>{{ $u->email }} · {{ implode(', ', array_map('ucfirst', $u->roles)) }}{{ $u->supplier ? ' + Supplier' : '' }}</p></div></div>
<div class="grid grid-2">@include('partials.account-forms', ['u' => $u])</div>
@endsection
