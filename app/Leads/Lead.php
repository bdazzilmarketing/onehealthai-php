<?php

namespace App\Leads;

use App\Support\Js;

/**
 * Lead contact fields and their validation (port of src/lib/lead-types.ts).
 * Contact-field logic only: never pricing and never AI payload data.
 */
final class Lead
{
    public const LIMITS = ['fullName' => 120, 'company' => 160, 'email' => 254, 'phone' => 32];

    /** @return array{fullName: string, company: string, phone: string, email: string} */
    public static function normalize(mixed $input): array
    {
        $input = is_array($input) ? $input : [];
        $text = fn (string $key) => Js::trim(Js::string($input[$key] ?? ''));

        return [
            'fullName' => Js::collapseSpaces($text('fullName')),
            'company' => Js::collapseSpaces($text('company')),
            'phone' => Js::collapseSpaces($text('phone')),
            'email' => mb_strtolower($text('email'), 'UTF-8'),
        ];
    }

    /** E.164-ish digits form kept for downstream reporting. Never used for identity. */
    public static function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/[^0-9]/', '', $phone);

        return str_starts_with(Js::trim($phone), '+') ? "+{$digits}" : $digits;
    }

    /**
     * @param  array{fullName: string, company: string, phone: string, email: string}  $input
     * @return array<string, string> field name to message; empty when valid
     */
    public static function validate(array $input): array
    {
        $errors = [];
        $space = Js::SPACE;

        $length = Js::length($input['fullName']);
        if ($length < 2) {
            $errors['fullName'] = 'Please enter your full name.';
        } elseif ($length > self::LIMITS['fullName']) {
            $errors['fullName'] = 'That name is too long.';
        }

        $length = Js::length($input['company']);
        if ($length < 2) {
            $errors['company'] = 'Please enter your company name.';
        } elseif ($length > self::LIMITS['company']) {
            $errors['company'] = 'That company name is too long.';
        }

        $length = Js::length($input['email']);
        if ($length === 0) {
            $errors['email'] = 'Please enter your email address.';
        } elseif ($length > self::LIMITS['email']) {
            $errors['email'] = 'That email address is too long.';
        } elseif (preg_match("/^[^{$space}@]+@[^{$space}@.]+(\\.[^{$space}@.]+)+\\z/u", $input['email']) !== 1) {
            $errors['email'] = 'Please enter a valid email address.';
        }

        $digits = strlen(preg_replace('/[^0-9]/', '', $input['phone']));
        $length = Js::length($input['phone']);
        if ($length === 0) {
            $errors['phone'] = 'Please enter your phone number.';
        } elseif ($length > self::LIMITS['phone']) {
            $errors['phone'] = 'That phone number is too long.';
        } elseif (preg_match("/^[+()\\-.{$space}0-9]+\\z/u", $input['phone']) !== 1 || $digits < 7 || $digits > 15) {
            $errors['phone'] = 'Please enter a valid phone number.';
        }

        return $errors;
    }

    public static function isCalculatorMode(mixed $value): bool
    {
        return $value === 'ai' || $value === 'manual';
    }
}
