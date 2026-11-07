<?php

namespace App\Http\Controllers;

use App\Domain\Pricing;
use App\Domain\Rentals;
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
        $self = ['id' => '__self', 'type' => $data['type'], 'ref_id' => (int) $data['ref_id'], 'days' => $days];
        $siblings = [];
        foreach ($data['siblings'] ?? [] as $i => $s) {
            if (isset($s['type'], $s['ref_id'], $s['days']) && is_array($s['days'])) {
                $siblings[] = ['id' => 's'.$i, 'type' => $s['type'] === 'package' ? 'package' : 'item', 'ref_id' => (int) $s['ref_id'], 'days' => Rentals::cleanDays($s['days'])];
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
                $unit = (float) $item->price;
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
        $okAll = $chk['ok'] && ! array_filter(array_keys($days), fn ($d) => $d < $today) && array_sum($days) > 0;
        $bad = array_map(fn ($b) => ['date' => fmt_date($b['date'], false), 'name' => inv()->product($b['product_id'])?->name, 'available' => $b['available']], $chk['bad']);

        return response()->json([
            'rows' => $rows, 'ok' => $okAll, 'total' => r2($total), 'totalText' => money($total),
            'unitWord' => $data['type'] === 'package' ? 'package' : $item->unitWord(),
            'minAvailable' => $data['type'] === 'item' && $days ? inv()->minAvailable($item->id, array_keys($days)) : null,
            'bad' => $bad, 'maxDays' => (int) setting('maxRentalDays', 30), 'today' => $today,
        ]);
    }

    /** Month availability for a product or package. */
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
