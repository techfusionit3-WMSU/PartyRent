{{-- Port of profileMenu() in reference js/ui/layout.js. Change list: the customer's "Switch to Supplier Mode" shows the shop name. --}}
@php($u = $shellUser)
@php($sup = $u->supplier)
@php($shop = $u->shop())
@php($emp = $u->isEmployee())
@php($appStatus = ['pending' => ['Pending Review', 'b-gold'], 'revision' => ['Needs Revision', 'b-sky']])
<div class="dropdown">
  <button class="user-btn" data-dropdown aria-label="Account menu"><span class="avatar">{{ $u->initials() }}</span><span class="uname hide-sm">{{ $u->firstName() }}</span>
    @if($sup && $sup->status === 'approved' && ! $u->isStaff() && $shellRole !== 'cashier')<span class="mode-pill {{ $shellRole === 'supplier' ? 'sup' : '' }}">{{ $shellRole === 'supplier' ? 'Supplier' : 'Customer' }}</span>@endif
  </button>
  <div class="menu">
    <div class="menu-head"><div class="strong">{{ $u->name }}</div><div class="small muted">{{ $u->email }}</div>
      @if(in_array($shellRole, ['supplier', 'cashier'], true) && $shop)<div class="small" style="margin-top:4px">{{ $shop->logo }} {{ $shop->name }}</div>@endif
    </div>
    <div class="sep"></div>
    @if($shellRole === 'customer')
      <a href="{{ route('rentals.index') }}">{{ icon('list') }}My Rentals</a>
      <a href="{{ route('customer.payments') }}">{{ icon('card') }}Payment History</a>
      <a href="{{ route('profile') }}">{{ icon('user') }}Profile & Settings</a>
      <div class="sep"></div>
      @if($sup && $sup->status === 'approved')
        <form method="POST" action="{{ route('mode.switch') }}">@csrf<input type="hidden" name="mode" value="supplier"><button class="menu-item menu-switch" title="Switch to Supplier Mode">{{ icon('store') }}<span class="ellipsis">{{ $sup->name }}</span></button></form>
      @elseif($sup && isset($appStatus[$sup->status]))
        <a href="{{ route('start-selling') }}">{{ icon('clock') }}Supplier application: <span class="badge {{ $appStatus[$sup->status][1] }}">{{ $appStatus[$sup->status][0] }}</span></a>
      @else
        <a href="{{ route('start-selling') }}" class="menu-switch">{{ icon('store') }}Start Selling / Become a Supplier</a>
      @endif
    @elseif($shellRole === 'supplier' && $emp)
      <div class="menu-head small" style="padding-top:0">{{ \App\Models\ShopStaff::ORG_ROLES[$u->orgRole()] ?? '' }} · {{ $shop?->name }}</div>
      @if($shop)<a href="{{ route('suppliers.show', $shop) }}">{{ icon('eye') }}View Public Shop</a>@endif
      <a href="{{ route('account') }}">{{ icon('user') }}Account</a>
    @elseif($shellRole === 'supplier')
      <a href="{{ route('supplier.shop') }}">{{ icon('store') }}Shop Profile & Settings</a>
      <a href="{{ route('supplier.team') }}">{{ icon('users') }}Team & Roles</a>
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
