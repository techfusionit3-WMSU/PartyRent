@extends($layout, ['title' => 'Notifications', 'nav' => 'notifications'])

@section('content')
<div class="page-head"><div><h1>Notifications</h1><p>Updates for your {{ active_role() }} account.</p></div>
  <div class="row">
    <div class="seg"><a href="{{ route('notifications') }}" class="{{ $filter !== 'unread' ? 'active' : '' }}" style="text-decoration:none"><button type="button" class="{{ $filter !== 'unread' ? 'active' : '' }}">All</button></a><a href="{{ route('notifications', ['filter' => 'unread']) }}"><button type="button" class="{{ $filter === 'unread' ? 'active' : '' }}">Unread</button></a></div>
    <form method="POST" action="{{ route('notifications.read') }}">@csrf<button class="btn btn-sm">{{ icon('check') }}Mark all read</button></form>
  </div>
</div>
<div class="card pad-0">
  @forelse($list as $n)
    @include('partials.notif-item', ['n' => $n])
  @empty
    @include('partials.empty', ['emoji' => '🔔', 'title' => "You're all caught up", 'text' => 'New updates will appear here.'])
  @endforelse
</div>
@endsection
