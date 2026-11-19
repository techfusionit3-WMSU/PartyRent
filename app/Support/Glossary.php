<?php

namespace App\Support;

/**
 * Plain-language meanings of rental and payment terms, shown by term('key') as a small "?" next to the word.
 * Keep each meaning to one or two short sentences.
 */
class Glossary
{
    public const TERMS = [
        'down_payment' => ['Down payment', 'The first part of the price you pay after the shop accepts. It is deducted from the total, and your items are reserved once it is confirmed.'],
        'final_balance' => ['Final balance', 'What is left of the price after the down payment. Pay it on or before the event date.'],
        'reservation_fee' => ['Reservation fee', 'A small first payment that holds your booking. It is deducted from the total and usually not refundable.'],
        'full_payment' => ['Full payment', 'You pay the whole price in one payment after the shop accepts.'],
        'installment' => ['Installment', 'Paying the rest in smaller parts on dates you propose. The shop must approve the schedule, and the last part is due by the event date.'],
        'security_deposit' => ['Security deposit', 'A refundable amount held while you have the items. It comes back after the return check, minus any damage charges.'],
        'reserved' => ['Reserved', 'The items are set aside for you on your dates and nobody else can book them.'],
        'credited' => ['Credited', 'This payment counts toward the total; it is not an extra charge.'],
        'replacement_value' => ['Replacement value', 'What it costs to replace one unit if it is lost or cannot be repaired. Damage charges are based on it.'],
        'inspection' => ['Return inspection', 'The shop checks every returned item. Damage is charged by the shop\'s damage policy, and you can dispute it.'],
        'buffer' => ['Buffer time', 'Time kept free between two rentals of the same item for cleaning and travel.'],
        'payout' => ['Payout', 'Money PartyRent sends to your account for payments customers made online.'],
    ];

    /** [title, meaning] in the current language, or null. */
    public static function get(string $key): ?array
    {
        $t = self::TERMS[$key] ?? null;

        return $t ? [__($t[0]), __($t[1])] : null;
    }
}
