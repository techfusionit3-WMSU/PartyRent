@php($u = $shellUser)
@php($sup = $u->supplier)
@php($shop = $u->shop())
<div class="dropdown">
  <button class="user-btn" data-dropdown aria-label="Account menu"><span class="avatar">{{ $u->initials() }}</span><span class="uname hide-sm">{{ $u->firstName() }}</span>
    @if($shop && ! $u->isStaff())<span class="mode-pill {{ $shellRole === 'supplier' ? 'sup' : '' }}">{{ $shellRole === 'supplier' ? 'Rental Partner' : 'Customer' }}</span>@endif
  </button>
  <div class="menu">
    <div class="menu-head"><div class="strong">{{ $u->name }}</div><div class="small muted">{{ $u->email }}</div>
      @if($shellRole === 'supplier' && $shop)<div class="small" style="margin-top:4px">{{ $shop->logo }} {{ $shop->name }}@if($u->shopRole() !== 'owner') · {{ \App\Models\ShopStaff::ROLES[$u->shopRole()][0] }}@endif</div>@endif
    </div>
    <div class="sep"></div>
    @if($shellRole === 'customer')
      <a href="{{ route('rentals.index') }}">{{ icon('list') }}My Rentals</a>
      <a href="{{ route('customer.payments') }}">{{ icon('card') }}Payment History</a>
      <a href="{{ route('profile') }}">{{ icon('user') }}Profile & Settings</a>
      <div class="sep"></div>
      @if($shop)
        <form method="POST" action="{{ route('mode.switch') }}">@csrf<input type="hidden" name="mode" value="supplier"><button class="menu-item menu-switch" title="Open your shop">{{ icon('store') }}<span class="ellipsis">{{ $shop->name }}</span></button></form>
      @elseif($sup && $sup->status === 'pending')
        <a href="{{ route('start-selling') }}">{{ icon('clock') }}Rental Partner application: <span class="badge b-gold">Pending Review</span></a>
      @else
        <a href="{{ route('start-selling') }}" class="menu-switch">{{ icon('store') }}Start Selling / Become a Rental Partner</a>
      @endif
    @elseif($shellRole === 'supplier')
      @if($u->canShop('supplier.shop'))<a href="{{ route('supplier.shop') }}">{{ icon('store') }}Shop Profile & Settings</a>@endif
      <a href="{{ route('suppliers.show', $shop) }}">{{ icon('eye') }}View Public Shop</a>
      <a href="{{ route('account') }}">{{ icon('user') }}Account</a>
      <div class="sep"></div>
      <form method="POST" action="{{ route('mode.switch') }}">@csrf<input type="hidden" name="mode" value="customer"><button class="menu-item menu-switch">{{ icon('swap') }}Switch to Customer Mode</button></form>
    @else
      <a href="{{ route('account') }}">{{ icon('user') }}Account Settings</a>
    @endif
    <div class="sep"></div>
    <form method="POST" action="{{ route('logout') }}" data-confirm="You will need to sign in again to manage your rentals." data-confirm-title="Log out?" data-confirm-ok="Log out">@csrf<button class="menu-item">{{ icon('logout') }}Log out</button></form>
  </div>
</div>
