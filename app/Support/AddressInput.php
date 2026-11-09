<?php

namespace App\Support;

use App\Domain\Locations;

/**
 * Validation + normalisation for the structured address fields rendered by
 * resources/views/partials/address-fields.blade.php (inputs named addr[regionCode], ...).
 */
class AddressInput
{
    /** @param bool $full  require barangay and street too (delivery / business addresses) */
    public static function rules(string $key = 'addr', bool $full = true, bool $required = true): array
    {
        $req = $required ? 'required' : 'nullable';

        return [
            $key => $required ? 'required|array' : 'nullable|array',
            "$key.regionCode" => "$req|string|max:12",
            "$key.provinceCode" => "$req|string|max:12",
            "$key.cityCode" => [$req, 'string', 'max:12', function ($attr, $val, $fail) {
                if ($val && ! Locations::isCity($val)) {
                    $fail('Choose a city or municipality from the list.');
                }
            }],
            "$key.barangay" => ($full && $required ? 'required' : 'nullable').'|string|max:120',
            "$key.street" => ($full && $required ? 'required' : 'nullable').'|string|max:180',
            "$key.landmark" => 'nullable|string|max:180',
        ];
    }

    public static function messages(string $key = 'addr'): array
    {
        return [
            "$key.regionCode.required" => 'Choose a region.',
            "$key.provinceCode.required" => 'Choose a province.',
            "$key.cityCode.required" => 'Choose a city or municipality.',
            "$key.barangay.required" => 'Enter the barangay.',
            "$key.street.required" => 'Enter the house no. and street.',
        ];
    }

    /** Model attributes for a validated address: address (display), address_parts, city_code. */
    public static function attributes(?array $input): array
    {
        $a = Locations::cleanAddress($input ?? []);

        return ['address' => Locations::formatAddress($a), 'address_parts' => $a, 'city_code' => $a['cityCode']];
    }
}
