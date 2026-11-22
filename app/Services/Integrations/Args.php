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
                $out[$name] = (($spec['type'] ?? null) === 'array' && ! is_array($v)) ? [$v] : $v;
            } elseif (in_array($n, self::START, true) && isset($values['_start'])) {
                $out[$name] = self::date($values['_start'], $spec);
            } elseif (in_array($n, self::END, true) && isset($values['_end'])) {
                $out[$name] = self::date($values['_end'], $spec);
            } elseif (in_array($n, self::LIMIT, true)) {
                $out[$name] = min((int) ($values['_limit'] ?? 500), (int) ($spec['maximum'] ?? 500));
            }
        }
        foreach ($required as $r) {
            if (! array_key_exists($r, $out)) {
                return null;
            }
        }

        return $out;
    }

    private static function date(string $ymd, array $spec): string
    {
        return ($spec['format'] ?? null) === 'date-time' ? $ymd.'T00:00:00Z' : $ymd;
    }
}
