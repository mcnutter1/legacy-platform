<?php

namespace App\Support;

use InvalidArgumentException;

/** Decimal-string arithmetic (bcmath). Money never passes through floats. */
final class Dec
{
    public const SCALE = 4;

    public static function norm(mixed $v, int $scale = self::SCALE): string
    {
        if ($v === null || $v === '') {
            throw new InvalidArgumentException('Missing decimal value');
        }
        if (is_int($v)) {
            $v = (string) $v;
        } elseif (is_float($v)) {
            $v = number_format($v, $scale + 2, '.', '');
        }
        $v = trim((string) $v);
        $v = str_replace([',', '$', ' '], '', $v);
        if (preg_match('/^\((.*)\)$/', $v, $m)) {
            $v = '-'.$m[1];
        }
        if (! preg_match('/^[+-]?\d*(\.\d+)?$/', $v) || $v === '' || $v === '-' || $v === '+') {
            throw new InvalidArgumentException("Invalid decimal: {$v}");
        }

        return bcadd($v, '0', $scale);
    }

    public static function tryNorm(mixed $v, int $scale = self::SCALE): ?string
    {
        try {
            return ($v === null || $v === '') ? null : self::norm($v, $scale);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public static function add(string ...$vals): string
    {
        $sum = '0';
        foreach ($vals as $v) {
            $sum = bcadd($sum, $v, self::SCALE);
        }

        return $sum;
    }

    public static function sub(string $a, string $b): string
    {
        return bcsub($a, $b, self::SCALE);
    }

    public static function mul(string $a, string $b, int $scale = self::SCALE): string
    {
        return self::round(bcmul($a, $b, $scale + 6), $scale);
    }

    public static function div(string $a, string $b, int $scale = self::SCALE): string
    {
        if (bccomp($b, '0', 12) === 0) {
            throw new InvalidArgumentException('Division by zero');
        }

        return self::round(bcdiv($a, $b, $scale + 6), $scale);
    }

    public static function cmp(string $a, string $b): int
    {
        return bccomp($a, $b, self::SCALE + 6);
    }

    public static function isZero(string $a): bool
    {
        return bccomp($a, '0', 10) === 0;
    }

    public static function neg(string $a): string
    {
        return bcmul($a, '-1', self::SCALE);
    }

    public static function abs(string $a): string
    {
        return str_starts_with($a, '-') ? substr($a, 1) : $a;
    }

    /** Round half away from zero. */
    public static function round(string $v, int $scale = 2): string
    {
        $offset = '0.'.str_repeat('0', $scale).'5';
        $r = str_starts_with($v, '-') ? bcsub($v, $offset, $scale) : bcadd($v, $offset, $scale);

        return $r === '-0'.($scale ? '.'.str_repeat('0', $scale) : '') ? substr($r, 1) : $r;
    }
}
