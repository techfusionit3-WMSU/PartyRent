<?php

namespace App\Domain;

use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Business (organization) suppliers: photos (logo, cover, portfolio of past events) and "what your business
 * offers" (events catered, services, event size, packages-from price). Shared by the Start Selling application
 * and Shop settings. Solo suppliers do not use this.
 */
class BusinessProfile
{
    public static function rules(): array
    {
        return [
            'events_catered' => 'required|array|min:1|max:15', 'events_catered.*' => 'string|max:40',
            'offers' => 'required|array|min:1|max:15', 'offers.*' => 'string|max:60',
            'event_size' => 'required|in:'.implode(',', array_keys(Supplier::EVENT_SIZES)),
            'packages_from' => 'nullable|numeric|min:0|max:100000000',
        ];
    }

    public static function fileRules(): array
    {
        return [
            'logo_file' => 'nullable|image|max:5120', 'cover_file' => 'nullable|image|max:8192',
            'portfolio' => 'nullable|array|max:'.Supplier::PORTFOLIO_MAX, 'portfolio.*' => 'image|max:8192',
            'portfolio_keep' => 'nullable|array', 'portfolio_keep.*' => 'string|max:255',
            'remove_logo' => 'nullable|boolean', 'remove_cover' => 'nullable|boolean',
        ];
    }

    public static function messages(): array
    {
        return [
            'events_catered.required' => 'Add at least one type of event you cater.',
            'offers.required' => 'Add at least one service you offer.',
            'event_size.required' => 'Choose the event size you can handle.',
            'portfolio.*.image' => 'Portfolio files must be photos (JPG, PNG or WebP).',
            'portfolio.*.max' => 'Each portfolio photo must be 8 MB or smaller.',
            'logo_file.image' => 'The logo must be a photo.', 'cover_file.image' => 'The cover must be a photo.',
        ];
    }

    /** Typed tags: suggestions keep their spelling; others are title-cased when typed in lowercase. */
    public static function tags(array $typed, array $known, int $max = 15): array
    {
        $out = [];
        foreach ($typed as $t) {
            $t = trim(preg_replace('/\s+/', ' ', (string) $t));
            if ($t === '') {
                continue;
            }
            $hit = collect($known)->first(fn ($s) => mb_strtolower($s) === mb_strtolower($t));
            $t = $hit ?? (mb_strtolower($t) === $t ? mb_convert_case($t, MB_CASE_TITLE) : $t);
            $out[mb_strtolower($t)] = $t;
        }

        return array_values(array_slice($out, 0, $max));
    }

    public static function profile(array $v): array
    {
        return [
            'events' => self::tags($v['events_catered'] ?? [], array_keys(Ai::EVENTS)),
            'offers' => self::tags($v['offers'] ?? [], Supplier::OFFER_SUGGESTIONS),
            'eventSize' => $v['event_size'],
            'packagesFrom' => isset($v['packages_from']) && $v['packages_from'] !== '' ? round((float) $v['packages_from'], 2) : null,
        ];
    }

    /** Store new uploads on the public disk. @return array{logo: ?string, cover: ?string, portfolio: string[]} */
    public static function storeUploads(Request $request, int $userId): array
    {
        $request->validate(self::fileRules(), self::messages());
        $dir = 'suppliers/'.$userId;

        return [
            'logo' => $request->hasFile('logo_file') ? $request->file('logo_file')->store($dir, 'public') : null,
            'cover' => $request->hasFile('cover_file') ? $request->file('cover_file')->store($dir, 'public') : null,
            'portfolio' => array_map(fn ($f) => $f->store($dir, 'public'), (array) $request->file('portfolio', [])),
        ];
    }

    /**
     * Photos after this save: $had = photos the form showed (saved + uploaded earlier in this application);
     * kept ones are those still ticked, then the new uploads. Throws when the portfolio is too small/large.
     *
     * @param  array{logo: ?string, cover: ?string, portfolio: string[]}  $had
     * @param  array{logo: ?string, cover: ?string, portfolio: string[]}  $new
     */
    public static function photos(Request $request, array $had, array $new): array
    {
        $keep = array_values(array_intersect($had['portfolio'], (array) $request->input('portfolio_keep', [])));
        $portfolio = array_values(array_unique(array_merge($keep, $new['portfolio'])));
        if (count($portfolio) < Supplier::PORTFOLIO_MIN) {
            throw ValidationException::withMessages(['portfolio' => 'Add at least '.Supplier::PORTFOLIO_MIN.' photos of events you have done (you have '.count($portfolio).').']);
        }
        if (count($portfolio) > Supplier::PORTFOLIO_MAX) {
            throw ValidationException::withMessages(['portfolio' => 'Keep the portfolio to '.Supplier::PORTFOLIO_MAX.' photos. Untick some to make room.']);
        }

        return [
            'logo_path' => $new['logo'] ?? ($request->boolean('remove_logo') ? null : $had['logo']),
            'cover_path' => $new['cover'] ?? ($request->boolean('remove_cover') ? null : $had['cover']),
            'portfolio' => $portfolio,
        ];
    }
}
