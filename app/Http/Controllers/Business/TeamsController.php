<?php

namespace App\Http\Controllers\Business;

use App\Domain\EventTeams;
use App\Domain\Notifier;
use App\Models\EventBooking;
use App\Models\EventTeam;
use Illuminate\Http\Request;

/** The business's teams (crews): who is in each, what each works next; assign a team to a part of an event. */
class TeamsController extends BusinessController
{
    /** People who can be put in a team: the owner and active team members. */
    private function people($s)
    {
        return collect([$s->owner])->concat($s->staff()->where('status', 'active')->with('user')->get()->pluck('user'))->filter()->unique('id')->values();
    }

    public function index(Request $request)
    {
        $s = $this->biz($request);

        return view('business.teams', [
            's' => $s, 'teams' => EventTeam::where('supplier_id', $s->id)->orderByDesc('active')->orderBy('id')->get(),
            'people' => $this->people($s), 'work' => EventTeams::schedule($s),
        ]);
    }

    public function save(Request $request, ?EventTeam $eventTeam = null)
    {
        $s = $this->biz($request);
        abort_if($eventTeam && $eventTeam->supplier_id !== $s->id, 404);
        $v = $request->validate(['name' => 'required|string|min:2|max:60', 'members' => 'nullable|array', 'members.*' => 'integer', 'active' => 'nullable|boolean']);
        $ids = array_values(array_intersect(array_map('intval', $v['members'] ?? []), $this->people($s)->pluck('id')->all()));
        $data = ['name' => trim($v['name']), 'members' => $ids];
        if ($eventTeam) {
            $active = $request->boolean('active', true);
            if (! $active && $eventTeam->active && $eventTeam->parts()->whereDate('date', '>=', today())->whereHas('booking', fn ($q) => $q->whereIn('status', ['booked', 'confirmed']))->exists()) {
                return back()->with('error', $eventTeam->name.' still has upcoming events. Move them to another team first (on each booking).');
            }
            $eventTeam->update($data + ['active' => $active]);
        } else {
            $eventTeam = EventTeam::create($data + ['supplier_id' => $s->id, 'active' => true]);
        }
        Notifier::audit('Saved team', $s->name.' · '.$eventTeam->name, $request->user());

        return back()->with('success', $eventTeam->name.' saved.'.(EventTeam::where('supplier_id', $s->id)->where('active', true)->count() === 1 && ! $request->route('eventTeam') ? ' From now on, how many events you can take at the same time comes from your teams.' : ''));
    }

    public function assign(Request $request, EventBooking $eventBooking, int $part)
    {
        $b = $this->own($request, $eventBooking);
        $p = $b->parts()->findOrFail($part);
        $v = $request->validate(['team' => 'nullable|integer']);

        return $this->done(EventTeams::setTeam($p, $v['team'] ?? null, $request->user()), $p->name.': team updated.');
    }
}
