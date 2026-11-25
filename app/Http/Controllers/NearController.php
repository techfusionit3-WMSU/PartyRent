<?php

namespace App\Http\Controllers;

use App\Support\BranchScope;
use App\Support\Near;
use Illuminate\Http\Request;

/** "Renting near" for customers, and the branch switcher for shop users. */
class NearController extends Controller
{
    public function show(Request $request)
    {
        return view('market.near', ['near' => Near::get(), 'back' => $this->back($request)]);
    }

    public function store(Request $request)
    {
        $v = $request->validate([
            'near' => 'nullable|array', 'near.cityCode' => 'nullable|string|max:20',
            'lat' => 'nullable|numeric|between:-90,90|required_without:near.cityCode', 'lng' => 'nullable|numeric|between:-180,180|required_with:lat',
        ], ['lat.required_without' => 'Choose your city or municipality, or use your current location.']);
        $city = $v['near']['cityCode'] ?? null;
        if (! $city && isset($v['lat'], $v['lng'])) {
            $city = \App\Domain\Locations::nearestCity([(float) $v['lat'], (float) $v['lng']])['code'] ?? null; // a pin: its city
        }
        $set = $city ? Near::set($city, isset($v['lat']) ? (float) $v['lat'] : null, isset($v['lng']) ? (float) $v['lng'] : null) : null;
        if (! $set) {
            return back()->withErrors(['near.cityCode' => 'Choose a city or municipality from the list.'])->withInput();
        }
        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'near' => $set]);
        }

        return redirect()->to($this->back($request))->with('success', 'Showing what you can rent near '.$set['label'].'.');
    }

    public function clear(Request $request)
    {
        Near::clear();

        return redirect()->to($this->back($request));
    }

    /** Switch the dashboard to one branch, or all (only branches the user may see; locked for one-branch staff). */
    public function branchView(Request $request)
    {
        $u = $request->user();
        abort_unless($u->shop(), 403);
        $id = $request->filled('branch') ? (int) $request->input('branch') : null;
        if (! BranchScope::set($u, $id)) {
            return back()->with('error', 'You can only view your own branch.');
        }
        $c = BranchScope::current($u);

        return back()->with('success', $c ? 'Showing '.$c->name.' only.' : 'Showing all '.(BranchScope::isOrgLevel($u) ? 'branches (whole organization).' : 'your branches.'));
    }

    /** Back to the page the customer came from (same site only). */
    private function back(Request $request): string
    {
        $to = (string) $request->input('back', $request->query('back', url()->previous()));
        $host = parse_url($to, PHP_URL_HOST);

        return $to !== '' && (! $host || $host === $request->getHost()) && ! str_contains($to, '/near') ? $to : route('home');
    }
}
