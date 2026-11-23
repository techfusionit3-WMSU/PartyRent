<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;

/**
 * Captures the values before and after each database save made while a shop action runs, from the model
 * events Eloquent fires when the row is actually written (not from what the form sent). RecordStaffAction
 * turns them into the "Previous → New" lines of the Store History.
 *
 * Output: [['record' => 'Product', 'name' => 'White Monoblock Chair', 'action' => 'updated',
 *           'fields' => [['field' => 'Price per day', 'old' => '₱15.00', 'new' => '₱18.00'], …]], …]
 */
class ChangeRecorder
{
    /** Model => [friendly name, field used as the record's name]. Other models are not shown. */
    public const MODELS = [
        \App\Models\Product::class => ['Product', 'name'], \App\Models\Package::class => ['Package', 'name'],
        \App\Models\EventPackage::class => ['Event package', 'name'], \App\Models\Discount::class => ['Discount', 'name'],
        \App\Models\LocationStock::class => ['Stock at a location', null], \App\Models\InventoryLocation::class => ['Inventory location', 'name'],
        \App\Models\StockTransfer::class => ['Stock transfer', null], \App\Models\Hold::class => ['Held units', null],
        \App\Models\Supplier::class => ['Store', 'name'], \App\Models\ShopStaff::class => ['Team member', null],
        \App\Models\StaffInvite::class => ['Team invitation', 'email'], \App\Models\RentalRequest::class => ['Rental', 'code'],
        \App\Models\Payment::class => ['Payment', 'code'], \App\Models\Payout::class => ['Payout', 'code'],
        \App\Models\ShortfallCase::class => ['Case', 'code'], \App\Models\EventBooking::class => ['Event booking', 'code'],
        \App\Models\EventPayment::class => ['Event payment', 'code'], \App\Models\EventTeam::class => ['Team', 'name'],
        \App\Models\SupplierEventType::class => ['Event type application', null], \App\Models\Review::class => ['Review', null],
    ];

    /** Fields that are bookkeeping, not something a person changed. */
    private const SKIP = ['id', 'created_at', 'updated_at', 'remember_token', 'password', 'reference_key', 'sort', 'checked_at',
        'gateway_url', 'reserved_at', 'last_reminder_at', 'proof_path', 'images', 'photos', 'logo_path', 'cover_path'];

    /** Field => friendly label (others: "unit_price" → "Unit price"). */
    private const LABELS = [
        'price' => 'Price', 'price_per_day' => 'Price per day', 'rate' => 'Rate', 'deposit' => 'Security deposit', 'qty' => 'Quantity',
        'total' => 'Total quantity', 'total_qty' => 'Total quantity', 'status' => 'Status', 'active' => 'Active', 'is_draft' => 'Draft',
        'name' => 'Name', 'description' => 'Description', 'amount' => 'Amount', 'method' => 'Method', 'reject_reason' => 'Reason',
        'decline_reason' => 'Reason', 'cancel_reason' => 'Reason', 'replacement_value' => 'Replacement value', 'damaged' => 'Damaged',
        'missing' => 'Missing', 'maintenance' => 'In maintenance', 'payout_method' => 'Payout method', 'payout_bank' => 'Payout bank',
        'payout_account_name' => 'Payout account name', 'payout_account_no' => 'Payout account number', 'payout_schedule' => 'Payout schedule',
        'roles' => 'Roles', 'role' => 'Role', 'perms_add' => 'Extra permissions', 'perms_remove' => 'Removed permissions',
        'notes' => 'Notes', 'internal_notes' => 'Internal notes', 'pct' => 'Discount %', 'value' => 'Value', 'min_order' => 'Minimum order',
        'starts_on' => 'Starts', 'ends_on' => 'Ends', 'phone' => 'Phone', 'email' => 'Email', 'hours' => 'Opening hours',
        'delivery_fee' => 'Delivery fee', 'methods' => 'Payment methods', 'fulfillment' => 'Delivery / pickup', 'receipt_no' => 'Receipt',
    ];

    private const MONEY = ['price', 'price_per_day', 'rate', 'deposit', 'amount', 'replacement_value', 'delivery_fee', 'min_order', 'total_amount', 'commission', 'net'];

    /** The event dispatcher the listeners are on (a new app instance has a new one). */
    private static ?int $listening = null;
    private static bool $on = false;
    private static array $rows = [];

    public static function start(): void
    {
        self::$rows = [];
        self::$on = true;
        $events = app('events');
        if (self::$listening === spl_object_id($events)) {
            return;
        }
        self::$listening = spl_object_id($events);
        foreach (['created', 'updated', 'deleted'] as $ev) {
            Event::listen('eloquent.'.$ev.': *', function (string $name, array $data) use ($ev) {
                if (self::$on && ($data[0] ?? null) instanceof Model) {
                    self::capture($ev, $data[0]);
                }
            });
        }
    }

    /** Stop and return what changed (at most 8 records, 12 fields each). */
    public static function stop(): array
    {
        self::$on = false;
        $rows = array_slice(array_values(self::$rows), 0, 8);
        self::$rows = [];

        return $rows;
    }

    private static function capture(string $ev, Model $m): void
    {
        $meta = self::MODELS[get_class($m)] ?? null;
        if (! $meta) {
            return;
        }
        [$label, $nameField] = $meta;
        $fields = [];
        if ($ev === 'updated') {
            foreach ($m->getChanges() as $k => $new) {
                if (in_array($k, self::SKIP, true) || str_ends_with($k, '_id') || str_ends_with($k, '_at')) {
                    continue;
                }
                $old = $m->getOriginal($k);
                $o = self::fmt($k, $old);
                $n = self::fmt($k, $m->getAttribute($k));
                if ($o !== $n) {
                    $fields[] = ['field' => self::LABELS[$k] ?? ucfirst(str_replace('_', ' ', $k)), 'old' => $o, 'new' => $n];
                }
            }
            if (! $fields) {
                return; // only bookkeeping changed
            }
        }
        $key = get_class($m).'#'.$m->getKey();
        $name = $nameField ? (string) $m->getAttribute($nameField) : '';
        if (isset(self::$rows[$key])) { // several saves of one record in one action: keep the first old value, the last new value
            $prev = collect(self::$rows[$key]['fields'])->keyBy('field');
            foreach ($fields as $f) {
                $prev[$f['field']] = isset($prev[$f['field']]) ? ['field' => $f['field'], 'old' => $prev[$f['field']]['old'], 'new' => $f['new']] : $f;
            }
            self::$rows[$key]['fields'] = $prev->values()->all();
            if ($ev !== 'updated') {
                self::$rows[$key]['action'] = $ev;
            }

            return;
        }
        self::$rows[$key] = ['record' => $label, 'name' => mb_substr($name, 0, 80), 'action' => $ev, 'fields' => array_slice($fields, 0, 12)];
    }

    /** A value as a person would read it. */
    private static function fmt(string $k, mixed $v): string
    {
        if ($v === null || $v === '') {
            return '—';
        }
        if (is_bool($v) || in_array($k, ['active', 'is_draft'], true)) {
            return $v ? 'Yes' : 'No';
        }
        if ($v instanceof \DateTimeInterface) {
            return $v->format('M j, Y g:i A');
        }
        if (is_string($v) && ($v[0] === '[' || $v[0] === '{')) {
            $d = json_decode($v, true);
            $v = is_array($d) ? $d : $v;
        }
        if (is_array($v)) {
            $flat = array_filter($v, fn ($x) => is_scalar($x));

            return count($flat) === count($v) && count($v) <= 6 ? (implode(', ', $v) ?: '—') : 'changed ('.count($v).' entries)';
        }
        if (in_array($k, self::MONEY, true) && is_numeric($v)) {
            return money((float) $v);
        }
        if ($k === 'status') {
            return ucfirst(str_replace('_', ' ', (string) $v));
        }

        return mb_strimwidth((string) $v, 0, 120, '…');
    }
}
