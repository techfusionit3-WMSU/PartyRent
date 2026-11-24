<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Validation\Rules\Password;

/**
 * Shared account rules (registration, staff invitations, profile, password change and reset):
 *   name      first + last required, middle optional (letters, spaces, . ' -)
 *   birthday  a real date, not in the future, at least 18 years old today (worked out from the date, never typed)
 *   gender    male | female | prefer_not
 *   password  at least 8 characters with an uppercase and a lowercase letter (Password::defaults(), AppServiceProvider)
 */
class AccountRules
{
    public const MIN_AGE = 18;

    public const GENDERS = ['male' => 'Male', 'female' => 'Female', 'prefer_not' => 'Prefer not to say'];

    /** What a password needs, shown next to every new-password field. */
    public const PASSWORD_NEEDS = ['len' => 'At least 8 characters', 'upper' => 'One uppercase letter (A–Z)', 'lower' => 'One lowercase letter (a–z)'];

    private const NAME = 'regex:/^[\pL][\pL\pM .\'\-]*$/u';

    public static function name(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:80', self::NAME],
            'middle_name' => ['nullable', 'string', 'max:80', self::NAME],
            'last_name' => ['required', 'string', 'max:80', self::NAME],
        ];
    }

    /** Age in whole years on $on (today): one less until this year's birthday has come. */
    public static function age(Carbon|string $birthday, ?Carbon $on = null): int
    {
        $b = Carbon::parse($birthday);
        $on ??= today();
        $age = $on->year - $b->year;
        if ($on->format('md') < $b->format('md')) {
            $age--; // this year's birthday has not come yet (Feb 29 birthdays count from Mar 1 in other years)
        }

        return max(0, $age);
    }

    public static function birthday(bool $required = true): array
    {
        return ['birthday' => [$required ? 'required' : 'nullable', 'date_format:Y-m-d', 'before_or_equal:today', 'after:1900-01-01',
            function ($attr, $value, $fail) {
                if (! $value || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $value) || ! strtotime($value) || $value > today()->toDateString()) {
                    return; // the rules above report it
                }
                if (self::age($value) < self::MIN_AGE) {
                    $fail('You must be at least '.self::MIN_AGE.' years old to have a PartyRent account (you are '.self::age($value).').');
                }
            }]];
    }

    public static function gender(bool $required = true): array
    {
        return ['gender' => [$required ? 'required' : 'nullable', 'in:'.implode(',', array_keys(self::GENDERS))]];
    }

    /** Password rule for creating or changing a password (Password::defaults()). */
    public static function password(): Password
    {
        return Password::min(8)->mixedCase();
    }

    public static function messages(string $passwordField = 'password'): array
    {
        return [
            'first_name.required' => 'Enter your first name.', 'last_name.required' => 'Enter your last name.',
            'first_name.regex' => 'Use letters only in your first name.', 'middle_name.regex' => 'Use letters only in your middle name.', 'last_name.regex' => 'Use letters only in your last name.',
            'birthday.required' => 'Enter your birthday.', 'birthday.date_format' => 'Enter a valid birthday.',
            'birthday.before_or_equal' => 'Your birthday cannot be in the future.', 'birthday.after' => 'Enter a valid birthday.',
            'gender.required' => 'Choose your gender (or "Prefer not to say").', 'gender.in' => 'Choose one of the gender options.',
            $passwordField.'.min' => 'Your password needs at least 8 characters.',
            $passwordField.'.mixed' => 'Your password needs at least one uppercase letter (A–Z) and one lowercase letter (a–z).',
            $passwordField.'.confirmed' => 'The two passwords do not match.',
        ];
    }

    /** "Maria Lopez Santos" from the parts (extra spaces removed). */
    public static function fullName(?string $first, ?string $middle, ?string $last): string
    {
        return trim(preg_replace('/\s+/', ' ', implode(' ', array_filter([trim((string) $first), trim((string) $middle), trim((string) $last)]))));
    }
}
