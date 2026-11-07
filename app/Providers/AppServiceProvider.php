<?php

namespace App\Providers;

use App\Domain\Inventory;
use App\Domain\Notifier;
use App\Domain\Rentals;
use App\Models\Complaint;
use App\Models\Payment;
use App\Models\RentalRequest;
use App\Models\Supplier;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Availability lookups are memoized for the lifetime of one HTTP request.
        $this->app->singleton(Inventory::class);
    }

    public function boot(): void
    {
        // Shared shell data: notifications, cart count and sidebar badges.
        View::composer(['layouts.market', 'layouts.dash'], function ($view) {
            $u = auth()->user();
            if (! $u) {
                return;
            }
            $role = $u->activeRole(session('mode'));
            $notifs = Notifier::for($u, session('mode'));
            $badges = [];
            if ($role === 'supplier' && $u->supplier) {
                $badges['requests'] = RentalRequest::where('supplier_id', $u->supplier->id)->where('status', 'pending')->count();
            } elseif ($role === 'cashier') {
                $badges['verification'] = Payment::where('status', 'pending')->count();
            } elseif ($role === 'admin') {
                $badges['applications'] = Supplier::where('status', 'pending')->count();
                $badges['complaints'] = Complaint::where('status', '!=', 'resolved')->count();
            }
            $view->with([
                'shellUser' => $u,
                'shellRole' => $role,
                'shellNotifs' => $notifs,
                'shellUnread' => $notifs->filter(fn ($n) => ! $n->isReadBy($u->id))->count(),
                'shellCart' => $role === 'customer' ? Rentals::cartCount($u) : 0,
                'shellBadges' => $badges,
                'shellPendingRentals' => $role === 'customer' ? RentalRequest::where('customer_id', $u->id)->whereIn('status', ['accepted', 'pending'])->count() : 0,
            ]);
        });
    }
}
