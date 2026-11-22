<?php

namespace App\Services\Integrations;

/** Builds tool arguments from a tool's JSON Schema using only well-known parameter names. */
final class Args
{
    private const START = ['startdate', 'start', 'from', 'fromdate', 'since', 'begin', 'begindate', 'datefrom', 'mindate'];

    private const END = ['enddate', 'end', 'to', 'todate', 'until', 'dateto', 'maxdate'];

    private const LIMIT = ['limit', 'first', 'pagesize', 'count', 'perpage', 'maxresults', 'size'];

    /** Name of the account/portfolio id parameter, if the tool is per-account. */
    public static function parentKey(array $schema): ?string
    {
        foreach (array_keys((array) ($schema['properties'] ?? [])) as $name) {
            if (preg_match('/^(account|portfolio)_?ids?$/i', (string) $name)) {
                return (string) $name;
            }
        }

        return null;
    }

    /** @return array<string, mixed>|null null when a required parameter cannot be supplied */
    public static function build(array $schema, array $values): ?array
    {
        $props = (array) ($schema['properties'] ?? []);
        $required = (array) ($schema['required'] ?? []);
        $out = [];
        foreach ($props as $name => $spec) {
            $n = Shape::norm((string) $name);
            $spec = is_array($spec) ? $spec : [];
            if (array_key_exists($name, $values)) {
                $v = $values[$name];
                $isArray = ($spec['type'] ?? null) === 'array' || in_array('array', array_column((array) ($spec['anyOf'] ?? []), 'type'), true);
                $out[$name] = ($isArray && ! is_array($v)) ? [$v] : $v;
            } elseif (in_array($n, self::START, true) && isset($values['_start'])) {
                $out[$name] = self::date($values['_start'], $spec);
            } elseif (in_array($n, self::END, true) && isset($values['_end'])) {
                $out[$name] = self::date($values['_end'], $spec);
            } elseif (in_array($n, self::LIMIT, true)) {
                $out[$name] = min((int) ($values['_limit'] ?? 500), self::maximum($spec));
            } elseif (in_array($name, $required, true) && is_array($spec['enum'] ?? null) && $spec['enum']) {
                // Required enums such as Monarch's "intent": use the caller's preferred value when offered, else the first specific one.
                $prefer = array_values(array_intersect((array) ($values['_prefer'] ?? []), $spec['enum']));
                $specific = array_values(array_filter($spec['enum'], fn ($e) => ! in_array($e, ['other', 'unknown', 'none'], true)));
                $out[$name] = $prefer[0] ?? $specific[0] ?? $spec['enum'][0];
            }
        }
        foreach ($required as $r) {
            if (! array_key_exists($r, $out)) {
                return null;
            }
        }

        return $out;
    }

    /** Declared maximum, else one stated in the description ("1-100"), else 500. */
    private static function maximum(array $spec): int
    {
        if (isset($spec['maximum'])) {
            return (int) $spec['maximum'];
        }

        return preg_match('/\b1\s*[-–]\s*(\d{1,4})\b/', (string) ($spec['description'] ?? ''), $m) ? (int) $m[1] : 500;
    }

    private static function date(string $ymd, array $spec): string
    {
        return ($spec['format'] ?? null) === 'date-time' ? $ymd.'T00:00:00Z' : $ymd;
    }
}
