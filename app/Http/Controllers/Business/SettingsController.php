<?php

namespace App\Http\Controllers\Business;

use App\Domain\BusinessProfile;
use App\Domain\Notifier;
use Illuminate\Http\Request;

/** Business settings: where they work, how long setup takes, how many events a day, the payment schedule and policies. */
class SettingsController extends BusinessController
{
    public function edit(Request $request)
    {
        $s = $this->biz($request);

        return view('business.settings', ['s' => $s, 'set' => $s->bizSettings(), 'pol' => (array) ($s->policy ?? [])]);
    }

    public function update(Request $request)
    {
        $s = $this->biz($request);
        $v = $request->validate([
            'serviceAreas' => 'required|array|min:1|max:40', 'serviceAreas.*' => 'string|max:80',
            'travelNote' => 'nullable|string|max:300',
            'setupHours' => 'required|numeric|min:0|max:48', 'teardownHours' => 'required|numeric|min:0|max:48',
            'maxEventsPerDay' => 'required|integer|min:1|max:50', 'minNoticeDays' => 'required|integer|min:0|max:365',
            'reservePct' => 'required|numeric|min:0|max:100', 'depositPct' => 'required|numeric|min:0|max:100',
            'depositDays' => 'required|integer|min:0|max:365', 'balanceDays' => 'required|integer|min:0|max:365|lte:depositDays',
            'reserveHours' => 'required|integer|min:12|max:168', 'quoteDays' => 'required|integer|min:1|max:30',
            'cancellation' => 'required|string|min:10|max:1500', 'rescheduling' => 'required|string|min:10|max:1500',
        ], [
            'serviceAreas.required' => 'Add at least one city or area you serve.',
            'balanceDays.lte' => 'The balance is due closer to the event than the deposit, so its days must be fewer or equal.',
        ]);
        if ($v['reservePct'] + $v['depositPct'] > 100) {
            return back()->withInput()->withErrors(['depositPct' => 'The reservation fee and deposit together cannot be more than 100%.']);
        }
        $settings = [
            'serviceAreas' => BusinessProfile::tags($v['serviceAreas'], [], 40), 'travelNote' => trim((string) ($v['travelNote'] ?? '')),
            'setupHours' => (float) $v['setupHours'], 'teardownHours' => (float) $v['teardownHours'],
            'maxEventsPerDay' => (int) $v['maxEventsPerDay'], 'minNoticeDays' => (int) $v['minNoticeDays'],
            'reservePct' => (float) $v['reservePct'], 'depositPct' => (float) $v['depositPct'],
            'depositDays' => (int) $v['depositDays'], 'balanceDays' => (int) $v['balanceDays'],
            'reserveHours' => (int) $v['reserveHours'], 'quoteDays' => (int) $v['quoteDays'],
        ];
        $s->update([
            'business_profile' => array_replace((array) ($s->business_profile ?? []), ['settings' => $settings]),
            'policy' => array_replace((array) ($s->policy ?? []), ['cancellation' => trim($v['cancellation']), 'rescheduling' => trim($v['rescheduling'])]),
        ]);
        Notifier::audit('Updated business settings', $s->name, $request->user());

        return back()->with('success', 'Business settings saved.');
    }
}
