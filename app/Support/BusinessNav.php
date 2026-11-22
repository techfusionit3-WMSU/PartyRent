<?php

namespace App\Support;

use App\Models\EventBooking;
use App\Models\EventPayment;
use App\Models\Supplier;

/** Sidebar of the business (event company) workspace, in the layouts.dash format: [key, route, icon, label, count, org permission]. */
class BusinessNav
{
    public static function config(Supplier $s): array
    {
        $inq = EventBooking::where('supplier_id', $s->id)->where('status', 'inquiry')->count();
        // event types still to apply for: none verified yet = everything waits on this page
        $types = $s->eventTypes()->where('status', 'verified')->exists() ? 0 : 1;
        $pay = EventPayment::where('status', 'pending')->whereHas('booking', fn ($q) => $q->where('supplier_id', $s->id))->count();

        return ['tag' => 'Business', 'items' => [
            ['Overview'], ['dashboard', 'business.dashboard', 'grid', 'Dashboard'],
            ['bookings', 'business.bookings', 'list', 'Event Bookings', $inq, 'orders.view'],
            ['calendar', 'business.calendar', 'calendar', 'Event Calendar', 0, 'orders.view'],
            ['teams', 'business.teams', 'users', 'Teams', 0, 'orders.view'],
            ['payments', 'business.payments', 'card', 'Event Payments', $pay, 'payments.view'],
            ['reviews', 'supplier.reviews', 'star', 'Reviews', 0, 'orders.view'],
            ['What you offer'], ['event-types', 'business.event-types', 'shield', 'Event Types', $types, 'shop.manage'], ['packages', 'business.packages', 'package', 'Event Packages', 0, 'products.manage'],
            ['Business'], ['settings', 'business.settings', 'settings', 'Bookings & Payment Settings', 0, 'shop.manage'],
            ['shop', 'supplier.shop', 'store', 'Business Profile', 0, 'shop.manage'], ['team', 'supplier.team', 'users', 'Team & Roles', 0, 'team.manage'],
            ['notifications', 'notifications', 'bell', 'Notifications'],
        ], 'bottom' => [
            ['dashboard', 'business.dashboard', 'grid', 'Home'], ['bookings', 'business.bookings', 'calendar', 'Bookings', 'orders.view'],
            ['payments', 'business.payments', 'card', 'Payments', 'payments.view'], ['packages', 'business.packages', 'package', 'Packages', 'products.manage'],
        ]];
    }
}
