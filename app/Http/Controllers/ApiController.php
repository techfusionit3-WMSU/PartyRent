<?php

namespace App\Http\Controllers;

use App\Domain\Locations;
use App\Domain\Packages;
use App\Domain\Pricing;
use App\Domain\Rentals;
use App\Models\Supplier;
use App\Support\DeliveryInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only availability / pricing endpoints used by the interactive scheduler and
 * calendars. All numbers come from the same domain services the workflows use.
 */
class ApiController extends Controller
{
    /** Date-by-date check + pricing for one line, validated together with sibling lines of the same supplier. */
    public function schedule(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => 'required|in:item,package',
            'ref_id' => 'required|integer',
            'days' => 'array',
            'siblings' => 'array',
            'sel' => 'nullable|array',
        ]);
        $item = $data['type'] === 'package' ? inv()->package($data['ref_id']) : inv()->product($data['ref_id']);
        abort_unless($item, 404);

        $days = [];
        foreach ($data['days'] ?? [] as $d => $q) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $d)) {
                $days[$d] = max(0, (int) $q);
            }
        }
        ksort($days);
        $def = $data['type'] === 'package' ? Packages::def($item) : null;
        $sel = $def ? Packages::cleanSel($def, $data['sel'] ?? null) : null;
        $pp = $def ? Packages::price($def, $sel) : null;
        $self = ['id' => '__self', 'type' => $data['type'], 'ref_id' => (int) $data['ref_id'], 'days' => $days] + ($sel ? ['sel' => $sel] : []);
        $siblings = [];
        foreach ($data['siblings'] ?? [] as $i => $s) {
            if (isset($s['type'], $s['ref_id'], $s['days']) && is_array($s['days'])) {
                $siblings[] = ['id' => 's'.$i, 'type' => $s['type'] === 'package' ? 'package' : 'item', 'ref_id' => (int) $s['ref_id'], 'days' => Rentals::cleanDays($s['days'])] + (is_array($s['sel'] ?? null) ? ['sel' => $s['sel']] : []);
            }
        }
        $chk = inv()->check(array_merge($siblings, [$self]));
        $mine = $chk['byLine']['__self'] ?? [];
        $today = today_iso();
        $rows = [];
        $total = 0.0;
        foreach ($days as $d => $q) {
            $info = $mine[$d] ?? ['ok' => true, 'available' => 0];
            if ($data['type'] === 'package') {
                $unit = $pp['perDay'];
                $tier = null;
            } else {
                $unit = Pricing::unitPrice($item, $q);
                $t = count($item->tiers) > 1 ? Pricing::tierFor($item, $q) : null;
                $tier = $t && $t['min'] > 1 ? $t['min'].($t['max'] ? '–'.$t['max'] : '+') : null;
            }
            $sub = r2($unit * $q);
            $total += $sub;
            $rows[] = ['date' => $d, 'label' => fmt_day($d), 'qty' => $q, 'available' => $info['available'], 'ok' => $info['ok'] && $d >= $today, 'past' => $d < $today, 'unit' => $unit, 'unitText' => money($unit), 'tier' => $tier, 'subtotal' => $sub, 'subtotalText' => money($sub)];
        }
        $once = 0.0;
        if ($pp && $pp['once'] > 0 && $days && max($days) > 0) {
            $once = r2($pp['once'] * max($days)); // consumables + services: once, for the peak number of packages
            $total += $once;
        }
        if ($e = Rentals::leadTimeError($self)) {
            $lead = $e;
        }
        $okAll = $chk['ok'] && empty($lead) && ! array_filter(array_keys($days), fn ($d) => $d < $today) && array_sum($days) > 0;
        $bad = array_map(fn ($b) => ['date' => fmt_date($b['date'], false), 'name' => $b['name'], 'available' => $b['available']], $chk['bad']);

        return response()->json([
            'rows' => $rows, 'ok' => $okAll, 'total' => r2($total), 'totalText' => money($total),
            'unitWord' => $data['type'] === 'package' ? 'package' : $item->unitWord(),
            'minAvailable' => $data['type'] === 'item' && $days ? inv()->minAvailable($item->id, array_keys($days)) : null,
            'once' => $once, 'onceText' => money($once), 'lead' => $lead ?? null,
            'bad' => $bad, 'maxDays' => (int) setting('maxRentalDays', 30), 'today' => $today,
        ]);
    }

    /** Customer package configurator: price, contents and lead time for a selection (one package, one day). */
    public function packageQuote(Request $request): JsonResponse
    {
        $data = $request->validate(['package_id' => 'required|integer', 'sel' => 'nullable|array']);
        $k = inv()->package($data['package_id']);
        abort_unless($k && $k->active && ! $k->is_draft, 404);
        $def = Packages::def($k);
        $pp = Packages::price($def, $data['sel'] ?? null);
        $lead = Packages::leadDays($def, $pp['sel']);

        return response()->json([
            'sel' => $pp['sel'], 'package' => $pp['package'], 'packageText' => money($pp['package']),
            'perDay' => $pp['perDay'], 'perDayText' => money($pp['perDay']), 'once' => $pp['once'], 'onceText' => money($pp['once']),
            'savings' => $pp['savings'], 'savingsText' => money($pp['savings']), 'separateText' => money($pp['separate']),
            'summary' => Packages::describe($def, $pp['sel']),
            'leadDays' => $lead, 'earliest' => $lead ? add_days(today_iso(), $lead) : today_iso(),
        ]);
    }

    /** Region / province / city list for the cascading address selects. Static data, so cache it in the browser. */
    public function locations(): JsonResponse
    {
        return response()->json(Locations::forClient())->setPublic()->setMaxAge(86400);
    }


    /** Month availability for a product or package. */
    /** Hourly item: booked times on a date, and how many units are free for a chosen window. */
    public function slots(Request $request): JsonResponse
    {
        $data = $request->validate(['id' => 'required|integer', 'date' => 'required|date_format:Y-m-d', 'from' => 'nullable|string|max:5', 'to' => 'nullable|string|max:5']);
        $p = inv()->product($data['id']);
        abort_unless($p && $p->isHourly() && $p->status === 'active' && ! $p->package_only, 404);
        $w = \App\Domain\Hours::window(['from' => $data['from'] ?? null, 'to' => $data['to'] ?? null]);
        $busy = array_map(fn ($s) => $s + ['label' => \App\Domain\Hours::fmt($s['from']).' – '.\App\Domain\Hours::fmt($s['to'])], inv()->busySlots($p->id, $data['date']));

        return response()->json([
            'busy' => $busy, 'buffer' => (int) $p->buffer_min, 'minHours' => (float) $p->min_hours,
            'available' => $w ? inv()->availableAt($p->id, $data['date'], $w[0], $w[1]) : null,
        ]);
    }

    public function calendar(Request $request): JsonResponse
    {
        $data = $request->validate(['kind' => 'required|in:item,package', 'id' => 'required|integer', 'month' => ['required', 'regex:/^\d{4}-\d{2}$/'], 'need' => 'nullable|integer|min:1']);
        $first = $data['month'].'-01';
        $daysIn = (int) date('t', strtotime($first));
        $today = today_iso();
        $need = (int) ($data['need'] ?? 1);
        $out = [];
        for ($i = 1; $i <= $daysIn; $i++) {
            $d = $data['month'].'-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            $a = $data['kind'] === 'package' ? inv()->packageAvailableOn((int) $data['id'], $d) : inv()->availableOn((int) $data['id'], $d);
            $out[] = ['date' => $d, 'n' => $i, 'available' => $a, 'cls' => $d < $today ? 'past' : ($a <= 0 ? 'none' : ($a < $need ? 'low' : 'ok'))];
        }

        return response()->json([
            'month' => $data['month'], 'title' => date('F Y', strtotime($first)), 'startPad' => (int) date('w', strtotime($first)),
            'prev' => date('Y-m', strtotime($first.' -1 month')), 'next' => date('Y-m', strtotime($first.' +1 month')),
            'canPrev' => date('Y-m', strtotime($first.' -1 month')) >= substr($today, 0, 7), 'today' => $today, 'days' => $out, 'need' => $need,
        ]);
    }
}
