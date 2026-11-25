<?php

namespace App\Http\Controllers;

use App\Domain\ReportCenter;
use App\Support\Report;
use Illuminate\Http\Request;

/**
 * Printable reports: view (with totals), print (clean A4 page; "Save as PDF" from the print dialog) and CSV download.
 * Supplier routes are locked to the user's own shop (team members need reports.view, checked by the route map);
 * admin routes need the reports.view permission. Customers and other roles never reach these routes.
 */
class ReportCenterController extends Controller
{
    /** [shop or null for admin, route prefix] */
    private function scope(Request $request): array
    {
        $u = $request->user();
        $route = (string) $request->route()->getName();
        if (str_starts_with($route, 'supplier.')) {
            $shop = $u->shop();
            abort_unless($shop, 403);

            return [$shop, 'supplier.reports.'];
        }
        abort_unless($u->hasPerm('reports.view'), 403, 'You do not have permission for this page.');
        if (str_starts_with($route, 'cashier.')) {
            // a shop's cashier stays on that shop; PartyRent's cashier sees the platform
            $sid = $u->shop()?->id ?? $u->staffOf?->supplier_id;

            return [$sid ? \App\Models\Supplier::findOrFail($sid) : null, 'cashier.reports.'];
        }

        return [null, 'admin.reports.'];
    }

    private function prepare(Request $request): array
    {
        [$shop, $prefix] = $this->scope($request);
        $f = ReportCenter::filters($request, $shop);

        return [$f, ReportCenter::build($f), $prefix];
    }

    private function header(array $f): array
    {
        $s = $f['supplier'];

        return [
            'name' => $f['admin'] ? config('app.name', 'PartyRent') : $s->name,
            'lines' => array_values(array_filter($f['admin']
                ? [$s ? 'Supplier: '.$s->name.($s->city ? ' · '.$s->city : '') : 'Platform report: all suppliers']
                : [trim(($s->address ?: '').($s->city ? ', '.$s->city : ''), ', '), implode(' · ', array_filter([$s->phone, $s->email]))])),
            'branch' => $f['branch'] ? $f['branch']->name.($f['branch']->address ? ' — '.$f['branch']->address : '') : null,
            'scopeNote' => ! $f['admin'] && ! $f['branch'] && ! $f['branchSet'] && $f['supplier']->branches()->count() > 1 ? 'Whole organization: all branches' : null,
            'period' => ReportCenter::periodText($f),
            'filters' => ReportCenter::applied($f),
            'generated' => now(),
            'by' => auth()->user()->name,
        ];
    }

    public function show(Request $request)
    {
        [$f, $report, $prefix] = $this->prepare($request);

        return view('reports.show', ['f' => $f, 'report' => $report, 'prefix' => $prefix, 'head' => $this->header($f),
            'options' => ReportCenter::options($f), 'types' => ReportCenter::types($f['admin']), 'query' => ReportCenter::query($f)]);
    }

    public function print(Request $request)
    {
        [$f, $report, $prefix] = $this->prepare($request);

        return view('reports.print', ['f' => $f, 'report' => $report, 'prefix' => $prefix, 'head' => $this->header($f),
            'query' => ReportCenter::query($f), 'pdf' => $request->boolean('pdf')]);
    }

    public function export(Request $request)
    {
        [$f, $report] = $this->prepare($request);
        $h = $this->header($f);
        $rows = [[$h['name']], [$report['title']], ['Period', $h['period']]];
        foreach ($h['filters'] as $k => $v) {
            $rows[] = [$k, $v];
        }
        $rows[] = ['Generated', $h['generated']->format('Y-m-d H:i'), 'by '.$h['by']];
        $rows[] = [];
        if ($report['summary']) {
            $rows[] = ['Summary'];
            foreach ($report['summary'] as [$label, $value]) {
                $rows[] = [$label, $value];
            }
            $rows[] = [];
        }
        foreach ($report['tables'] as $t) {
            $rows[] = [$t['title']];
            $rows[] = array_column($t['cols'], 0);
            foreach ($t['rows'] as $r) {
                $rows[] = $r;
            }
            if ($t['total']) {
                $rows[] = $t['total'];
            }
            if (! $t['rows']) {
                $rows[] = [$t['empty']];
            }
            if ($t['note']) {
                $rows[] = [$t['note']];
            }
            $rows[] = [];
        }
        $who = $f['admin'] ? 'platform'.($f['supplier'] ? '-'.str($f['supplier']->name)->slug() : '') : str($f['supplier']->name)->slug();

        return Report::csv('partyrent-'.$who.'-'.$f['type'].'-'.$f['from'].'-to-'.$f['to'].'.csv', $rows);
    }
}
