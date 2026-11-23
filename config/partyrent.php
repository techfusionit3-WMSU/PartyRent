<?php

/*
 * Country-specific settings. To run PartyRent in another country, point
 * `locations` at that country's dataset and change the currency block;
 * no code changes are needed.
 */
return [
    'country' => env('PARTYRENT_COUNTRY', 'PH'),

    'currency' => [
        'code' => env('PARTYRENT_CURRENCY', 'PHP'),
        'symbol' => env('PARTYRENT_CURRENCY_SYMBOL', '₱'),
    ],

    // Flat list of [code, name, type, parentCode, lat, lng] rows (see the file header).
    'locations' => database_path('data/locations-ph.php'),

    // Straight-line (haversine) distance × this factor ≈ road distance.
    'road_factor' => 1.3,

    // Map pins (Leaflet + OpenStreetMap tiles). Bounds keep pins inside the country.
    'map' => [
        'center' => [12.8797, 121.7740],
        'zoom' => 6,
        'bounds' => [[4.2, 116.0], [21.5, 127.5]],
        'tiles' => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
        'attribution' => '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
        // A shop pin farther than this from the centre of its chosen city is probably a mistake.
        'max_km_from_city' => 60,
    ],

    /*
     * Event-company module (whole-event quotations, coordinators, crews, event parts and venues).
     * PartyRent rents decorations and party items; it does not book or manage events, so this is OFF.
     * Off: companies run as ordinary rental shops, the event-booking pages are closed and their links hidden.
     * The code and data stay (true switches it back on).
     */
    'event_companies' => (bool) env('PARTYRENT_EVENT_COMPANIES', false),
];
