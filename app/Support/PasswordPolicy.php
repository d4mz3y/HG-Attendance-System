<?php

namespace App\Support;

use Closure;
use Illuminate\Validation\Rules\Password;

final class PasswordPolicy
{
    /*
     * Bcrypt processes at most 72 bytes. Keep a conservative character cap
     * for predictable UI behaviour, then enforce the real byte boundary for
     * non-ASCII passwords as well.
     */
    public const MAX_CHARACTERS = 64;

    public const MAX_BYTES = 72;

    /** @return array<int, mixed> */
    public static function strong(bool $required = true, bool $confirmed = false): array
    {
        $rules = [
            $required ? 'required' : 'nullable',
            'string',
            'max:'.self::MAX_CHARACTERS,
            self::withinBcryptByteLimit(),
            Password::min(12)->letters()->mixedCase()->numbers()->symbols(),
        ];

        if ($confirmed) {
            array_splice($rules, 2, 0, 'confirmed');
        }

        return $rules;
    }

    /** @return array<int, mixed> */
    public static function login(): array
    {
        return [
            'required',
            'string',
            'max:'.self::MAX_CHARACTERS,
            self::withinBcryptByteLimit(),
        ];
    }

    private static function withinBcryptByteLimit(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && strlen($value) > self::MAX_BYTES) {
                $fail('The :attribute may not exceed '.self::MAX_BYTES.' bytes.');
            }
        };
    }
}
