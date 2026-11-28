<?php

namespace App\Modules\Finance\Support;

use Closure;
use DateTimeImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/** Rules of the field types of the contract, and the output format of instants. */
class Values
{
    private const MAX_CENTS = 9007199254740991;

    private const INSTANT = '/^(19\d\d|2[01]\d\d|2200)-\d\d-\d\dT([01]\d|2[0-3]):[0-5]\d:[0-5]\d(\.\d{1,6})?Z$/';

    /** Not blank, valid UTF-8, no NUL, at most $max characters. */
    public static function text(int $max, bool $nullable = false): Closure
    {
        return fn (mixed $v): ?string => match (true) {
            $v === null => $nullable ? null : 'required',
            ! is_string($v) => 'string',
            ! mb_check_encoding($v, 'UTF-8') || str_contains($v, "\0") => 'invalid',
            trim($v) === '' => 'blank',
            mb_strlen($v, 'UTF-8') > $max => 'max',
            default => null,
        };
    }

    /** @param  list<string>  $options */
    public static function oneOf(array $options): Closure
    {
        return fn (mixed $v): ?string => is_string($v) && in_array($v, $options, true) ? null : 'in';
    }

    public static function boolean(): Closure
    {
        return fn (mixed $v): ?string => is_bool($v) ? null : 'boolean';
    }

    /** JSON integers only: no strings, no decimals, no exponent notation. */
    public static function integer(int $min, int $max): Closure
    {
        return fn (mixed $v): ?string => match (true) {
            ! is_int($v) => 'integer',
            $v < $min || $v > $max => 'out_of_range',
            default => null,
        };
    }

    public static function cents(): Closure
    {
        return self::integer(-self::MAX_CENTS, self::MAX_CENTS);
    }

    public static function int32(): Closure
    {
        return self::integer(-2147483648, 2147483647);
    }

    public static function date(): Closure
    {
        return function (mixed $v): ?string {
            if (! is_string($v) || preg_match('/^(\d{4})-(\d\d)-(\d\d)$/D', $v, $m) !== 1) {
                return 'date';
            }

            return $m[1] >= 1900 && $m[1] <= 2200 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? null : 'date';
        };
    }

    public static function nullableInstant(): Closure
    {
        return function (mixed $v): ?string {
            if ($v === null) {
                return null;
            }

            if (! is_string($v) || preg_match(self::INSTANT.'D', $v) !== 1) {
                return 'instant';
            }

            // Refuses days that do not exist, which the parser would roll over.
            return checkdate((int) substr($v, 5, 2), (int) substr($v, 8, 2), (int) substr($v, 0, 4)) ? null : 'instant';
        };
    }

    public static function nullableUuid(): Closure
    {
        return fn (mixed $v): ?string => $v === null || (is_string($v) && Str::isUuid($v)) ? null : 'uuid';
    }

    public static function nullableColor(): Closure
    {
        return fn (mixed $v): ?string => $v === null || (is_string($v) && preg_match('/^#[0-9A-Fa-f]{6}$/D', $v) === 1) ? null : 'color';
    }

    public static function instant(?string $value): ?string
    {
        return $value === null ? null : Carbon::instance(new DateTimeImmutable($value))->utc()->format('Y-m-d\TH:i:s.u\Z');
    }
}
