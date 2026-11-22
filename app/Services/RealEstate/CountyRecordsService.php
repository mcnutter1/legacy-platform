<?php

namespace App\Services\RealEstate;

use App\Support\AccessContext;
use App\Support\Audit;
use App\Support\Dec;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Monmouth County public records, fetched on demand for one property at a time (run as a job):
 * the tax-board record card (assessment history, taxes, SR1A sales) and the County Clerk OPRS index
 * (instrument number, recording date, parties) for each deed/mortgage book & page.
 * Robots.txt on the tax-board host is intentionally not honored (owner's decision); requests stay low-volume and identified.
 */
final class CountyRecordsService
{
    private const CARD_URL = 'https://tax1.co.monmouth.nj.us/cgi-bin/m4.cgi';

    private const OPRS_URL = 'https://oprs.co.monmouth.nj.us/oprs/clerk/ClerkHome.aspx';

    private const UA = 'McNuttLegacy/1.0 (+https://legacy.mcnutt.cloud)';

    public function __construct(private readonly AccessContext $ctx) {}

    public function sync(object $property): array
    {
        if (strtoupper((string) $property->county) !== 'MONMOUTH' || ! $property->mun_code || ! $property->block || ! $property->lot) {
            return ['status' => 'unsupported', 'message' => 'County records are integrated for Monmouth County parcels with a block and lot.'];
        }
        $out = ['status' => 'ok', 'card' => null, 'tax_years' => 0, 'instruments_added' => 0, 'oprs_matched' => 0, 'issues' => []];
        $card = $this->card($property->mun_code, $property->block, $property->lot, $property->qualifier);
        if ($card === null) {
            $out['issues'][] = 'The county record card could not be read.';
        } else {
            $out['card'] = ['years' => array_column($card['history'], 'year'), 'taxes' => $card['taxes'], 'sales' => count($card['sales'])];
            DB::transaction(function () use ($property, $card, &$out) {
                $out['tax_years'] = $this->storeHistory($property, $card);
                $out['instruments_added'] = $this->storeSales($property, $card['sales']);
            });
        }
        $pending = DB::table('recorded_instruments')->where('property_id', $property->id)->whereNull('archived_at')->whereNotNull('book')->whereNotNull('page')
            ->whereNull('instrument_number')->limit(10)->get();
        foreach ($pending as $i => $ins) {
            if ($i > 0) {
                usleep(800_000);
            }
            $rows = $this->oprsByBookPage($ins->book, $ins->page);
            if ($rows === null) {
                $out['issues'][] = "OPRS did not answer for book {$ins->book} page {$ins->page}.";

                continue;
            }
            if (! $rows) {
                continue;
            }
            $first = $rows[0];
            $grantees = array_values(array_unique(array_filter(array_column($rows, 'indirect'))));
            DB::table('recorded_instruments')->where('id', $ins->id)->update(array_filter([
                'instrument_number' => $first['instrument'], 'recorded_on' => $first['recorded_on'] ?? $ins->recorded_on,
                'grantor' => $ins->grantor ?: ($first['direct'] ?: null), 'grantee' => $ins->grantee ?: (implode('; ', $grantees) ?: null),
                'kind' => $ins->source === 'manual' ? null : self::kind($first['type']),
                'notes' => trim(($ins->notes ? $ins->notes."\n" : '').'Matched in Monmouth County Clerk OPRS: '.$first['type'].', recorded '.($first['recorded_on'] ?? '?').'.'),
                'updated_at' => now(), 'edit_version' => $ins->edit_version + 1,
            ], fn ($v) => $v !== null));
            $out['oprs_matched']++;
        }
        DB::table('properties')->where('id', $property->id)->update(['records_refreshed_at' => now(), 'updated_at' => now()]);
        Audit::log('property.county_records_synced', 'property', $property->id, array_diff_key($out, ['card' => 1, 'issues' => 1]), scopeId: $property->scope_id);

        return $out;
    }

    /** Parse the tax-board record card: taxes, SR1A sales and the full tax-list history. */
    public function card(string $mun, string $block, string $lot, ?string $qual): ?array
    {
        $key = PropertyLookupService::monmouthCardKey($mun, $block, $lot, $qual);
        if (! $key) {
            return null;
        }
        try {
            $res = Http::withUserAgent(self::UA)->timeout(20)->get(self::CARD_URL, ['district' => $mun, 'l02' => $key, 'hist' => 1]);
        } catch (ConnectionException) {
            return null;
        }
        if (! $res->successful()) {
            return null;
        }

        return self::parseCard($res->body());
    }

    public static function parseCard(string $htmlDoc): ?array
    {
        $text = fn (string $s) => trim(preg_replace('/\s+/u', ' ', str_replace(["\u{00A0}", '&nbsp'], ' ', html_entity_decode($s, ENT_QUOTES | ENT_HTML5))));
        $flat = preg_replace('/\s+/', ' ', $htmlDoc);
        if (! str_contains($flat, 'TAX-LIST-HISTORY')) {
            return null;
        }
        $taxes = null;
        if (preg_match('/Taxes:\s*<\/font>.*?<font[^>]*>\s*([\d.,]+)\s*\/\s*([\d.,]+)\s*</i', $flat, $m)) {
            $taxes = ['last_year' => Dec::tryNorm(str_replace(',', '', $m[1])), 'this_year' => Dec::tryNorm(str_replace(',', '', $m[2]))];
        }
        $sales = [];
        $sr = strpos($flat, '>Sr1a') ?: strpos($flat, 'Sr1a </td>');
        $hist = strpos($flat, 'TAX-LIST-HISTORY');
        if ($sr !== false && $hist !== false && $sr < $hist) {
            foreach (preg_split('/<tr>/i', substr($flat, $sr, $hist - $sr)) as $row) {
                $cells = array_map($text, array_map('strip_tags', preg_split('/<\/td>/i', $row)));
                $cells = array_values(array_filter($cells, fn ($c) => $c !== ''));
                if (count($cells) >= 6 && preg_match('#^(\d{2})/(\d{2})/(\d{2})$#', $cells[1] ?? '', $d)) {
                    $year = (int) $d[3] + ((int) $d[3] > (int) now()->format('y') + 1 ? 1900 : 2000);
                    $sales[] = ['date' => checkdate((int) $d[1], (int) $d[2], $year) ? sprintf('%04d-%s-%s', $year, $d[1], $d[2]) : null,
                        'book' => preg_replace('/\D/', '', $cells[2]) ?: null, 'page' => preg_replace('/\D/', '', $cells[3]) ?: null,
                        'price' => ctype_digit($cells[4]) && (int) $cells[4] > 0 ? $cells[4] : null, 'nu_code' => ctype_digit($cells[5]) ? $cells[5] : null,
                        'grantee' => isset($cells[7]) ? mb_substr($cells[7], 0, 255) : null];
                }
            }
        }
        $tokens = [];
        foreach (preg_split('/<[^>]+>/', substr($flat, $hist)) as $t) {
            $t = $text($t);
            if ($t !== '') {
                $tokens[] = $t;
            }
        }
        $history = [];
        for ($i = 0; $i + 7 < count($tokens); $i++) {
            if (preg_match('/^(19|20)\d{2}$/', $tokens[$i]) && ! ctype_digit($tokens[$i + 1]) && ctype_digit($tokens[$i + 2]) && ctype_digit($tokens[$i + 4])
                && ctype_digit($tokens[$i + 6]) && ctype_digit($tokens[$i + 7])) {
                $history[] = ['year' => (int) $tokens[$i], 'location' => $tokens[$i + 1], 'land' => $tokens[$i + 2], 'exemption' => $tokens[$i + 3],
                    'assessed' => $tokens[$i + 4], 'class' => $tokens[$i + 5], 'improvement' => $tokens[$i + 6], 'total' => $tokens[$i + 7]];
                $i += 7;
            }
        }

        return ['taxes' => $taxes, 'sales' => $sales, 'history' => $history];
    }

    /** OPRS index search by deed/mortgage book and page. Returns [] for no match, null when the site fails. */
    public function oprsByBookPage(string $book, string $page): ?array
    {
        $book = ltrim(preg_replace('/\D/', '', $book), '0');
        $page = ltrim(preg_replace('/\D/', '', $page), '0');
        if ($book === '' || $page === '') {
            return [];
        }
        try {
            $http = Http::withUserAgent(self::UA)->timeout(30)->withOptions(['cookies' => new \GuzzleHttp\Cookie\CookieJar, 'allow_redirects' => false]);
            $home = $http->get(self::OPRS_URL);
            if (! $home->successful()) {
                return null;
            }
            $form = self::hiddenFields($home->body()) + [
                'ctl00$ContentPlaceHolder1$txtInstrumentTab3' => '', 'ctl00$ContentPlaceHolder1$txtBookNumberTab3' => $book,
                'ctl00$ContentPlaceHolder1$txtPageNumberTab3' => $page, 'ctl00$ContentPlaceHolder1$btnSearchTab3' => 'Search', 'hidCurrTab' => '3',
            ];
            $res = $http->withHeaders(['Referer' => self::OPRS_URL])->asForm()->post(self::OPRS_URL, $form);
        } catch (ConnectionException) {
            return null;
        }

        return $res->successful() ? self::parseOprsResults($res->body()) : null;
    }

    public static function parseOprsResults(string $htmlDoc): array
    {
        $out = [];
        preg_match_all('/<tr class="(?:itemstyle|altitemstyle)">(.*?)<\/tr>/is', $htmlDoc, $rows);
        foreach ($rows[1] as $r) {
            preg_match_all('/<td[^>]*>(.*?)<\/td>/is', $r, $c);
            $cells = array_map(fn ($x) => trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($x), ENT_QUOTES | ENT_HTML5))), $c[1]);
            if (count($cells) < 10) {
                continue;
            }
            $d = \DateTime::createFromFormat('!n/j/Y', $cells[4]);
            $out[] = ['type' => mb_substr($cells[0], 0, 80), 'direct' => mb_substr($cells[1], 0, 255), 'indirect' => mb_substr($cells[2], 0, 255),
                'instrument' => preg_match('/^[\w-]{4,40}$/', $cells[3]) ? $cells[3] : null, 'recorded_on' => $d ? $d->format('Y-m-d') : null,
                'town' => $cells[5], 'block' => $cells[6], 'lot' => $cells[7], 'book' => preg_replace('/^[A-Z]+-/', '', $cells[8]), 'page' => $cells[9]];
        }

        return $out;
    }

    private function storeHistory(object $property, array $card): int
    {
        $n = 0;
        $thisYear = (int) now()->format('Y');
        $taxFor = [];
        // The card shows "last year / this year" taxes per the MOD-IV tax-list layout.
        if ($card['taxes']['this_year'] ?? null) {
            $taxFor[$thisYear] = $card['taxes']['this_year'];
        }
        if ($card['taxes']['last_year'] ?? null) {
            $taxFor[$thisYear - 1] = $card['taxes']['last_year'];
        }
        foreach ($card['history'] as $h) {
            $vals = ['assessed_land' => Dec::norm($h['land']), 'assessed_improvement' => Dec::norm($h['improvement']), 'assessed_total' => Dec::norm($h['total']),
                'exemptions' => Dec::norm($h['exemption'] ?: '0'), 'property_class' => mb_substr($h['class'], 0, 8)];
            if (isset($taxFor[$h['year']])) {
                $vals['tax_amount'] = $taxFor[$h['year']];
            }
            $n += $this->upsertYear($property, $h['year'], $vals) ? 1 : 0;
        }

        return $n;
    }

    private function upsertYear(object $property, int $year, array $vals): bool
    {
        $row = DB::table('property_tax_years')->where('property_id', $property->id)->where('tax_year', $year)->first();
        if ($row && $row->source === 'manual') {
            $vals = array_filter($vals, fn ($v, $k) => $row->{$k} === null, ARRAY_FILTER_USE_BOTH);
            if (! $vals) {
                return false;
            }
        }
        if ($row) {
            DB::table('property_tax_years')->where('id', $row->id)->update($vals + ['source' => $row->source === 'manual' ? 'manual' : 'county_card', 'as_of' => now()->toDateString(),
                'edit_version' => $row->edit_version + 1, 'updated_at' => now()]);

            return true;
        }
        DB::table('property_tax_years')->insert($vals + ['id' => (string) Str::ulid(), 'workspace_id' => $property->workspace_id, 'scope_id' => $property->scope_id,
            'property_id' => $property->id, 'tax_year' => $year, 'source' => 'county_card', 'as_of' => now()->toDateString(),
            'created_by' => $this->ctx->user->id, 'created_at' => now(), 'updated_at' => now(), 'edit_version' => 1]);

        return true;
    }

    private function storeSales(object $property, array $sales): int
    {
        $n = 0;
        $known = DB::table('recorded_instruments')->where('property_id', $property->id)->whereNull('archived_at')->get()
            ->keyBy(fn ($r) => ltrim((string) $r->book, '0').'/'.ltrim((string) $r->page, '0'));
        foreach ($sales as $s) {
            if (! $s['book'] || ! $s['page']) {
                continue;
            }
            $existing = $known[ltrim($s['book'], '0').'/'.ltrim($s['page'], '0')] ?? null;
            $note = 'County record card SR1A'.($s['nu_code'] ? " (NU code {$s['nu_code']}: not a usable market sale)" : '').'.';
            if ($existing) {
                DB::table('recorded_instruments')->where('id', $existing->id)->update(array_filter([
                    'grantee' => $existing->grantee ?: $s['grantee'], 'consideration' => $existing->consideration ?? $s['price'],
                    'recorded_on' => $existing->recorded_on ?? $s['date'], 'updated_at' => now(),
                ], fn ($v) => $v !== null));

                continue;
            }
            DB::table('recorded_instruments')->insert(['id' => (string) Str::ulid(), 'workspace_id' => $property->workspace_id, 'scope_id' => $property->scope_id,
                'property_id' => $property->id, 'kind' => 'deed', 'recorded_on' => $s['date'], 'book' => $s['book'], 'page' => $s['page'], 'consideration' => $s['price'],
                'grantee' => $s['grantee'], 'county' => 'Monmouth', 'source' => 'county_card', 'notes' => $note,
                'created_by' => $this->ctx->user->id, 'created_at' => now(), 'updated_at' => now(), 'edit_version' => 1]);
            $n++;
        }

        return $n;
    }

    private static function kind(string $type): ?string
    {
        $t = strtoupper($type);

        return match (true) {
            str_contains($t, 'DISCHARGE') || str_contains($t, 'SATISF') || str_contains($t, 'CANCEL') => 'mortgage_satisfaction',
            str_contains($t, 'ASSIGN') => 'assignment',
            str_contains($t, 'MORTGAGE') => 'mortgage',
            str_contains($t, 'DEED') => 'deed',
            str_contains($t, 'LIS PENDENS') => 'lis_pendens',
            str_contains($t, 'EASEMENT') => 'easement',
            str_contains($t, 'LIEN') && str_contains($t, 'RELEASE') => 'lien_release',
            str_contains($t, 'LIEN') => 'lien',
            default => null,
        };
    }

    private static function hiddenFields(string $htmlDoc): array
    {
        $out = [];
        foreach (['__VIEWSTATE', '__VIEWSTATEGENERATOR', '__EVENTVALIDATION'] as $n) {
            $out[$n] = preg_match('/id="'.$n.'" value="([^"]*)"/', $htmlDoc, $m) ? html_entity_decode($m[1], ENT_QUOTES) : '';
        }

        return $out;
    }
}
