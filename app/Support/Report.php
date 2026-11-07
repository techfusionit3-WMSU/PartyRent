<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Report helpers: the shared date-range picker and CSV downloads. */
class Report
{
    /** @return array{range:string, from:string, to:string} */
    public static function range(Request $r): array
    {
        $T = today_iso();
        $range = in_array($r->query('range'), ['month', '3m', 'year', 'custom'], true) ? $r->query('range') : 'month';
        [$from, $to] = match ($range) {
            '3m' => [Carbon::parse($T)->startOfMonth()->subMonths(2)->toDateString(), $T],
            'year' => [substr($T, 0, 4).'-01-01', $T],
            'custom' => [self::date($r->query('from')) ?? substr($T, 0, 7).'-01', self::date($r->query('to')) ?? $T],
            default => [substr($T, 0, 7).'-01', $T],
        };
        if ($to < $from) {
            $to = $from;
        }

        return ['range' => $range, 'from' => $from, 'to' => $to];
    }

    private static function date(?string $d): ?string
    {
        return $d && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : null;
    }

    /** Last N months as [['key' => 'Y-m', 'label' => 'Mon']] ending this month. */
    public static function months(int $n = 6): array
    {
        $out = [];
        for ($i = $n - 1; $i >= 0; $i--) {
            $d = Carbon::parse(today_iso())->startOfMonth()->subMonths($i);
            $out[] = ['key' => $d->format('Y-m'), 'label' => $d->format('M')];
        }

        return $out;
    }

    /** Stream a CSV download. $rows[0] is the header row. Cells are guarded against spreadsheet formula injection. */
    public static function csv(string $filename, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows ₱ and ñ correctly
            foreach ($rows as $row) {
                fputcsv($out, array_map(fn ($c) => is_string($c) && preg_match('/^[=+\-@\t\r]/', $c) ? "'".$c : $c, $row));
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
