<?php

namespace App\Providers;

use App\Domain\Inventory;
use App\Domain\Notifier;
use App\Domain\Rentals;
use App\Models\Complaint;
use App\Models\Payment;
use App\Models\RentalRequest;
use App\Models\Review;
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
        \Illuminate\Auth\Notifications\VerifyEmail::toMailUsing(fn ($user, string $url) => (new \Illuminate\Notifications\Messages\MailMessage)
            ->subject('Verify your PartyRent email')
            ->greeting('Hi '.$user->firstName().'!')
            ->line('Thanks for signing up for PartyRent. Tap the button below to confirm this is your email address.')
            ->action('Verify Email Address', $url)
            ->line('This link expires in '.config('auth.verification.expire', 60).' minutes.')
            ->line("If you didn't create a PartyRent account, you can ignore this email."));

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
                $badges['reviews'] = Review::visible()->where('supplier_id', $u->supplier->id)->whereNull('reply')->count();
            } elseif ($role === 'cashier') {
                $badges['verification'] = Payment::where('status', 'pending')->count();
            } elseif ($role === 'superadmin') {
                $badges['applications'] = Supplier::where('status', 'pending')->count();
                $badges['complaints'] = Complaint::where('status', '!=', 'resolved')->count();
                $badges['verification'] = Payment::where('status', 'pending')->count();
                $badges['reviews'] = Review::whereHas('reports', fn ($q) => $q->where('status', 'open'))->count();
            } elseif ($role === 'admin') {
                $badges['applications'] = Supplier::where('status', 'pending')->count();
                $badges['complaints'] = Complaint::where('status', '!=', 'resolved')->count();
                $badges['reviews'] = Review::whereHas('reports', fn ($q) => $q->where('status', 'open'))->count();
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
