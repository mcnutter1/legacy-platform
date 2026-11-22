<?php

namespace App\Services\Integrations;

/** Schema-tolerant readers for provider payloads whose exact field names are discovered at connect time. */
final class Shape
{
    /** Find the list of objects under one of $keys (any depth), else the largest list of objects in the payload. */
    public static function records(mixed $payload, array $keys): array
    {
        if (self::isList($payload)) {
            return array_values(array_filter($payload, 'is_array'));
        }
        if (! is_array($payload)) {
            return [];
        }
        $best = [];
        $stack = [[$payload, 0]];
        while ($stack) {
            [$node, $depth] = array_pop($stack);
            foreach ($node as $k => $v) {
                if (! is_array($v)) {
                    continue;
                }
                if (self::isList($v) && $v && is_array($v[0])) {
                    if (is_string($k) && in_array(self::norm($k), array_map([self::class, 'norm'], $keys), true)) {
                        return $v;
                    }
                    if (count($v) > count($best)) {
                        $best = $v;
                    }
                } elseif ($depth < 4) {
                    $stack[] = [$v, $depth + 1];
                }
            }
        }

        return $best;
    }

    /** First non-empty value among candidate keys; supports dotted paths and camel/snake/case-insensitive names. */
    public static function pick(array $row, array $candidates): mixed
    {
        foreach ($candidates as $path) {
            $node = $row;
            foreach (explode('.', $path) as $seg) {
                if (! is_array($node)) {
                    $node = null;
                    break;
                }
                $node = self::get($node, $seg);
            }
            if ($node !== null && $node !== '' && $node !== []) {
                return $node;
            }
        }

        return null;
    }

    public static function str(array $row, array $candidates, int $max = 255): ?string
    {
        $v = self::pick($row, $candidates);
        if (is_array($v)) {
            $v = self::pick($v, ['name', 'display_name', 'value', 'label']);
        }

        return is_scalar($v) && trim((string) $v) !== '' ? mb_substr(trim((string) $v), 0, $max) : null;
    }

    /** Decimal string from numbers or "$1,234.56" / {amount: …} shapes; never via float formatting of large values. */
    public static function dec(array $row, array $candidates): ?string
    {
        $v = self::pick($row, $candidates);
        if (is_array($v)) {
            $v = self::pick($v, ['amount', 'value', 'total']);
        }
        if (is_int($v)) {
            return (string) $v;
        }
        if (is_float($v)) {
            return rtrim(rtrim(number_format($v, 8, '.', ''), '0'), '.') ?: '0';
        }
        if (is_string($v)) {
            $s = str_replace([',', '$', ' ', "\u{00A0}"], '', $v);
            if (preg_match('/^\((\d+(\.\d+)?)\)$/', $s, $m)) {
                $s = '-'.$m[1];
            }

            return preg_match('/^-?\d+(\.\d+)?$/', $s) ? $s : null;
        }

        return null;
    }

    public static function date(array $row, array $candidates): ?string
    {
        $v = self::pick($row, $candidates);
        if (! is_string($v) && ! is_int($v)) {
            return null;
        }
        if (is_int($v)) {
            return date('Y-m-d', $v > 1e11 ? intdiv($v, 1000) : $v);
        }

        return preg_match('/^(\d{4}-\d{2}-\d{2})/', $v, $m) ? $m[1] : (($t = strtotime($v)) ? date('Y-m-d', $t) : null);
    }

    public static function norm(string $k): string
    {
        return strtolower(str_replace(['_', '-', ' '], '', $k));
    }

    private static function get(array $node, string $key): mixed
    {
        if (array_key_exists($key, $node)) {
            return $node[$key];
        }
        $want = self::norm($key);
        foreach ($node as $k => $v) {
            if (is_string($k) && self::norm($k) === $want) {
                return $v;
            }
        }

        return null;
    }

    private static function isList(mixed $v): bool
    {
        return is_array($v) && array_is_list($v);
    }
}
