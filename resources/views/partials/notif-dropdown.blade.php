<div class="dropdown">
  <button class="icon-btn" data-dropdown aria-label="Notifications">{{ icon('bell') }}@if($shellUnread)<span class="dot-count" data-notif-count>{{ $shellUnread > 9 ? '9+' : $shellUnread }}</span>@endif</button>
  <div class="menu notif-menu">
    <div class="nm-head"><b>Notifications</b><button class="btn btn-xs btn-ghost" data-mark-all="{{ route('notifications.read') }}">Mark all read</button></div>
    <div class="notif-list">
      @forelse($shellNotifs->take(8) as $n)
        @include('partials.notif-item', ['n' => $n])
      @empty
        <div class="empty small">No notifications yet</div>
      @endforelse
    </div>
    <div style="padding:10px;text-align:center"><a href="{{ route('notifications') }}" class="small strong">View all notifications</a></div>
  </div>
</div>
