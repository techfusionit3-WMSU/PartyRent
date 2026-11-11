<?php

namespace App\Domain;

/**
 * Typical weight and packed size of common party rental items, so suppliers don't have to measure
 * before listing. The product form suggests one from the item name (keywords) or, failing that, the
 * category, and fills the empty weight / L / W / H fields. Rough averages only: the supplier can edit them.
 *
 * Each preset: label, keywords (English/Filipino, matched as whole words in the name), category,
 * kg per unit, and packed length × width × height in cm.
 */
class ItemSizes
{
    public const PRESETS = [
        // chairs
        ['Monoblock chair', ['monoblock', 'plastic chair', 'upuan', 'silya'], 'chairs', 2.5, 45, 50, 80],
        ['Tiffany / Chiavari chair', ['tiffany', 'chiavari'], 'chairs', 4, 40, 45, 92],
        ['Ghost / acrylic chair', ['ghost chair', 'acrylic chair', 'ghost'], 'chairs', 5, 45, 50, 90],
        ['Folding chair', ['folding chair', 'metal chair', 'foldable chair'], 'chairs', 4.5, 45, 8, 85],
        ['Kiddie chair', ['kiddie chair', 'kids chair', 'kid chair', 'children chair'], 'chairs', 1.2, 35, 35, 55],
        ['Chair cover / sash', ['chair cover', 'sash', 'chair sash'], 'tableware', 0.2, 30, 20, 3],
        // tables
        ['Round table (5 ft)', ['round table', 'banquet table', 'bilog'], 'tables', 20, 152, 152, 10],
        ['Long / rectangular table (6 ft)', ['long table', 'rectangular table', 'rectangle table', 'buffet table', 'folding table', 'trestle'], 'tables', 15, 183, 76, 8],
        ['Cocktail / high table', ['cocktail table', 'high table', 'bar table', 'cocktail'], 'tables', 8, 60, 60, 110],
        ['Kiddie table', ['kiddie table', 'kids table', 'kid table'], 'tables', 6, 90, 60, 50],
        // tents
        ['Canopy tent 3 × 3 m', ['canopy', '3x3', '10x10', 'pop up tent', 'gazebo'], 'tents', 25, 160, 25, 25],
        ['Big tent 5 × 10 m', ['5x10', 'big tent', 'party tent', 'wedding tent', 'tolda', 'trapal'], 'tents', 60, 200, 50, 50],
        // sound
        ['Speaker', ['speaker', 'speakers', 'subwoofer'], 'sound', 15, 40, 40, 65],
        ['Sound system set', ['sound system', 'sound set', 'audio set', 'pa system'], 'sound', 45, 70, 60, 80],
        ['Microphone', ['mic', 'microphone', 'wireless mic'], 'sound', 0.5, 25, 15, 8],
        ['Videoke / karaoke', ['videoke', 'karaoke'], 'sound', 15, 60, 45, 40],
        ['Mixer', ['mixer', 'console'], 'sound', 4, 45, 35, 12],
        // lighting
        ['Fairy / string lights', ['fairy', 'string light', 'fairy lights', 'christmas light', 'ilaw'], 'lighting', 0.5, 25, 20, 10],
        ['Uplight / PAR light', ['uplight', 'par light', 'par can', 'led par'], 'lighting', 2, 25, 25, 25],
        ['Moving head light', ['moving head', 'beam light'], 'lighting', 15, 50, 40, 60],
        ['Chandelier', ['chandelier'], 'lighting', 8, 60, 60, 60],
        // decorations
        ['Balloon set', ['balloon', 'balloons', 'lobo'], 'decorations', 0.5, 30, 20, 10],
        ['Centerpiece', ['centerpiece', 'centrepiece', 'flower vase', 'vase'], 'decorations', 1.5, 30, 30, 40],
        ['Flower stand', ['flower stand', 'pedestal', 'aisle stand'], 'decorations', 3, 40, 40, 120],
        ['Garland / banner', ['garland', 'banner', 'bunting'], 'decorations', 0.5, 30, 20, 10],
        // backdrops
        ['Backdrop frame', ['backdrop', 'photo wall', 'panel'], 'backdrops', 15, 200, 30, 30],
        ['Arch', ['arch', 'arko'], 'backdrops', 10, 220, 30, 30],
        ['Stage platform', ['stage', 'platform', 'riser'], 'backdrops', 35, 244, 122, 15],
        // tableware
        ['Tablecloth / linen', ['tablecloth', 'table cloth', 'linen', 'table cover', 'runner', 'napkin'], 'tableware', 0.5, 30, 25, 4],
        ['Plate', ['plate', 'plates', 'pinggan', 'charger'], 'tableware', 0.5, 27, 27, 3],
        ['Glass', ['glass', 'glasses', 'goblet', 'baso'], 'tableware', 0.3, 10, 10, 15],
        ['Cutlery', ['cutlery', 'utensils', 'spoon', 'fork', 'kubyertos'], 'tableware', 0.1, 22, 3, 2],
        // catering
        ['Chafing dish', ['chafing', 'food warmer', 'buffet warmer'], 'catering', 5, 65, 40, 30],
        ['Drink dispenser', ['dispenser', 'drink station', 'juice jar'], 'catering', 3, 30, 30, 50],
        ['Popcorn / cotton candy machine', ['popcorn', 'cotton candy'], 'catering', 20, 50, 40, 70],
        // games
        ['Bubble machine', ['bubble'], 'games', 3, 30, 25, 25],
        ['Inflatable / bounce house', ['inflatable', 'bounce', 'bouncy', 'slide'], 'games', 40, 100, 60, 60],
        ['Giant game (Jenga, Connect 4)', ['jenga', 'connect 4', 'giant game'], 'games', 15, 50, 30, 30],
        // furniture & other
        ['Sofa / lounge set', ['sofa', 'lounge', 'couch', 'loveseat'], 'furniture', 35, 180, 80, 80],
        ['Photo booth', ['photo booth', 'photobooth'], 'other', 30, 80, 60, 120],
        ['Props set', ['props'], 'other', 1, 40, 30, 10],
    ];

    /** Used when nothing in the name matches: a typical item of that category. */
    public const CATEGORY_DEFAULT = [
        'chairs' => 'Monoblock chair', 'tables' => 'Long / rectangular table (6 ft)', 'tents' => 'Canopy tent 3 × 3 m',
        'sound' => 'Speaker', 'lighting' => 'Uplight / PAR light', 'decorations' => 'Centerpiece', 'backdrops' => 'Backdrop frame',
        'tableware' => 'Plate', 'catering' => 'Chafing dish', 'games' => 'Bubble machine', 'furniture' => 'Sofa / lounge set',
        'other' => 'Props set',
    ];

    /** Presets as plain arrays for the product form's script. */
    public static function forJs(): array
    {
        return array_map(fn ($p) => ['label' => $p[0], 'words' => $p[1], 'cat' => $p[2], 'kg' => $p[3], 'l' => $p[4], 'w' => $p[5], 'h' => $p[6]], self::PRESETS);
    }

    /** Best preset for a name/category (same rules as the form's script), or null. */
    public static function guess(string $name, ?string $category = null): ?array
    {
        $name = ' '.preg_replace('/[^a-z0-9]+/', ' ', strtolower($name)).' ';
        $best = null;
        $bestLen = 0;
        foreach (self::forJs() as $p) {
            foreach ($p['words'] as $w) {
                $len = strlen($w) + ($p['cat'] === $category ? 100 : 0); // prefer a match in the chosen category
                if (str_contains($name, ' '.$w.' ') && $len > $bestLen) {
                    [$best, $bestLen] = [$p, $len];
                }
            }
        }
        if ($best || ! $category || ! isset(self::CATEGORY_DEFAULT[$category])) {
            return $best;
        }

        return collect(self::forJs())->firstWhere('label', self::CATEGORY_DEFAULT[$category]);
    }
}
