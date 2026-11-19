{{-- Port of profileMenu() in reference js/ui/layout.js. Change list: the customer's "Switch to Supplier Mode" shows the shop name.
     Shop employees are customer accounts: "Switch to Business Mode" opens their shop (cashier workspace for a cashier). --}}
@php($u = $shellUser)
@php($sup = $u->supplier)
@php($shop = $u->shop())
@php($emp = $u->isEmployee())
@php($appStatus = ['pending' => ['Pending Review', 'b-gold'], 'revision' => ['Needs Revision', 'b-sky']])
<div class="dropdown">
  <button class="user-btn" data-dropdown aria-label="Account menu"><span class="avatar">{{ $u->initials() }}</span><span class="uname hide-sm">{{ $u->firstName() }}</span>
    @if($shop && ! $u->isStaff())<span class="mode-pill {{ $shellRole !== 'customer' ? 'sup' : '' }}">{{ $shellRole === 'customer' ? 'Customer' : ($emp ? 'Business' : 'Supplier') }}</span>@endif
  </button>
  <div class="menu">
    <div class="menu-head"><div class="strong">{{ $u->name }}</div><div class="small muted">{{ $u->email }}</div>
      @if(in_array($shellRole, ['supplier', 'cashier'], true) && $shop)<div class="small" style="margin-top:4px">{{ $shop->logo }} {{ $shop->name }}</div>@endif
    </div>
    @if($shellRole === 'customer' && $emp && $shop)
      <form method="POST" action="{{ route('mode.switch') }}">@csrf<input type="hidden" name="mode" value="supplier"><button class="menu-switch-top" title="Switch to Business Mode">{{ icon('store') }}<span class="ellipsis">Switch to Business Mode<span class="sub ellipsis">{{ \App\Models\ShopStaff::rolesLabel($u->orgRoles()) }} · {{ $shop->name }}</span></span></button></form>
    @elseif($shellRole === 'customer' && $sup && $sup->status === 'approved')
      <form method="POST" action="{{ route('mode.switch') }}">@csrf<input type="hidden" name="mode" value="supplier"><button class="menu-switch-top" title="Switch to Supplier Mode">{{ icon('store') }}<span class="ellipsis">Switch to your shop<span class="sub ellipsis">{{ $sup->name }}</span></span></button></form>
    @elseif(($shellRole === 'supplier' || $shellRole === 'cashier') && ! $u->isStaff())
      <form method="POST" action="{{ route('mode.switch') }}">@csrf<input type="hidden" name="mode" value="customer"><button class="menu-switch-top">{{ icon('swap') }}<span>Switch to Customer Mode<span class="sub">Browse and rent as a customer</span></span></button></form>
    @endif
    <div class="sep"></div>
    @if($shellRole === 'customer')
      <a href="{{ route('rentals.index') }}">{{ icon('list') }}My Rentals</a>
      <a href="{{ route('events.index') }}">{{ icon('calendar') }}My Events</a>
      <a href="{{ route('customer.payments') }}">{{ icon('card') }}Payment History</a>
      <a href="{{ route('profile') }}">{{ icon('user') }}Profile & Settings</a>
      <div class="sep"></div>
      @if(($sup && $sup->status === 'approved') || $u->staffOf)
      @elseif($sup && isset($appStatus[$sup->status]))
        <a href="{{ route('start-selling') }}">{{ icon('clock') }}Supplier application: <span class="badge {{ $appStatus[$sup->status][1] }}">{{ $appStatus[$sup->status][0] }}</span></a>
      @else
        <a href="{{ route('start-selling') }}" class="menu-switch">{{ icon('store') }}Start Selling / Become a Supplier</a>
      @endif
    @elseif(in_array($shellRole, ['supplier', 'cashier'], true) && $emp)
      <div class="menu-head small" style="padding-top:0">{{ \App\Models\ShopStaff::rolesLabel($u->orgRoles()) }} · {{ $shop?->name }}</div>
      @if($shop)<a href="{{ route('suppliers.show', $shop) }}">{{ icon('eye') }}View Public Shop</a>@endif
      <a href="{{ route('account') }}">{{ icon('user') }}Account</a>
    @elseif($shellRole === 'supplier')
      <a href="{{ route('supplier.shop') }}">{{ icon('store') }}Shop Profile & Settings</a>
      <a href="{{ route('supplier.team') }}">{{ icon('users') }}Team & Roles</a>
      <a href="{{ route('suppliers.show', $shop) }}">{{ icon('eye') }}View Public Shop</a>
      <a href="{{ route('account') }}">{{ icon('user') }}Account</a>
    @else
      <a href="{{ route('account') }}">{{ icon('user') }}Account Settings</a>
    @endif
    <div class="sep"></div>
    <form method="POST" action="{{ route('logout') }}" data-confirm="You will need to sign in again to manage your rentals." data-confirm-title="Log out?" data-confirm-ok="Log out">@csrf<button class="menu-item">{{ icon('logout') }}Log out</button></form>
  </div>
</div>
