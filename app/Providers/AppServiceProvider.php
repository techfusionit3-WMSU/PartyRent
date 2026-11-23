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
        // The request memo (Inventory) never outlives a change to what it remembers.
        foreach ([\App\Models\Product::class, \App\Models\Package::class, \App\Models\Supplier::class, \App\Models\Discount::class] as $m) {
            $m::saved(fn () => inv()->flush());
            $m::deleted(fn () => inv()->flush());
        }

        // Event companies (Organization suppliers) don't rent single items: their item listings and item packages never go live.
        \App\Models\Product::saving(function ($p) {
            if ($p->status === 'active' && \App\Models\Supplier::eventCompanies() && \App\Models\Supplier::whereKey($p->supplier_id)->value('type') === 'organization') {
                $p->status = 'inactive';
            }
        });
        \App\Models\Package::saving(function ($k) {
            if ($k->active && \App\Models\Supplier::eventCompanies() && \App\Models\Supplier::whereKey($k->supplier_id)->value('type') === 'organization') {
                $k->active = false;
            }
        });

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
            \App\Domain\Shortfalls::escalateDue(); // proposal deadlines (also run by the scheduler)
            $openCases = fn () => \App\Models\ShortfallCase::where('status', '!=', 'resolved');
            if ($role === 'supplier' && ($shop = $u->shop())) {
                $badges['cases'] = $openCases()->where('supplier_id', $shop->id)->count();
                $badges['requests'] = RentalRequest::where('supplier_id', $shop->id)->where('status', 'pending')->count();
                $badges['payments'] = Payment::where('status', 'pending')->whereIn('method', ['cash', 'cod'])->whereHas('rentalRequest', fn ($q) => $q->where('supplier_id', $shop->id))->count();
                if ($shop->isOrganization()) { // event company role modules
                    $badges['inquiries'] = \App\Models\EventBooking::where('supplier_id', $shop->id)->where('status', 'inquiry')->count();
                    $badges['eventPayments'] = \App\Models\EventPayment::where('status', 'pending')->whereHas('booking', fn ($q) => $q->where('supplier_id', $shop->id))->count();
                }
            } elseif ($role === 'cashier') {
                $shop = $u->shop();
                // a shop's cashier confirms cash only; PartyRent's cashier checks transfers for every shop
                $badges['verification'] = $shop
                    ? Payment::where('status', 'pending')->whereIn('method', ['cash', 'cod'])->whereHas('rentalRequest', fn ($r) => $r->where('supplier_id', $shop->id))->count()
                    : Payment::where('status', 'pending')->whereNotIn('method', ['cash', 'cod'])->count();
            } elseif ($role === 'superadmin') {
                $badges['applications'] = Supplier::where('status', 'pending')->count() + Supplier::where('status', 'approved')->where('biz_status', 'pending')->count();
                $badges['complaints'] = Complaint::where('status', '!=', 'resolved')->count();
                $badges['cases'] = \App\Models\ShortfallCase::where('status', 'escalated')->count();
                $badges['verification'] = Payment::where('status', 'pending')->whereNotIn('method', ['cash', 'cod'])->count();
                $badges['reviews'] = Review::whereHas('reports', fn ($q) => $q->where('status', 'open'))->count();
                $badges['payouts'] = \App\Models\PayoutBatch::where('status', 'pending')->count();
                $badges['eventTypes'] = \App\Models\SupplierEventType::where(fn ($q) => $q->where('status', 'pending')->orWhere('raise_pending', true))->count() + \App\Models\EventType::where('status', 'requested')->count();
            } elseif ($role === 'admin') {
                $badges['applications'] = Supplier::where('status', 'pending')->count() + Supplier::where('status', 'approved')->where('biz_status', 'pending')->count();
                $badges['complaints'] = Complaint::where('status', '!=', 'resolved')->count();
                $badges['cases'] = \App\Models\ShortfallCase::where('status', 'escalated')->count();
                $badges['reviews'] = Review::whereHas('reports', fn ($q) => $q->where('status', 'open'))->count();
                $badges['verification'] = Payment::where('status', 'pending')->whereNotIn('method', ['cash', 'cod'])->count();
                $badges['payouts'] = \App\Models\PayoutBatch::where('status', 'pending')->count();
                $badges['eventTypes'] = \App\Models\SupplierEventType::where(fn ($q) => $q->where('status', 'pending')->orWhere('raise_pending', true))->count() + \App\Models\EventType::where('status', 'requested')->count();
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
