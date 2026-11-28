<?php

namespace App\Auth;

/** 12 to 72 characters and at most 72 bytes (the bcrypt limit). No trimming, no character classes. */
class PasswordPolicy
{
    public const MIN_CHARACTERS = 12;

    public const MAX_BYTES = 72;

    public static function allows(#[\SensitiveParameter] string $password): bool
    {
        return mb_check_encoding($password, 'UTF-8')
            && mb_strlen($password, 'UTF-8') >= self::MIN_CHARACTERS
            && strlen($password) <= self::MAX_BYTES
            && ! str_contains($password, "\0");
    }
}
