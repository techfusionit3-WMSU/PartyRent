<?php

namespace App\Domain;

use App\Models\Category;
use App\Models\InventoryLocation;
use App\Models\Package;
use App\Models\Payment;
use App\Models\Product;
use App\Models\RentalRequest;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Support\BranchScope;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Printable reports for suppliers (their own business) and admins (the whole platform).
 *
 * One engine for both: filters() reads and checks the filters (a supplier can only name its own branches and items;
 * anything else is a 404), build() reads the real records and returns the report as summary figures and tables.
 * The page, the print layout and the CSV download all render the same result, so their totals always agree.
 *
 * Money rules used everywhere:
 *   Gross        = what the rental cost before discounts (rental fee + delivery + services + discounts given)
 *   Discounts    = shop promotions on the rental + a special (custom) discount for that customer
 *   Charges      = what the customer owes for the rental (gross − discounts)
 *   Collected    = verified rent payments − verified rent refunds (deposits are held money, not income)
 *   Damage       = damage charges assessed at return; collected = paid directly + taken from the deposit
 *   Confirmed    = accepted, in progress or finished: pending, declined and cancelled rentals are never sales
 */
class ReportCenter
{
    public const GROUPS = ['money' => 'Rentals & money', 'stock' => 'Items & stock', 'people' => 'Branches, customers & suppliers'];

    /** key => [title, group, what it shows, filters it uses, dated?, admin only?] */
    public const TYPES = [
        'transactions' => ['Rental transactions', 'money', 'Every rental booked in the period: charges, discounts, payments and what is still owed.', ['branch', 'status', 'pay', 'item'], true, false],
        'sales' => ['Sales & revenue', 'money', 'Money collected by day, week or month, and the value of confirmed rentals held in the period by branch, item and package.', ['branch', 'item', 'category'], true, false],
        'payments' => ['Payments', 'money', 'Every payment and refund dated in the period, with its status and method.', ['branch', 'pstatus', 'method'], true, false],
        'outstanding' => ['Outstanding balances', 'money', 'Confirmed rentals that still owe rent or damage charges, as of today.', ['branch'], false, false],
        'discounts' => ['Discounts given', 'money', 'Shop promotions and special customer discounts on rentals booked in the period.', ['branch'], true, false],
        'cancellations' => ['Cancellations & refunds', 'money', 'Rentals cancelled or declined in the period, why, and what was refunded or kept.', ['branch'], true, false],
        'inventory' => ['Inventory', 'stock', 'Stock of every item today: by branch, damaged, missing, written off, rented out and usable.', ['branch', 'category', 'item'], false, false],
        'items' => ['Item & package performance', 'stock', 'How often each item and package was rented in the period and what it brought in.', ['branch', 'category'], true, false],
        'damage' => ['Damage & compensation', 'stock', 'Items found damaged or missing at return, what was charged and what has been collected.', ['branch', 'item'], true, false],
        'returns' => ['Returns & overdue', 'stock', 'Rentals due back in the period: returned on time, returned late or not back yet.', ['branch'], true, false],
        'stock' => ['Stock movements', 'stock', 'Stock added, adjusted, sold, transferred between branches or marked damaged.', ['branch', 'item'], true, false],
        'branches' => ['Branch performance', 'people', 'Rentals, money, cancellations, damage and balances for each branch side by side.', [], true, false],
        'customers' => ['Customer activity', 'people', 'Rentals, spending and balances per customer (names only, no contact details).', ['branch'], true, false],
        'suppliers' => ['Supplier performance', 'people', 'Every supplier side by side: rentals, value, money collected, commission, cancellations and damage.', [], true, true],
    ];

    public const PERIODS = ['day' => 'Daily', 'week' => 'Weekly', 'month' => 'Monthly', 'year' => 'Yearly', 'custom' => 'Custom dates'];

    public const PAY_STATUSES = ['Pending', 'Partially Paid', 'Fully Paid', 'Overdue', 'Refunded', 'Partially Refunded', 'Closed'];

    /** Statuses that are not (or no longer) a sale. */
    public const NOT_SALE = ['pending', 'declined', 'cancelled'];

    public const MOVE_LABEL = ['restock' => 'Stock added', 'adjust' => 'Stock adjusted', 'sold' => 'Sold', 'transfer' => 'Moved to another branch', 'damaged' => 'Marked damaged'];

    /** Report types this viewer may open. */
    public static function types(bool $admin): array
    {
        return array_filter(self::TYPES, fn ($t) => $admin || ! $t[5]);
    }

    /**
     * Read and check the filters. $shop is the supplier a supplier user is locked to (null for admins).
     * A branch, item or package that is not the (selected) supplier's is refused with a 404, never quietly ignored.
     */
    public static function filters(Request $q, ?Supplier $shop): array
    {
        $admin = $shop === null;
        $types = self::types($admin);
        $type = array_key_exists((string) $q->query('type'), $types) ? (string) $q->query('type') : 'transactions';
        $uses = $types[$type][3];
        $period = array_key_exists((string) $q->query('period'), self::PERIODS) ? (string) $q->query('period') : 'month';
        $date = self::date($q->query('date')) ?? today_iso();
        $d = Carbon::parse($date);
        [$from, $to] = match ($period) {
            'day' => [$date, $date],
            'week' => [$d->copy()->startOfWeek()->toDateString(), $d->copy()->endOfWeek()->toDateString()],
            'year' => [$d->copy()->startOfYear()->toDateString(), $d->copy()->endOfYear()->toDateString()],
            'custom' => [self::date($q->query('from')) ?? substr(today_iso(), 0, 7).'-01', self::date($q->query('to')) ?? today_iso()],
            default => [$d->copy()->startOfMonth()->toDateString(), $d->copy()->endOfMonth()->toDateString()],
        };
        if ($to < $from) {
            [$from, $to] = [$to, $from];
        }

        $supplier = $shop;
        if ($admin && $type !== 'suppliers' && filled($q->query('supplier'))) {
            $supplier = Supplier::find((int) $q->query('supplier'));
            abort_unless($supplier, 404);
        }
        // a shop user: only their branches (BranchScope); "all" = every branch they may see (the whole organization for
        // the owner / Organization Admins); no choice yet = the branch the dashboard shows
        $allowed = $admin ? null : BranchScope::allowed();
        $branch = null;
        $pickedAll = (string) $q->query('branch') === 'all';
        if (in_array('branch', $uses, true) && filled($q->query('branch')) && ! $pickedAll) {
            $branch = $supplier ? InventoryLocation::where('supplier_id', $supplier->id)->find((int) $q->query('branch')) : null;
            abort_unless($branch && ($allowed === null || in_array($branch->id, $allowed, true)), 404);
        } elseif (! $admin && in_array('branch', $uses, true) && ! $q->has('branch')) {
            $branch = BranchScope::current();
        }
        if (! $branch && $allowed !== null && count($allowed) === 1 && in_array('branch', $uses, true)) {
            $branch = InventoryLocation::find($allowed[0]);
        }
        $branchSet = ! $branch && $allowed !== null ? $allowed : null;
        $item = null;
        if (in_array('item', $uses, true) && filled($q->query('item'))) {
            abort_unless($supplier && preg_match('/^([pk]):(\d+)$/', (string) $q->query('item'), $m), 404);
            $item = $m[1] === 'p' ? Product::where('supplier_id', $supplier->id)->find((int) $m[2]) : Package::where('supplier_id', $supplier->id)->find((int) $m[2]);
            abort_unless($item && ($m[1] === 'p' || in_array($type, ['transactions', 'sales'], true)), 404);
            $item = ['kind' => $m[1] === 'p' ? 'item' : 'package', 'id' => $item->id, 'name' => $item->name, 'key' => $m[0]];
        }
        $pick = fn (string $f, array $allowed) => in_array($f, $uses, true) && in_array($q->query($f), $allowed, true) ? $q->query($f) : null;

        return [
            'type' => $type, 'admin' => $admin, 'period' => $period, 'date' => $date, 'from' => $from, 'to' => $to,
            'supplier' => $supplier, 'branch' => $branch, 'branchSet' => $branchSet, 'item' => $item,
            'category' => in_array('category', $uses, true) && filled($q->query('category')) ? Category::find((int) $q->query('category')) : null,
            'status' => $pick('status', array_keys(RentalRequest::STATUS)),
            'pay' => $pick('pay', self::PAY_STATUSES),
            'pstatus' => $pick('pstatus', ['verified', 'pending', 'rejected']),
            'method' => $pick('method', array_keys(Payment::METHOD_LABEL)),
        ];
    }

    private static function date($d): ?string
    {
        return is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) ? $d : null;
    }

    /** The filters as query parameters (for links to print / download the same report). */
    public static function query(array $f): array
    {
        return array_filter([
            'type' => $f['type'], 'period' => $f['period'],
            'date' => $f['period'] !== 'custom' ? $f['date'] : null,
            'from' => $f['period'] === 'custom' ? $f['from'] : null, 'to' => $f['period'] === 'custom' ? $f['to'] : null,
            'supplier' => $f['admin'] ? $f['supplier']?->id : null, 'branch' => $f['branch']?->id ?? ($f['admin'] ? null : 'all'), 'item' => $f['item']['key'] ?? null,
            'category' => $f['category']?->id, 'status' => $f['status'], 'pay' => $f['pay'], 'pstatus' => $f['pstatus'], 'method' => $f['method'],
        ], fn ($v) => $v !== null && $v !== '');
    }

    /** "Applied filters" as label => value, for the report header. */
    public static function applied(array $f): array
    {
        return array_filter([
            'Supplier' => $f['admin'] ? ($f['supplier']?->name ?? ($f['type'] === 'suppliers' ? null : 'All suppliers')) : null,
            'Branch' => $f['branch']?->name ?? ($f['branchSet'] ? 'Your branches: '.InventoryLocation::whereIn('id', $f['branchSet'])->pluck('name')->implode(', ')
                : (in_array('branch', self::TYPES[$f['type']][3], true) || $f['type'] === 'branches' ? ($f['admin'] ? 'All branches' : 'All branches (whole organization)') : null)),
            'Item / package' => $f['item']['name'] ?? null,
            'Category' => $f['category']?->name,
            'Rental status' => $f['status'] ? RentalRequest::statusLabel($f['status']) : null,
            'Payment status' => $f['pay'] ?? ($f['pstatus'] ? Payment::STATUS_LABEL[$f['pstatus']] : null),
            'Payment method' => $f['method'] ? Payment::METHOD_LABEL[$f['method']] : null,
        ]);
    }

    public static function periodText(array $f): string
    {
        if (! self::TYPES[$f['type']][4]) {
            return 'As of '.fmt_date(today_iso());
        }

        return match ($f['period']) {
            'day' => fmt_date($f['from']),
            'month' => Carbon::parse($f['from'])->format('F Y'),
            'year' => substr($f['from'], 0, 4),
            default => fmt_range($f['from'], $f['to']),
        };
    }

    /** Choices for the filter selects (only the selected / locked supplier's branches and items). */
    public static function options(array $f): array
    {
        $sid = $f['supplier']?->id;

        return [
            'suppliers' => $f['admin'] ? Supplier::approved()->orderBy('name')->get(['id', 'name']) : collect(),
            'branches' => $sid ? InventoryLocation::where('supplier_id', $sid)->when(! $f['admin'] && BranchScope::allowed() !== null, fn ($q) => $q->whereIn('id', BranchScope::allowed()))
                ->orderByDesc('is_main')->orderBy('name')->get(['id', 'name', 'active']) : collect(),
            'orgLevel' => $f['admin'] || BranchScope::allowed() === null,
            'products' => $sid ? Product::where('supplier_id', $sid)->where('status', '!=', 'deleted')->orderBy('name')->get(['id', 'name']) : collect(),
            'packages' => $sid ? Package::where('supplier_id', $sid)->where('status', '!=', 'deleted')->orderBy('name')->get(['id', 'name']) : collect(),
            'categories' => Category::orderBy('name')->get(['id', 'name']),
        ];
    }

    /* ------------------------------------------------------------------ build ------------------------------------------------------------------ */

    /** @return array{title:string, about:string, summary:array, tables:array, notes:array} */
    public static function build(array $f): array
    {
        $out = self::{'r'.ucfirst($f['type'])}($f);

        return ['title' => self::TYPES[$f['type']][0], 'about' => self::TYPES[$f['type']][2], 'summary' => $out['summary'] ?? [], 'tables' => $out['tables'] ?? [], 'notes' => $out['notes'] ?? []];
    }

    /** Rentals in scope (supplier / branch), with what the money columns need. */
    private static function rentals(array $f)
    {
        return RentalRequest::query()
            ->when($f['supplier'], fn ($q, $s) => $q->where('supplier_id', $s->id))
            ->when($f['branch'], fn ($q, $b) => $q->where('branch_id', $b->id))
            ->when($f['branchSet'], fn ($q, $ids) => $q->whereIn('branch_id', $ids))
            ->with(['customer:id,name', 'supplier:id,name', 'branch:id,name', 'payments']);
    }

    /** Rentals that include the filtered item / package as a line of their own. */
    private static function withItem(Collection $list, array $f): Collection
    {
        if (! $f['item']) {
            return $list;
        }

        return $list->filter(fn ($r) => collect($r->lines ?? [])->contains(fn ($l) => ($l['type'] ?? 'item') === $f['item']['kind'] && (int) ($l['ref_id'] ?? 0) === $f['item']['id']))->values();
    }

    private static function inRange(?string $d, array $f): bool
    {
        return $d !== null && $d >= $f['from'] && $d <= $f['to'];
    }

    /** The money figures of one rental (see the class comment). */
    public static function money(RentalRequest $r): array
    {
        $p = $r->pricing ?? [];
        $fin = $r->fin();
        $promo = (float) ($p['discount'] ?? 0);
        $special = (float) ($p['customDiscount']['amount'] ?? 0);
        $charges = (float) $fin['payable'];

        return [
            'gross' => r2($charges + $promo + $special), 'promo' => $promo, 'special' => $special, 'discounts' => r2($promo + $special),
            'charges' => $charges, 'paid' => (float) $fin['paidRental'], 'refunded' => (float) $fin['refunded'],
            'collected' => r2((float) $fin['paidRental'] - (float) $fin['refunded']), 'balance' => (float) $fin['balance'],
            'damage' => (float) $fin['compensation'], 'damageDue' => (float) $fin['compensationDue'],
            'damagePaid' => r2((float) $fin['compPaidDirect'] + (float) $fin['depositApplied']),
            'pay' => $fin['status'], 'overdue' => (bool) $fin['isOverdue'],
        ];
    }

    private static function isSale(RentalRequest $r): bool
    {
        return ! in_array($r->status, self::NOT_SALE, true);
    }

    /** The shared leading columns: rental, (supplier), branch. */
    private static function who(array $f): array
    {
        return array_filter(['supplier' => ! $f['supplier'] ? ['Supplier', 'text'] : null, 'branch' => ['Branch', 'text']]);
    }

    private static function whoCells(RentalRequest $r, array $f): array
    {
        return array_merge(! $f['supplier'] ? [$r->supplier?->name] : [], [$r->branch?->name ?? 'Not set']);
    }

    private static function dates(RentalRequest $r): string
    {
        return $r->start_date === $r->end_date ? fmt_date($r->start_date) : fmt_range($r->start_date, $r->end_date);
    }

    private static function table(string $title, array $cols, array $rows, ?array $total = null, ?string $note = null, ?string $empty = null): array
    {
        return ['title' => $title, 'cols' => $cols, 'rows' => $rows, 'total' => $total, 'note' => $note, 'empty' => $empty ?? 'Nothing in this period.'];
    }

    /** Sum the money columns of $rows; non-money columns get $label (first) or blank. */
    private static function totalRow(array $cols, array $rows, string $label = 'Total'): array
    {
        $out = [];
        foreach (array_values($cols) as $i => $c) {
            $out[] = in_array($c[1], ['money', 'int'], true) ? r2(array_sum(array_map(fn ($r) => (float) ($r[$i] ?? 0), $rows))) : ($i === 0 ? $label : '');
        }

        return $out;
    }

    /* ---------------------------------------------------------------- reports ---------------------------------------------------------------- */

    private static function rTransactions(array $f): array
    {
        $list = self::withItem(self::rentals($f)->whereBetween('created_at', [$f['from'].' 00:00:00', $f['to'].' 23:59:59'])
            ->when($f['status'], fn ($q, $s) => $q->where('status', $s))->orderBy('created_at')->orderBy('id')->get(), $f);
        $rows = [];
        $counted = [];
        foreach ($list as $r) {
            $m = self::money($r);
            if ($f['pay'] && $m['pay'] !== $f['pay']) {
                continue;
            }
            $row = array_merge([$r->code, $r->created_at->toDateString(), $r->customer?->name], self::whoCells($r, $f), [
                self::dates($r), RentalRequest::statusLabel($r->status), $m['gross'], $m['discounts'], $m['charges'], $m['collected'], $m['balance'], $m['pay'],
            ]);
            $rows[] = $row;
            if (self::isSale($r)) {
                $counted[] = $row;
            }
        }
        $cols = array_merge([['Rental', 'text'], ['Booked', 'date'], ['Customer', 'text']], array_values(self::who($f)), [
            ['Rental dates', 'text'], ['Status', 'text'], ['Gross', 'money'], ['Discounts', 'money'], ['Charges', 'money'], ['Collected', 'money'], ['Balance', 'money'], ['Payment', 'text'],
        ]);
        $tot = self::totalRow($cols, $counted, 'Total (confirmed)');
        $i = fn ($label) => array_search($label, array_column($cols, 0), true);

        return [
            'summary' => [
                ['Rentals booked', count($rows), 'int'], ['Confirmed', count($counted), 'int'],
                ['Gross', $tot[$i('Gross')], 'money'], ['Discounts', $tot[$i('Discounts')], 'money'], ['Charges', $tot[$i('Charges')], 'money'],
                ['Collected', $tot[$i('Collected')], 'money'], ['Still owed', $tot[$i('Balance')], 'money'],
            ],
            'tables' => [self::table('Rentals booked '.self::rangeWords($f), $cols, $rows, $counted ? $tot : null, 'Totals count confirmed rentals only: pending, declined and cancelled rentals are listed but are not sales.', 'No rentals were booked in this period.')],
            'notes' => ['Booked = the day the customer placed the rental. Collected = verified rent payments minus refunds (security deposits are not included).'],
        ];
    }

    /** Group dates for the period: by day (up to a month), by week (up to ~3 months) or by month. */
    private static function bucket(string $date, array $f): array
    {
        $days = diff_days($f['from'], $f['to']);
        $d = Carbon::parse($date);
        if ($days <= 31) {
            return [$date, fmt_date($date)];
        }
        if ($days <= 92) {
            $s = $d->copy()->startOfWeek()->toDateString();

            return [$s, 'Week of '.fmt_date($s)];
        }

        return [$d->format('Y-m'), $d->format('F Y')];
    }

    /** Verified, pending and rejected payments dated in the period, for rentals in scope. */
    private static function payments(array $f)
    {
        return Payment::whereBetween('date', [$f['from'], $f['to']])
            ->whereHas('rentalRequest', fn ($q) => $q->when($f['supplier'], fn ($x, $s) => $x->where('supplier_id', $s->id))->when($f['branch'], fn ($x, $b) => $x->where('branch_id', $b->id))->when($f['branchSet'], fn ($x, $ids) => $x->whereIn('branch_id', $ids)))
            ->with(['rentalRequest:id,code,customer_id,supplier_id,branch_id', 'rentalRequest.customer:id,name', 'rentalRequest.supplier:id,name', 'rentalRequest.branch:id,name']);
    }

    private static function rSales(array $f): array
    {
        // 1. money collected, by payment date
        $pays = self::payments($f)->where('status', 'verified')->get();
        if ($f['item']) {
            $ids = self::withItem(RentalRequest::whereIn('id', $pays->pluck('rental_request_id'))->get(['id', 'lines']), $f)->pluck('id')->all();
            $pays = $pays->whereIn('rental_request_id', $ids);
        }
        $byB = [];
        foreach ($pays->sortBy('date') as $p) {
            [$k, $label] = self::bucket($p->date, $f);
            $byB[$k] ??= [$label, 0.0, 0.0, 0.0, 0.0];
            if (in_array($p->type, Finance::RENTAL_TYPES, true)) {
                $byB[$k][1] += (float) $p->amount;
            } elseif ($p->type === 'refund') {
                $byB[$k][2] += (float) $p->amount;
            } elseif ($p->type === 'compensation') {
                $byB[$k][4] += (float) $p->amount;
            }
        }
        $cRows = array_values(array_map(fn ($b) => [$b[0], r2($b[1]), r2($b[2]), r2($b[1] - $b[2]), r2($b[4])], $byB));
        $cCols = [['Period', 'text'], ['Rent received', 'money'], ['Refunds', 'money'], ['Net collected', 'money'], ['Damage charges collected', 'money']];

        // 2. value of confirmed rentals held in the period (by rental start date)
        $held = self::withItem(self::rentals($f)->whereBetween('start_date', [$f['from'], $f['to']])->whereNotIn('status', self::NOT_SALE)->get(), $f);
        $branchRows = $held->groupBy(fn ($r) => $r->branch?->name ?? 'Not set')->map(function ($g, $name) {
            $m = $g->map(fn ($r) => self::money($r));

            return [$name, $g->count(), r2($m->sum('gross')), r2($m->sum('discounts')), r2($m->sum('charges'))];
        })->sortByDesc(fn ($r) => $r[4])->values()->all();
        $bCols = [['Branch', 'text'], ['Rentals', 'int'], ['Gross', 'money'], ['Discounts', 'money'], ['Charges', 'money']];

        $lineRows = self::lineTotals($held, $f);
        $lCols = [['Item / package', 'text'], ['Type', 'text'], ['Rentals', 'int'], ['Qty', 'int'], ['Value', 'money']];

        $tables = [
            self::table('Money collected', $cCols, $cRows, $cRows ? self::totalRow($cCols, $cRows) : null, 'By payment date. Refunds are rent given back to customers; security deposits are held money and not counted.'),
            self::table('Confirmed rentals held, by branch', $bCols, $branchRows, $branchRows ? self::totalRow($bCols, $branchRows) : null, 'By rental start date. Cancelled, declined and pending rentals are not sales.'),
        ];
        if (! $f['supplier']) {
            $sRows = $held->groupBy(fn ($r) => $r->supplier?->name)->map(function ($g, $name) {
                $commission = $g->sum(fn ($r) => Payouts::breakdown($r)['commission']);

                return [$name, $g->count(), r2($g->sum(fn ($r) => self::money($r)['charges'])), r2($g->sum(fn ($r) => self::money($r)['collected'])), r2($commission)];
            })->sortByDesc(fn ($r) => $r[2])->values()->all();
            $sCols = [['Supplier', 'text'], ['Rentals', 'int'], ['Charges', 'money'], ['Collected so far', 'money'], ['Platform commission', 'money']];
            $tables[] = self::table('By supplier', $sCols, $sRows, $sRows ? self::totalRow($sCols, $sRows) : null, 'Commission is on item rent actually collected for these rentals (delivery and service fees carry none).');
        }
        $tables[] = self::table('By item and package', $lCols, $lineRows, $lineRows ? self::totalRow($lCols, $lineRows) : null, 'Line values after item discounts, before whole-order and special discounts, delivery and services.');

        $net = r2(array_sum(array_column($cRows, 3)));

        return [
            'summary' => [
                ['Rent received', r2(array_sum(array_column($cRows, 1))), 'money'], ['Refunds', r2(array_sum(array_column($cRows, 2))), 'money'],
                ['Net collected', $net, 'money'], ['Damage charges collected', r2(array_sum(array_column($cRows, 4))), 'money'],
                ['Confirmed rentals held', $held->count(), 'int'], ['Their charges', r2(array_sum(array_column($branchRows, 4))), 'money'],
            ],
            'tables' => $tables,
        ];
    }

    /** Pricing lines of $rentals grouped by item / package: [name, type, rentals, qty, value]. */
    private static function lineTotals(Collection $rentals, array $f, bool $withEmpty = false): array
    {
        $agg = [];
        foreach ($rentals as $r) {
            foreach (($r->pricing['lines'] ?? []) as $l) {
                $type = ($l['type'] ?? 'item') === 'package' ? 'package' : 'item';
                $id = (int) ($l['refId'] ?? 0);
                if ($f['item'] && ($f['item']['kind'] !== $type || $f['item']['id'] !== $id)) {
                    continue;
                }
                if ($f['category'] && ($type === 'package' || inv()->product($id)?->category_id !== $f['category']->id)) {
                    continue;
                }
                $k = $type.':'.$id;
                $qty = array_sum(array_map(fn ($d) => (int) ($d['qty'] ?? 0), $l['days'] ?? [])) ?: array_sum(array_map(fn ($o) => (int) ($o['qty'] ?? 0), $l['once'] ?? []));
                $agg[$k] ??= [$l['name'] ?? 'Item', $type === 'package' ? 'Package' : (($l['kind'] ?? '') === 'sale' ? 'For sale' : 'Item'), [], 0, 0.0];
                $agg[$k][2][$r->id] = true;
                $agg[$k][3] += $qty;
                $agg[$k][4] += (float) ($l['total'] ?? 0);
            }
        }
        if ($withEmpty && $f['supplier']) {
            $prods = Product::where('supplier_id', $f['supplier']->id)->where('status', 'active')->where('package_only', false)
                ->when($f['category'], fn ($q, $c) => $q->where('category_id', $c->id))->get(['id', 'name', 'listing_type']);
            foreach ($prods as $p) {
                $agg['item:'.$p->id] ??= [$p->name, $p->isSale() ? 'For sale' : 'Item', [], 0, 0.0];
            }
            if (! $f['category']) {
                foreach (Package::where('supplier_id', $f['supplier']->id)->where('status', 'active')->get(['id', 'name']) as $p) {
                    $agg['package:'.$p->id] ??= [$p->name, 'Package', [], 0, 0.0];
                }
            }
        }
        $rows = array_map(fn ($a) => [$a[0], $a[1], count($a[2]), $a[3], r2($a[4])], array_values($agg));
        usort($rows, fn ($a, $b) => [$b[4], $b[3], $a[0]] <=> [$a[4], $a[3], $b[0]]);

        return $rows;
    }

    private static function rPayments(array $f): array
    {
        $list = self::payments($f)->when($f['pstatus'], fn ($q, $s) => $q->where('status', $s), fn ($q) => $q->where('status', '!=', 'awaiting'))
            ->when($f['method'], fn ($q, $m) => $q->whereIn('method', $m === 'cash' ? ['cash', 'cod'] : [$m]))->orderBy('date')->orderBy('id')->get();
        $rows = [];
        $sum = ['rent' => 0.0, 'damage' => 0.0, 'deposit' => 0.0, 'out' => 0.0, 'pending' => 0.0, 'rejected' => 0];
        foreach ($list as $p) {
            $r = $p->rentalRequest;
            $amt = (float) $p->amount * ($p->isOutflow() ? -1 : 1);
            $rows[] = array_merge([fmt_date($p->date), $p->receipt_no ?: $p->code, $r?->code, $r?->customer?->name], self::whoCells($r, $f), [
                $p->typeLabel(), Payment::METHOD_LABEL[$p->method] ?? ucfirst((string) $p->method), Payment::STATUS_LABEL[$p->status] ?? $p->status, $p->status === 'verified' ? $amt : 0.0, $p->status === 'verified' ? 0.0 : (float) $p->amount,
            ]);
            if ($p->status === 'verified') {
                match (true) {
                    $p->isOutflow() => $sum['out'] += (float) $p->amount,
                    $p->type === 'compensation' => $sum['damage'] += (float) $p->amount,
                    $p->type === 'deposit' => $sum['deposit'] += (float) $p->amount,
                    default => $sum['rent'] += (float) $p->amount,
                };
            } elseif ($p->status === 'pending') {
                $sum['pending'] += (float) $p->amount;
            } else {
                $sum['rejected']++;
            }
        }
        $cols = array_merge([['Date', 'text'], ['Receipt / ref', 'text'], ['Rental', 'text'], ['Customer', 'text']], array_values(self::who($f)), [
            ['Type', 'text'], ['Method', 'text'], ['Status', 'text'], ['Verified amount', 'money'], ['Not yet verified / rejected', 'money'],
        ]);

        return [
            'summary' => [
                ['Rent received', r2($sum['rent']), 'money'], ['Damage charges received', r2($sum['damage']), 'money'], ['Deposits received', r2($sum['deposit']), 'money'],
                ['Refunds & deposits returned', r2($sum['out']), 'money'], ['Net verified', r2($sum['rent'] + $sum['damage'] + $sum['deposit'] - $sum['out']), 'money'],
                ['Waiting for verification', r2($sum['pending']), 'money'], ['Rejected', $sum['rejected'], 'int'],
            ],
            'tables' => [self::table('Payments dated '.self::rangeWords($f), $cols, $rows, $rows ? self::totalRow($cols, $rows) : null, 'Money going back to customers (refunds, returned deposits) is shown as a minus. Only verified payments count as money received.', 'No payments in this period.')],
            'notes' => ['Deposits are held for the rental and returned or used for damage; they are not rental income.'],
        ];
    }

    private static function rOutstanding(array $f): array
    {
        $list = self::rentals($f)->whereNotIn('status', self::NOT_SALE)->orderBy('start_date')->get()
            ->map(fn ($r) => [$r, self::money($r)])->filter(fn ($x) => $x[1]['balance'] > 0 || $x[1]['damageDue'] > 0);
        $rows = $list->map(fn ($x) => array_merge([$x[0]->code, $x[0]->customer?->name], self::whoCells($x[0], $f), [
            self::dates($x[0]), RentalRequest::statusLabel($x[0]->status), $x[1]['charges'], $x[1]['collected'], $x[1]['balance'], $x[1]['damageDue'], $x[1]['overdue'] ? 'Overdue' : $x[1]['pay'],
        ]))->values()->all();
        $cols = array_merge([['Rental', 'text'], ['Customer', 'text']], array_values(self::who($f)), [
            ['Rental dates', 'text'], ['Status', 'text'], ['Charges', 'money'], ['Collected', 'money'], ['Rent still owed', 'money'], ['Damage still owed', 'money'], ['Payment', 'text'],
        ]);

        return [
            'summary' => [
                ['Rentals with a balance', count($rows), 'int'], ['Rent still owed', r2($list->sum(fn ($x) => $x[1]['balance'])), 'money'],
                ['Damage still owed', r2($list->sum(fn ($x) => $x[1]['damageDue'])), 'money'], ['Overdue', $list->filter(fn ($x) => $x[1]['overdue'])->count(), 'int'],
            ],
            'tables' => [self::table('Balances still owed', $cols, $rows, $rows ? self::totalRow($cols, $rows) : null, null, 'Nothing is owed right now.')],
        ];
    }

    private static function rDiscounts(array $f): array
    {
        $list = self::rentals($f)->whereBetween('created_at', [$f['from'].' 00:00:00', $f['to'].' 23:59:59'])->whereNotIn('status', self::NOT_SALE)->orderBy('created_at')->get();
        $rows = [];
        $by = [];
        foreach ($list as $r) {
            $p = $r->pricing ?? [];
            $m = self::money($r);
            foreach (($p['discounts'] ?? []) as $d) {
                $name = $d['name'] ?? $d['label'] ?? 'Shop discount';
                $rows[] = array_merge([$r->code, $r->created_at->toDateString(), $r->customer?->name], self::whoCells($r, $f), [$name, 'Shop promotion', (string) ($d['item'] ?? ''), (float) ($d['amount'] ?? 0)]);
                $by['p:'.$name] ??= [$name, 'Shop promotion', 0, 0.0];
                $by['p:'.$name][2]++;
                $by['p:'.$name][3] += (float) ($d['amount'] ?? 0);
            }
            if ($m['special'] > 0) {
                $name = $p['customDiscount']['name'] ?? 'Special discount';
                $rows[] = array_merge([$r->code, $r->created_at->toDateString(), $r->customer?->name], self::whoCells($r, $f), [$name, 'Special (this customer)', 'Whole rental', $m['special']]);
                $by['c:'.$name] ??= [$name, 'Special (customer)', 0, 0.0];
                $by['c:'.$name][2]++;
                $by['c:'.$name][3] += $m['special'];
            }
        }
        $cols = array_merge([['Rental', 'text'], ['Booked', 'date'], ['Customer', 'text']], array_values(self::who($f)), [['Discount', 'text'], ['Kind', 'text'], ['On', 'text'], ['Amount', 'money']]);
        $sRows = array_map(fn ($b) => [$b[0], $b[1], $b[2], r2($b[3])], array_values($by));
        usort($sRows, fn ($a, $b) => $b[3] <=> $a[3]);
        $sCols = [['Discount', 'text'], ['Kind', 'text'], ['Times used', 'int'], ['Total given', 'money']];

        return [
            'summary' => [
                ['Rentals with a discount', collect($rows)->pluck(0)->unique()->count(), 'int'],
                ['Promotions given', r2(collect($sRows)->where(1, 'Shop promotion')->sum(3)), 'money'],
                ['Special discounts given', r2(collect($sRows)->where(1, 'Special (customer)')->sum(3)), 'money'],
            ],
            'tables' => [
                self::table('By discount', $sCols, $sRows, $sRows ? self::totalRow($sCols, $sRows) : null),
                self::table('Discount on each rental', $cols, $rows, $rows ? self::totalRow($cols, $rows) : null, 'Confirmed rentals booked in the period. Cancelled and declined rentals are left out.', 'No discounts were given in this period.'),
            ],
        ];
    }

    private static function rCancellations(array $f): array
    {
        $list = self::rentals($f)->whereIn('status', ['cancelled', 'declined'])->with('events')->get()->map(function ($r) {
            $ev = $r->events->where('status', $r->status)->sortByDesc('id')->first();

            return [$r, ($ev?->created_at ?? $r->updated_at)->toDateString()];
        })->filter(fn ($x) => self::inRange($x[1], $f))->sortBy(fn ($x) => $x[1]);
        $rows = $list->map(function ($x) use ($f) {
            [$r, $on] = $x;
            $m = self::money($r);

            return array_merge([$r->code, $on, $r->customer?->name], self::whoCells($r, $f), [
                $r->status === 'declined' ? 'Declined by the shop' : 'Cancelled', (string) ($r->status === 'declined' ? $r->decline_reason : $r->cancel_reason),
                $m['charges'], $m['paid'], $m['refunded'], r2(max(0, $m['paid'] - $m['refunded'])),
            ]);
        })->values()->all();
        $cols = array_merge([['Rental', 'text'], ['Date', 'date'], ['Customer', 'text']], array_values(self::who($f)), [
            ['Outcome', 'text'], ['Reason', 'text'], ['Rental value', 'money'], ['Paid', 'money'], ['Refunded', 'money'], ['Kept (fees)', 'money'],
        ]);
        $c = collect($rows);

        return [
            'summary' => [
                ['Cancelled', $list->filter(fn ($x) => $x[0]->status === 'cancelled')->count(), 'int'], ['Declined', $list->filter(fn ($x) => $x[0]->status === 'declined')->count(), 'int'],
                ['Value lost', r2($c->sum(count($cols) - 4)), 'money'], ['Refunded', r2($c->sum(count($cols) - 2)), 'money'], ['Kept as fees', r2($c->sum(count($cols) - 1)), 'money'],
            ],
            'tables' => [self::table('Cancelled and declined '.self::rangeWords($f), $cols, $rows, $rows ? self::totalRow($cols, $rows) : null, 'Rental value is never counted as a sale. Kept = paid minus refunded (for example a cancellation fee).', 'No rentals were cancelled or declined in this period.')],
        ];
    }

    private static function rInventory(array $f): array
    {
        $prods = Product::query()->when($f['supplier'], fn ($q, $s) => $q->where('supplier_id', $s->id))->where('status', '!=', 'deleted')
            ->when($f['category'], fn ($q, $c) => $q->where('category_id', $c->id))->when($f['item'], fn ($q, $i) => $q->whereKey($i['id']))
            ->with(['category:id,name', 'supplier:id,name'])->orderBy('name')->get();
        inv()->primeProducts($prods);
        $locs = InventoryLocation::whereIn('supplier_id', $prods->pluck('supplier_id')->unique())->pluck('name', 'id');
        $today = today_iso();
        $rows = [];
        $low = 0;
        foreach ($prods as $p) {
            $by = inv()->stockByLocation($p->id);
            if ($f['branchSet']) {
                $by = array_intersect_key($by, array_flip($f['branchSet'])); // only the user's branches
            }
            $stock = $f['branch'] ? (int) ($by[$f['branch']->id] ?? 0) : ($f['branchSet'] ? (int) array_sum($by) : (int) $p->total_stock);
            $out = $p->isSale() ? 0 : inv()->reservedOn($p->id, $today);
            $usable = max(0, (int) $p->total_stock - (int) $p->damaged - (int) $p->missing);
            $isLow = $p->isSale() && $p->low_stock_at !== null && $usable <= (int) $p->low_stock_at;
            $low += $isLow ? 1 : 0;
            $split = collect($by)->filter()->map(fn ($q, $id) => ($locs[$id] ?? 'Branch').': '.$q)->implode(' · ');
            $rows[] = array_merge(! $f['supplier'] ? [$p->supplier?->name] : [], [
                $p->name, $p->category?->name, $p->isSale() ? 'For sale' : 'For rent', $stock, $f['branch'] ? null : $split,
                (int) $p->damaged, (int) $p->missing, (int) ($p->written_off ?? 0), $out, $usable, $isLow ? 'Low stock' : ($p->status === 'active' ? 'Listed' : ucfirst($p->status)),
            ]);
        }
        $cols = array_merge(! $f['supplier'] ? [['Supplier', 'text']] : [], [
            ['Item', 'text'], ['Category', 'text'], ['Type', 'text'], [$f['branch'] ? 'Stock at '.$f['branch']->name : ($f['branchSet'] ? 'Stock at your branches' : 'Total stock'), 'int'], ['By branch', 'text'],
            ['Damaged', 'int'], ['Missing', 'int'], ['Written off', 'int'], ['Rented out today', 'int'], ['Usable', 'int'], ['Status', 'text'],
        ]);
        if ($f['branch']) { // the "By branch" column is only for all branches
            $k = array_search('By branch', array_column($cols, 0), true);
            array_splice($cols, $k, 1);
            $rows = array_map(fn ($r) => array_values(array_filter($r, fn ($v, $i) => $i !== $k, ARRAY_FILTER_USE_BOTH)), $rows);
        }
        $c = collect($rows);
        $at = fn ($label) => array_search($label, array_column($cols, 0), true);

        return [
            'summary' => [
                ['Items', count($rows), 'int'], ['Units in stock', (int) $c->sum($at($cols[$at('Item') + 3][0])), 'int'], ['Damaged', (int) $c->sum($at('Damaged')), 'int'],
                ['Missing', (int) $c->sum($at('Missing')), 'int'], ['Rented out today', (int) $c->sum($at('Rented out today')), 'int'], ['Low-stock sale items', $low, 'int'],
            ],
            'tables' => [self::table('Stock today', $cols, $rows, $rows ? self::totalRow($cols, $rows) : null, $f['branch'] ? 'Damaged, missing, written off and usable are for the item across all branches (they are not tracked per branch).' : null, 'No items match these filters.')],
        ];
    }

    private static function rItems(array $f): array
    {
        $held = self::rentals($f)->whereBetween('start_date', [$f['from'], $f['to']])->whereNotIn('status', self::NOT_SALE)->get();
        $rows = self::lineTotals($held, $f, true);
        $cols = [['Item / package', 'text'], ['Type', 'text'], ['Rentals', 'int'], ['Qty', 'int'], ['Value', 'money']];
        $none = array_values(array_filter($rows, fn ($r) => $r[2] === 0));

        return [
            'summary' => [
                ['Confirmed rentals held', $held->count(), 'int'], ['Items & packages rented', count($rows) - count($none), 'int'],
                ['Not rented in this period', count($none), 'int'], ['Value', r2(array_sum(array_column($rows, 4))), 'money'],
            ],
            'tables' => [self::table('Rented in the period (by rental start date)', $cols, $rows, $rows ? self::totalRow($cols, $rows) : null,
                'Packages count as one line; the items inside a package are not counted again. Line values are after item discounts.', 'Nothing was rented in this period.')],
        ];
    }

    private static function rDamage(array $f): array
    {
        $list = self::rentals($f)->whereNotNull('inspection')->get()
            ->map(fn ($r) => [$r, $r->inspection['returnDate'] ?? substr((string) ($r->inspection['at'] ?? ''), 0, 10)])
            ->filter(fn ($x) => self::inRange($x[1], $f))->sortBy(fn ($x) => $x[1]);
        $rows = [];
        $per = [];
        foreach ($list as [$r, $on]) {
            $insp = $r->inspection;
            $hit = false;
            foreach (($insp['items'] ?? []) as $it) {
                $dmg = (int) ($it['repairable'] ?? 0) + (int) ($it['nonRepairable'] ?? 0) + (int) ($it['minor'] ?? 0);
                $lost = (int) ($it['lost'] ?? 0);
                if (! $dmg && ! $lost && ! (float) ($it['total'] ?? 0)) {
                    continue;
                }
                if ($f['item'] && (int) ($it['productId'] ?? 0) !== $f['item']['id']) {
                    continue;
                }
                $hit = true;
                $rows[] = array_merge([$on, $r->code, $r->customer?->name], self::whoCells($r, $f), [
                    inv()->product((int) ($it['productId'] ?? 0))?->name ?? 'Item', (int) ($it['rented'] ?? 0), $dmg, $lost, (string) ($it['description'] ?? ''), (float) ($it['total'] ?? 0),
                ]);
            }
            if ($hit) {
                $m = self::money($r);
                $review = $insp['review']['status'] ?? null;
                $per[] = array_merge([$r->code, $r->customer?->name], self::whoCells($r, $f), [
                    $review === 'pending' ? 'Waiting for the customer' : ($review === 'disputed' ? 'Disputed' : ($review ? ucfirst($review) : 'Agreed')),
                    $m['damage'], $m['damagePaid'], $m['damageDue'],
                ]);
            }
        }
        $cols = array_merge([['Returned', 'date'], ['Rental', 'text'], ['Customer', 'text']], array_values(self::who($f)), [
            ['Item', 'text'], ['Rented', 'int'], ['Damaged', 'int'], ['Missing', 'int'], ['What happened', 'text'], ['Charge', 'money'],
        ]);
        $pCols = array_merge([['Rental', 'text'], ['Customer', 'text']], array_values(self::who($f)), [['Customer review', 'text'], ['Charged', 'money'], ['Collected', 'money'], ['Still owed', 'money']]);
        $n = count($pCols);

        return [
            'summary' => [
                ['Rentals with damage', count($per), 'int'], ['Units damaged', (int) collect($rows)->sum(count($cols) - 4), 'int'], ['Units missing', (int) collect($rows)->sum(count($cols) - 3), 'int'],
                ['Charged', r2(collect($per)->sum($n - 3)), 'money'], ['Collected', r2(collect($per)->sum($n - 2)), 'money'], ['Still owed', r2(collect($per)->sum($n - 1)), 'money'],
            ],
            'tables' => [
                self::table('Damaged and missing items', $cols, $rows, $rows ? self::totalRow($cols, $rows) : null, 'Charges use the damage fees agreed when each rental was booked.', 'No damage or missing items were recorded in this period.'),
                self::table('Charges per rental', $pCols, $per, $per ? self::totalRow($pCols, $per) : null, 'Collected = paid directly plus any amount taken from the security deposit.'),
            ],
        ];
    }

    private static function rReturns(array $f): array
    {
        $today = today_iso();
        $out = ['ongoing', 'ready_pickup', 'out_delivery', 'in_progress', 'accepted'];
        $list = self::rentals($f)->whereBetween('end_date', [$f['from'], $f['to']])->whereNotIn('status', self::NOT_SALE)->orderBy('end_date')->get();
        $count = ['on' => 0, 'late' => 0, 'out' => 0, 'over' => 0];
        $rows = $list->map(function ($r) use ($f, $today, $out, &$count) {
            $back = $r->inspection['returnDate'] ?? ($r->status === 'completed' ? substr((string) $r->completed_at, 0, 10) : null);
            if ($back) {
                $late = max(0, diff_days($r->end_date, $back));
                $state = $late ? 'Returned late' : 'Returned on time';
                $count[$late ? 'late' : 'on']++;
            } elseif (in_array($r->status, $out, true) && $r->end_date < $today) {
                $late = diff_days($r->end_date, $today);
                $state = 'Overdue: not back yet';
                $count['over']++;
            } else {
                $late = 0;
                $state = in_array($r->status, ['return_pending', 'inspection'], true) ? 'Back, being checked' : 'Not due yet';
                $count['out']++;
            }

            return array_merge([$r->code, $r->customer?->name], self::whoCells($r, $f), [fmt_date($r->end_date), $back ? fmt_date($back) : '—', RentalRequest::statusLabel($r->status), $state, $late]);
        })->values()->all();
        $cols = array_merge([['Rental', 'text'], ['Customer', 'text']], array_values(self::who($f)), [['Due back', 'text'], ['Returned', 'text'], ['Status', 'text'], ['Return', 'text'], ['Days late', 'int']]);

        return [
            'summary' => [['Due back', count($rows), 'int'], ['On time', $count['on'], 'int'], ['Late', $count['late'], 'int'], ['Overdue now', $count['over'], 'int'], ['Not due yet / being checked', $count['out'], 'int']],
            'tables' => [self::table('Rentals due back '.self::rangeWords($f), $cols, $rows, null, null, 'No rentals were due back in this period.')],
        ];
    }

    private static function rStock(array $f): array
    {
        $list = StockMovement::whereBetween('created_at', [$f['from'].' 00:00:00', $f['to'].' 23:59:59'])
            ->whereHas('product', fn ($q) => $q->when($f['supplier'], fn ($x, $s) => $x->where('supplier_id', $s->id)))
            ->when($f['branch'], fn ($q, $b) => $q->where(fn ($w) => $w->where('inventory_location_id', $b->id)->orWhere('to_location_id', $b->id)))
            ->when($f['branchSet'], fn ($q, $ids) => $q->where(fn ($w) => $w->whereIn('inventory_location_id', $ids)->orWhereIn('to_location_id', $ids)))
            ->when($f['item'], fn ($q, $i) => $q->where('product_id', $i['id']))
            ->with(['product:id,name,supplier_id', 'product.supplier:id,name', 'user:id,name'])->orderBy('created_at')->orderBy('id')->get();
        $locs = InventoryLocation::whereIn('id', $list->pluck('inventory_location_id')->merge($list->pluck('to_location_id'))->filter()->unique())->pluck('name', 'id');
        $codes = RentalRequest::whereIn('id', $list->pluck('rental_request_id')->filter()->unique())->pluck('code', 'id');
        $rows = $list->map(fn ($m) => array_merge(! $f['supplier'] ? [$m->product?->supplier?->name] : [], [
            $m->created_at->format('Y-m-d H:i'), $m->product?->name, self::MOVE_LABEL[$m->type] ?? ucfirst((string) $m->type), (int) $m->qty,
            $locs[$m->inventory_location_id] ?? '—', $locs[$m->to_location_id] ?? '—', $codes[$m->rental_request_id] ?? '', $m->user?->name ?? 'System', (string) $m->note,
        ]))->values()->all();
        $cols = array_merge(! $f['supplier'] ? [['Supplier', 'text']] : [], [['When', 'text'], ['Item', 'text'], ['Change', 'text'], ['Qty', 'int'], ['Branch', 'text'], ['Moved to', 'text'], ['Rental', 'text'], ['By', 'text'], ['Note', 'text']]);

        return [
            'summary' => collect(self::MOVE_LABEL)->map(fn ($l, $k) => [$l, $list->where('type', $k)->count(), 'int'])->prepend(['Changes', $list->count(), 'int'])->values()->all(),
            'tables' => [self::table('Stock changes '.self::rangeWords($f), $cols, $rows, null, 'Qty is the number of units in the change (a minus means units taken out).', 'No stock changes in this period.')],
        ];
    }

    private static function rBranches(array $f): array
    {
        $locs = InventoryLocation::query()->when($f['supplier'], fn ($q, $s) => $q->where('supplier_id', $s->id))->when($f['branchSet'], fn ($q, $ids) => $q->whereIn('id', $ids))->with('supplier:id,name')->orderBy('supplier_id')->orderByDesc('is_main')->orderBy('name')->get();
        $rentals = self::rentals($f)->with('events')->get();
        $pays = self::payments($f)->where('status', 'verified')->get();
        $rows = [];
        $groups = $locs->map(fn ($l) => [$l->id, $l->name.($l->active ? '' : ' (closed)').($l->is_main ? ' · main' : ''), $l->supplier?->name])->all();
        if ($rentals->whereNull('branch_id')->isNotEmpty()) {
            $groups[] = [null, 'Not set', null];
        }
        foreach ($groups as [$id, $name, $sup]) {
            $mine = $rentals->where('branch_id', $id);
            $booked = $mine->filter(fn ($r) => self::isSale($r) && self::inRange($r->created_at->toDateString(), $f));
            $p = $pays->filter(fn ($x) => $x->rentalRequest?->branch_id === $id);
            $collected = $p->whereIn('type', Finance::RENTAL_TYPES)->sum('amount') - $p->where('type', 'refund')->sum('amount');
            $cancel = $mine->whereIn('status', ['cancelled', 'declined'])->filter(fn ($r) => self::inRange($r->updated_at->toDateString(), $f))->count();
            $damage = $mine->filter(fn ($r) => self::inRange($r->inspection['returnDate'] ?? null, $f))->sum(fn ($r) => (float) ($r->inspection['compensation'] ?? 0));
            $owed = $mine->filter(fn ($r) => self::isSale($r))->sum(fn ($r) => self::money($r)['balance']);
            $rows[] = array_merge(! $f['supplier'] ? [$sup] : [], [$name, $booked->count(), r2($booked->sum(fn ($r) => self::money($r)['charges'])), r2($collected), $cancel, r2($damage), r2($owed)]);
        }
        $cols = array_merge(! $f['supplier'] ? [['Supplier', 'text']] : [], [['Branch', 'text'], ['Rentals booked', 'int'], ['Their charges', 'money'], ['Collected', 'money'], ['Cancelled / declined', 'int'], ['Damage charged', 'money'], ['Still owed now', 'money']]);

        return [
            'summary' => [['Branches', $locs->count(), 'int'], ['Active', $locs->where('active', true)->count(), 'int']],
            'tables' => [self::table('Branches side by side', $cols, $rows, $rows ? self::totalRow($cols, $rows) : null,
                'A rental counts for the branch that fulfils it. Booked = placed in the period (confirmed only); collected = verified rent payments minus refunds dated in the period.', 'No branches yet.')],
        ];
    }

    private static function rCustomers(array $f): array
    {
        $list = self::rentals($f)->whereBetween('created_at', [$f['from'].' 00:00:00', $f['to'].' 23:59:59'])->get();
        $rows = $list->groupBy('customer_id')->map(function ($g) {
            $sale = $g->filter(fn ($r) => self::isSale($r));
            $m = $sale->map(fn ($r) => self::money($r));

            return [$g->first()->customer?->name ?? 'Customer', $g->count(), $sale->count(), $g->whereIn('status', ['cancelled', 'declined'])->count(), $g->where('status', 'completed')->count(),
                r2($m->sum('charges')), r2($m->sum('collected')), r2($m->sum('balance')), $g->max('created_at')->toDateString()];
        })->sortByDesc(fn ($r) => $r[5])->values()->all();
        $cols = [['Customer', 'text'], ['Rentals placed', 'int'], ['Confirmed', 'int'], ['Cancelled / declined', 'int'], ['Completed', 'int'], ['Charges', 'money'], ['Collected', 'money'], ['Still owed', 'money'], ['Last booking', 'date']];

        return [
            'summary' => [['Customers', count($rows), 'int'], ['Returning (2+ rentals)', collect($rows)->filter(fn ($r) => $r[1] > 1)->count(), 'int'], ['Charges', r2(collect($rows)->sum(5)), 'money']],
            'tables' => [self::table('Customers who booked '.self::rangeWords($f), $cols, $rows, $rows ? self::totalRow($cols, $rows) : null, 'Names only: contact details are not included in reports.', 'No customers booked in this period.')],
        ];
    }

    private static function rSuppliers(array $f): array
    {
        $sups = Supplier::approved()->withCount('locations')->orderBy('name')->get(['id', 'name', 'city', 'rating']);
        $rentals = RentalRequest::with('payments')->get()->groupBy('supplier_id');
        $pays = self::payments($f)->where('status', 'verified')->get()->groupBy(fn ($p) => $p->rentalRequest?->supplier_id);
        $rows = $sups->map(function ($s) use ($rentals, $pays, $f) {
            $mine = $rentals[$s->id] ?? collect();
            $booked = $mine->filter(fn ($r) => self::isSale($r) && self::inRange($r->created_at->toDateString(), $f));
            $p = $pays[$s->id] ?? collect();
            $collected = $p->whereIn('type', Finance::RENTAL_TYPES)->sum('amount') - $p->where('type', 'refund')->sum('amount');

            return [$s->name, $s->city, (int) $s->locations_count, $booked->count(), r2($booked->sum(fn ($r) => self::money($r)['charges'])), r2($collected),
                r2($booked->sum(fn ($r) => Payouts::breakdown($r)['commission'])),
                $mine->whereIn('status', ['cancelled', 'declined'])->filter(fn ($r) => self::inRange($r->updated_at->toDateString(), $f))->count(),
                r2($mine->filter(fn ($r) => self::inRange($r->inspection['returnDate'] ?? null, $f))->sum(fn ($r) => (float) ($r->inspection['compensation'] ?? 0))),
                $s->rating ? number_format((float) $s->rating, 1) : '—'];
        })->sortByDesc(fn ($r) => $r[4])->values()->all();
        $cols = [['Supplier', 'text'], ['City', 'text'], ['Branches', 'int'], ['Rentals booked', 'int'], ['Their charges', 'money'], ['Collected', 'money'], ['Commission', 'money'], ['Cancelled / declined', 'int'], ['Damage charged', 'money'], ['Rating', 'text']];

        return [
            'summary' => [['Active suppliers', count($rows), 'int'], ['Rentals booked', (int) collect($rows)->sum(3), 'int'], ['Collected', r2(collect($rows)->sum(5)), 'money'], ['Commission', r2(collect($rows)->sum(6)), 'money']],
            'tables' => [self::table('Suppliers side by side', $cols, $rows, $rows ? self::totalRow($cols, $rows) : null, 'Commission is on item rent collected for rentals booked in the period (delivery and service fees carry none).', 'No approved suppliers.')],
        ];
    }

    private static function rangeWords(array $f): string
    {
        return $f['from'] === $f['to'] ? 'on '.fmt_date($f['from']) : 'from '.fmt_date($f['from']).' to '.fmt_date($f['to']);
    }
}
