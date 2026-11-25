<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/** A shop's extra policies (title + description) and its uploaded logo. */
class ShopExtras
{
    public const MAX_POLICIES = 12;

    /** Clean the policy rows: blank rows dropped, half-filled rows and duplicate titles refused. */
    public static function policies(array $rows, string $field = 'custom_policies'): array
    {
        $out = [];
        $seen = [];
        $errors = [];
        foreach (array_values($rows) as $i => $r) {
            $t = trim(preg_replace('/\s+/', ' ', (string) ($r['title'] ?? '')));
            $b = trim((string) ($r['body'] ?? ''));
            if ($t === '' && $b === '') {
                continue;
            }
            if ($t === '' || mb_strlen($t) < 3) {
                $errors[$field.'.'.$i.'.title'] = 'Give policy '.($i + 1).' a title (at least 3 characters).';
            } elseif (mb_strlen($t) > 80) {
                $errors[$field.'.'.$i.'.title'] = 'Keep policy titles under 80 characters.';
            } elseif (isset($seen[mb_strtolower($t)])) {
                $errors[$field.'.'.$i.'.title'] = '"'.$t.'" is already listed. Use one entry per policy.';
            }
            if (mb_strlen($b) < 10) {
                $errors[$field.'.'.$i.'.body'] = 'Describe "'.($t ?: 'policy '.($i + 1)).'" (at least 10 characters).';
            } elseif (mb_strlen($b) > 1500) {
                $errors[$field.'.'.$i.'.body'] = 'Keep each policy under 1,500 characters.';
            }
            $seen[mb_strtolower($t)] = true;
            $out[] = ['title' => $t, 'body' => $b];
        }
        if (count($out) > self::MAX_POLICIES) {
            $errors[$field] = 'Up to '.self::MAX_POLICIES.' policies.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $out;
    }

    public const LOGO_RULES = ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:min_width=64,min_height=64,max_width=4000,max_height=4000'];

    public const LOGO_MESSAGES = ['logo_file.mimes' => 'Use a JPG, PNG or WEBP image for the logo.', 'logo_file.max' => 'The logo can be up to 2 MB.', 'logo_file.dimensions' => 'The logo should be between 64 and 4000 pixels on each side.'];

    /**
     * Save an uploaded logo (public disk). When GD is available it is cropped to a square and resized to 512 px
     * (WEBP), so pages load fast; otherwise the original file (already limited to 2 MB) is kept.
     */
    public static function storeLogo(UploadedFile $f, int $ownerId): string
    {
        $dir = 'logos/'.$ownerId;
        if (function_exists('imagecreatefromstring') && function_exists('imagewebp') && ($src = @imagecreatefromstring((string) file_get_contents($f->getRealPath())))) {
            $w = imagesx($src);
            $h = imagesy($src);
            $side = min($w, $h);
            $size = min(512, $side);
            $dst = imagecreatetruecolor($size, $size);
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagecopyresampled($dst, $src, 0, 0, intdiv($w - $side, 2), intdiv($h - $side, 2), $size, $size, $side, $side);
            ob_start();
            imagewebp($dst, null, 85);
            $bytes = ob_get_clean();
            $path = $dir.'/logo-'.bin2hex(random_bytes(6)).'.webp';
            Storage::disk('public')->put($path, $bytes);

            return $path;
        }

        return $f->storeAs($dir, 'logo-'.bin2hex(random_bytes(6)).'.'.strtolower($f->extension() ?: 'png'), 'public');
    }

    public static function dropLogo(?string $path): void
    {
        if ($path && str_starts_with($path, 'logos/')) {
            Storage::disk('public')->delete($path);
        }
    }
}
