<?php

namespace App\Services\RealEstate;

use App\Models\Record;
use App\Support\AccessContext;
use App\Support\ApiError;
use App\Support\Audit;
use App\Support\Dec;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Address validation (US Census geocoder), New Jersey parcel/MOD-IV tax records (NJOGIS statewide composite),
 * optional licensed valuation feeds (RentCast, Zillow via Bridge), and deep links to listing sites and county clerk
 * record searches. External values are untrusted: they are normalized, length-limited and stored with provenance.
 */
final class PropertyLookupService
{
    public const NJ_CLASSES = [
        '1' => 'Vacant land', '2' => 'Residential (1–4 family)', '3A' => 'Farm (regular)', '3B' => 'Farm (qualified)', '4A' => 'Commercial',
        '4B' => 'Industrial', '4C' => 'Apartment', '5A' => 'Railroad (class I)', '5B' => 'Railroad (class II)', '6A' => 'Business personal property',
        '15A' => 'Exempt: public school', '15B' => 'Exempt: other school', '15C' => 'Exempt: public property', '15D' => 'Exempt: church/charitable',
        '15E' => 'Exempt: cemetery', '15F' => 'Exempt: other',
    ];

    private const PARCEL_FIELDS = 'PAMS_PIN,PCL_MUN,PCLBLOCK,PCLLOT,PCLQCODE,PROP_CLASS,COUNTY,MUN_NAME,PROP_LOC,OWNER_NAME,LAND_VAL,IMPRVT_VAL,'
        .'NET_VALUE,LAST_YR_TX,BLDG_DESC,LAND_DESC,CALC_ACRE,DEED_BOOK,DEED_PAGE,DEED_DATE,YR_CONSTR,SALE_PRICE,SALES_CODE,ZIP5,PCLLASTUPD';

    private const ABBREV = [
        'STREET' => 'ST', 'AVENUE' => 'AVE', 'ROAD' => 'RD', 'DRIVE' => 'DR', 'LANE' => 'LN', 'COURT' => 'CT', 'PLACE' => 'PL', 'BOULEVARD' => 'BLVD',
        'TERRACE' => 'TER', 'CIRCLE' => 'CIR', 'PARKWAY' => 'PKWY', 'HIGHWAY' => 'HWY', 'TURNPIKE' => 'TPKE', 'ROUTE' => 'RT', 'EAST' => 'E', 'WEST' => 'W',
        'NORTH' => 'N', 'SOUTH' => 'S', 'AVE.' => 'AVE', 'ST.' => 'ST',
    ];

    public function __construct(private readonly AccessContext $ctx) {}

    /** @param array{address?:string,mun_code?:string,block?:string,lot?:string,qualifier?:string} $q */
    public function lookup(array $q): array
    {
        if (! config('legacy.property_lookup.enabled')) {
            throw new ApiError('feature_disabled', 'Property lookups are turned off.', 503);
        }
        if (! $this->ctx->canAny('real_estate', 'write')) {
            throw ApiError::forbidden();
        }
        $address = trim(mb_substr((string) ($q['address'] ?? ''), 0, 200));
        $block = $this->clean($q['block'] ?? null, 16);
        $lot = $this->clean($q['lot'] ?? null, 16);
        $mun = preg_replace('/\D/', '', (string) ($q['mun_code'] ?? ''));
        if ($address === '' && ! ($mun && $block && $lot)) {
            throw new ApiError('validation_failed', 'Enter an address, or a municipality code with block and lot.', 422);
        }
        $key = 'property-lookup:v2:'.hash('sha256', json_encode([$address, $mun, $block, $lot, $q['qualifier'] ?? '']));

        return Cache::remember($key, now()->addHours((int) config('legacy.property_lookup.cache_hours')), function () use ($address, $mun, $block, $lot, $q) {
            $sources = [];
            $geo = $address !== '' ? $this->geocode($address, $sources) : null;
            $parcels = [];
            if ($mun && $block && $lot) {
                $parcels = $this->parcelsByBlockLot($mun, $block, $lot, $this->clean($q['qualifier'] ?? null, 16), $sources);
            } elseif ($geo && $geo['state'] === 'NJ') {
                $parcels = $this->parcelsNear($geo, $sources);
            } elseif ($geo) {
                $sources[] = ['name' => 'NJ parcels (MOD-IV)', 'status' => 'not_applicable', 'message' => 'Parcel and tax records are integrated for New Jersey only.'];
            }
            $full = $geo['matched'] ?? ($parcels[0] ?? null ? $parcels[0]['location'].', '.$parcels[0]['municipality'].', NJ' : $address);
            $valuations = array_values(array_filter([$this->rentcast($full, $sources), $this->zestimate($full, $sources)]));

            return [
                'query' => $address ?: "Block {$block} Lot {$lot} ({$mun})",
                'address' => $geo,
                'parcels' => $parcels,
                'valuations' => $valuations,
                'links' => $this->links($geo, $parcels[0] ?? null),
                'sources' => $sources,
                'fetched_at' => now()->toIso8601String(),
            ];
        });
    }

    /** Re-runs the (cached) lookup server-side and applies the chosen parcel; client-supplied values are never trusted. */
    public function apply(Record $property, array $q, ?string $pin): array
    {
        $res = $this->lookup($q);
        $parcel = $pin ? collect($res['parcels'])->firstWhere('pin', $pin) : null;
        if ($pin && ! $parcel) {
            throw new ApiError('validation_failed', 'That parcel is not in the lookup results.', 422);
        }
        $geo = $res['address'];
        $now = now();
        $updates = array_filter([
            'normalized_address' => $geo['matched'] ?? null,
            'latitude' => isset($geo['lat']) ? Dec::norm((string) $geo['lat'], 8) : null,
            'longitude' => isset($geo['lon']) ? Dec::norm((string) $geo['lon'], 8) : null,
            'county' => $parcel['county'] ?? ($geo['county'] ?? null),
            'municipality' => $parcel['municipality'] ?? ($geo['municipality'] ?? null),
            'mun_code' => $parcel['mun_code'] ?? null,
            'block' => $parcel['block'] ?? null,
            'lot' => $parcel['lot'] ?? null,
            'qualifier' => $parcel['qualifier'] ?? null,
            'parcel_pin' => $parcel['pin'] ?? null,
            'property_class' => $parcel['property_class'] ?? null,
            'year_built' => $parcel['year_built'] ?? null,
            'lot_acres' => isset($parcel['acres']) ? Dec::norm((string) $parcel['acres'], 4) : null,
            'assessed_land' => $parcel['assessed_land'] ?? null,
            'assessed_improvement' => $parcel['assessed_improvement'] ?? null,
            'assessed_total' => $parcel['assessed_total'] ?? null,
            'annual_property_tax' => $parcel['last_year_tax'] ?? null,
            'deed_book' => $parcel['deed_book'] ?? null,
            'deed_page' => $parcel['deed_page'] ?? null,
            'deed_date' => $parcel['deed_date'] ?? null,
            'last_sale_price' => $parcel['sale_price'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');
        $sqft = collect($res['valuations'])->pluck('details.square_feet')->filter()->first();
        if ($sqft) {
            $updates['living_area_sqft'] = (int) $sqft;
        }
        // Fill blank address fields only; the owner's own entry wins.
        foreach (['address_line' => 'line', 'city' => 'city', 'state' => 'state', 'postal_code' => 'zip'] as $col => $k) {
            if (! $property->{$col} && ! empty($geo[$k])) {
                $updates[$col] = $geo[$k];
            }
        }
        $updates += ['records_source' => $parcel ? 'NJ MOD-IV via NJOGIS parcels' : ($geo ? 'US Census geocoder' : null), 'records_refreshed_at' => $now];
        if ($geo) {
            $updates['address_verified_at'] = $now;
        }

        $created = DB::transaction(function () use ($property, $updates, $parcel, $res, $pin, $q, $now) {
            DB::table('properties')->where('id', $property->id)->update($updates + ['edit_version' => $property->edit_version + 1, 'updated_by' => $this->ctx->user->id, 'updated_at' => $now]);
            $created = [];
            if ($this->ctx->can('assets', 'write', $property->scope_id)) {
                if ($parcel && $parcel['assessed_total']) {
                    $created[] = $this->valuation($property, 'tax', $parcel['assessed_total'], null, null, 'statement', 'NJ MOD-IV tax assessment ('.$parcel['municipality'].')', 'accepted',
                        'Assessed value from the municipal tax list (land '.$parcel['assessed_land'].', improvements '.$parcel['assessed_improvement'].'). Not a market value.');
                }
                foreach ($res['valuations'] as $v) {
                    $created[] = $this->valuation($property, 'market', $v['value'], $v['low'], $v['high'], 'licensed_feed', $v['source'], 'proposed',
                        'Automated valuation model; review before accepting into net worth.');
                }
            }
            $taxes = $parcel ? (new PropertyTaxService($this->ctx))->recordFromParcel($property, $parcel) : null;
            DB::table('property_lookups')->insert([
                'id' => (string) Str::ulid(), 'workspace_id' => $property->workspace_id, 'scope_id' => $property->scope_id, 'property_id' => $property->id,
                'query' => mb_substr($res['query'], 0, 255), 'sources' => json_encode($res['sources']),
                'result' => json_encode(['address' => $res['address'], 'parcel' => $parcel, 'valuations' => $res['valuations'], 'links' => $res['links']]),
                'selected_pin' => $pin, 'created_by' => $this->ctx->user->id, 'created_at' => $now, 'updated_at' => $now, 'edit_version' => 1,
            ]);
            Audit::log('property.public_records_applied', 'property', $property->id, ['pin' => $pin, 'fields' => array_keys($updates), 'valuations' => count(array_filter($created))],
                scopeId: $property->scope_id);

            return ['valuations' => array_values(array_filter($created)), 'taxes' => $taxes];
        });

        return ['updated_fields' => array_keys($updates), 'valuations' => $created['valuations'], 'taxes' => $created['taxes']];
    }

    private function valuation(Record $p, string $purpose, string $value, ?string $low, ?string $high, string $method, string $source, string $status, string $rationale): ?string
    {
        $today = now()->toDateString();
        $dupe = DB::table('valuations')->where('workspace_id', $p->workspace_id)->where('subject_type', 'property')->where('subject_id', $p->id)
            ->where('purpose', $purpose)->where('source', $source)->where('value', $value)->whereNull('archived_at')->exists();
        if ($dupe) {
            return null;
        }
        $id = (string) Str::ulid();
        DB::table('valuations')->insert([
            'id' => $id, 'workspace_id' => $p->workspace_id, 'scope_id' => $p->scope_id, 'subject_type' => 'property', 'subject_id' => $p->id,
            'purpose' => $purpose, 'value' => $value, 'low' => $low, 'high' => $high, 'currency' => 'USD', 'as_of' => $today, 'method' => $method,
            'source' => mb_substr($source, 0, 255), 'status' => $status, 'accepted_by' => $status === 'accepted' ? $this->ctx->user->id : null,
            'accepted_at' => $status === 'accepted' ? now() : null, 'rationale' => $rationale, 'created_by' => $this->ctx->user->id,
            'created_at' => now(), 'updated_at' => now(), 'edit_version' => 1,
        ]);

        return $id;
    }

    // ---------------- Sources ----------------

    private function http(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::timeout((int) config('legacy.property_lookup.timeout'))->acceptJson()->withUserAgent('McNuttLegacy/1.0 (+https://legacy.mcnutt.cloud)');
    }

    private function geocode(string $address, array &$sources): ?array
    {
        try {
            $res = $this->http()->get((string) config('legacy.property_lookup.census_url'), [
                'address' => $address, 'benchmark' => 'Public_AR_Current', 'vintage' => 'Current_Current', 'layers' => 'Counties,County Subdivisions', 'format' => 'json',
            ]);
        } catch (ConnectionException) {
            $sources[] = ['name' => 'US Census geocoder', 'status' => 'unavailable', 'message' => 'Address service did not respond.'];

            return null;
        }
        $m = $res->json('result.addressMatches.0');
        if (! $res->successful() || ! is_array($m)) {
            $sources[] = ['name' => 'US Census geocoder', 'status' => $res->successful() ? 'no_match' : 'unavailable',
                'message' => $res->successful() ? 'Address could not be validated. Check spelling, or search by block and lot.' : 'HTTP '.$res->status()];

            return null;
        }
        $c = (array) ($m['addressComponents'] ?? []);
        $num = preg_match('/^\s*(\d+[A-Z]?)/i', $address, $mm) ? strtoupper($mm[1]) : null;
        $street = trim(implode(' ', array_filter([$c['preDirection'] ?? '', $c['preType'] ?? '', $c['streetName'] ?? '', $c['suffixType'] ?? '', $c['suffixDirection'] ?? ''])));
        $sources[] = ['name' => 'US Census geocoder', 'status' => 'ok', 'message' => 'Address matched.'];

        return [
            'matched' => $this->clean($m['matchedAddress'] ?? null, 255),
            'number' => $num,
            'street' => $this->clean($street, 120),
            'line' => $this->clean(trim(($num ? $num.' ' : '').$street), 255),
            'city' => $this->clean(isset($c['city']) ? ucwords(strtolower($c['city'])) : null, 120),
            'state' => $this->clean($c['state'] ?? null, 2),
            'zip' => $this->clean($c['zip'] ?? null, 10),
            'county' => $this->clean($m['geographies']['Counties'][0]['BASENAME'] ?? null, 32),
            'county_fips' => $this->clean(($m['geographies']['Counties'][0]['STATE'] ?? '').($m['geographies']['Counties'][0]['COUNTY'] ?? ''), 5),
            'municipality' => $this->clean($m['geographies']['County Subdivisions'][0]['NAME'] ?? null, 64),
            'lat' => isset($m['coordinates']['y']) ? (float) $m['coordinates']['y'] : null,
            'lon' => isset($m['coordinates']['x']) ? (float) $m['coordinates']['x'] : null,
        ];
    }

    private function parcelsNear(array $geo, array &$sources): array
    {
        if ($geo['lat'] === null || $geo['lon'] === null) {
            return [];
        }
        $rows = $this->parcelQuery([
            'geometry' => $geo['lon'].','.$geo['lat'], 'geometryType' => 'esriGeometryPoint', 'inSR' => 4326, 'spatialRel' => 'esriSpatialRelIntersects',
            'distance' => (int) config('legacy.property_lookup.nj_search_radius_m'), 'units' => 'esriSRUnit_Meter',
        ], $sources);
        $target = $this->normStreet(trim(($geo['number'] ?? '').' '.($geo['street'] ?? '')));
        $street = $this->normStreet((string) ($geo['street'] ?? ''));
        $num = (string) ($geo['number'] ?? '');
        $core = $street !== '' ? $this->streetCore($street) : '';
        $hit = fn (array $r) => $num !== '' && $core !== '' && str_starts_with($this->normStreet((string) $r['location']), $num.' ') && str_contains($this->normStreet((string) $r['location']), $core);
        // Geocodes are interpolated and the spatial window is finite; look the address up by text in the same municipality.
        if ($rows && ! array_filter($rows, $hit) && preg_match('/^\d+[A-Z]?$/', $num) && preg_match('/^[A-Z0-9]+$/', $core)) {
            $mun = (string) $rows[0]['mun_code'];
            $like = "PROP_LOC LIKE '{$num} %{$core}%'";
            if ($words = self::ordinalWords($core)) {
                $like = "({$like} OR PROP_LOC LIKE '{$num} %{$words}%' OR PROP_LOC LIKE '{$num} %".str_replace('-', ' ', $words)."%')";
            }
            $extra = $this->parcelQuery(['where' => "PCL_MUN='".str_replace("'", "''", $mun)."' AND {$like}"], $sources);
            $seen = array_column($rows, 'pin');
            $rows = array_merge($rows, array_filter($extra, fn ($r) => ! in_array($r['pin'], $seen, true)));
            array_pop($sources);
        }
        $dir = fn (string $s) => preg_match('/^\S+ ([NSEW]) /', $s, $m) ? $m[1] : null;
        $want = $dir($target);
        foreach ($rows as &$r) {
            $loc = $this->normStreet((string) $r['location']);
            $r['match'] = match (true) {
                $loc === $target => 'exact',
                $hit($r) && ! ($want && $dir($loc) && $dir($loc) !== $want) => 'likely',
                $core !== '' && str_contains($loc, $core) => 'same_street',
                default => 'nearby',
            };
            $r['distance_m'] = isset($r['_lat']) ? (int) round($this->meters($geo['lat'], $geo['lon'], $r['_lat'], $r['_lon'])) : null;
            // Along the same street, the closest house number beats the (interpolated) geocode distance.
            $r['_gap'] = $r['match'] === 'same_street' && ctype_digit($num) && preg_match('/^(\d+)/', $loc, $m) ? abs((int) $m[1] - (int) $num) : PHP_INT_MAX;
        }
        unset($r);
        $rank = ['exact' => 0, 'likely' => 1, 'same_street' => 2, 'nearby' => 3];
        usort($rows, fn ($a, $b) => [$rank[$a['match']], $a['_gap'], $a['distance_m'] ?? PHP_INT_MAX] <=> [$rank[$b['match']], $b['_gap'], $b['distance_m'] ?? PHP_INT_MAX]);

        return array_map(fn ($r) => array_diff_key($r, ['_lat' => 1, '_lon' => 1, '_gap' => 1]), array_slice($rows, 0, 8));
    }

    private function parcelsByBlockLot(string $mun, string $block, string $lot, ?string $qual, array &$sources): array
    {
        $q = fn ($v) => str_replace("'", "''", $v);
        $where = "PCL_MUN='{$q($mun)}' AND PCLBLOCK='{$q($block)}' AND PCLLOT='{$q($lot)}'".($qual ? " AND PCLQCODE='{$q($qual)}'" : '');
        $rows = $this->parcelQuery(['where' => $where], $sources);

        return array_map(fn ($r) => array_diff_key($r + ['match' => 'exact', 'distance_m' => null], ['_lat' => 1, '_lon' => 1]), $rows);
    }

    private function parcelQuery(array $params, array &$sources): array
    {
        try {
            $res = $this->http()->get((string) config('legacy.property_lookup.nj_parcels_url'), $params + [
                'outFields' => self::PARCEL_FIELDS, 'returnGeometry' => 'false', 'returnCentroid' => 'true', 'outSR' => 4326, 'resultRecordCount' => 400, 'f' => 'json',
            ]);
        } catch (ConnectionException) {
            $sources[] = ['name' => 'NJ parcels (MOD-IV)', 'status' => 'unavailable', 'message' => 'NJ parcel service did not respond.'];

            return [];
        }
        if (! $res->successful() || $res->json('error')) {
            $sources[] = ['name' => 'NJ parcels (MOD-IV)', 'status' => 'unavailable', 'message' => 'NJ parcel service error.'];

            return [];
        }
        $out = [];
        foreach ((array) $res->json('features', []) as $f) {
            $a = (array) ($f['attributes'] ?? []);
            if (empty($a['PAMS_PIN'])) {
                continue;
            }
            $cls = strtoupper(trim((string) ($a['PROP_CLASS'] ?? '')));
            $out[] = [
                'pin' => $this->clean($a['PAMS_PIN'], 40),
                'mun_code' => $this->clean($a['PCL_MUN'] ?? null, 8),
                'municipality' => $this->clean(isset($a['MUN_NAME']) ? ucwords(strtolower((string) $a['MUN_NAME'])) : null, 64),
                'county' => $this->clean(isset($a['COUNTY']) ? ucwords(strtolower((string) $a['COUNTY'])) : null, 32),
                'block' => $this->clean($a['PCLBLOCK'] ?? null, 16),
                'lot' => $this->clean($a['PCLLOT'] ?? null, 16),
                'qualifier' => $this->clean($a['PCLQCODE'] ?? null, 16),
                'location' => $this->clean($a['PROP_LOC'] ?? null, 120),
                'owner' => $this->clean($a['OWNER_NAME'] ?? null, 120),
                'property_class' => $cls ?: null,
                'property_class_label' => self::NJ_CLASSES[$cls] ?? null,
                'building' => $this->clean($a['BLDG_DESC'] ?? null, 60),
                'land' => $this->clean($a['LAND_DESC'] ?? null, 60),
                'acres' => isset($a['CALC_ACRE']) ? round((float) $a['CALC_ACRE'], 4) : null,
                'assessed_land' => $this->money($a['LAND_VAL'] ?? null),
                'assessed_improvement' => $this->money($a['IMPRVT_VAL'] ?? null),
                'assessed_total' => $this->money($a['NET_VALUE'] ?? null),
                'last_year_tax' => $this->money($a['LAST_YR_TX'] ?? null),
                'deed_book' => $this->clean($a['DEED_BOOK'] ?? null, 16),
                'deed_page' => $this->clean($a['DEED_PAGE'] ?? null, 16),
                'deed_date' => $this->deedDate($a['DEED_DATE'] ?? null),
                'sale_price' => (int) ($a['SALE_PRICE'] ?? 0) > 100 ? $this->money($a['SALE_PRICE']) : null,
                'sales_code' => $this->clean($a['SALES_CODE'] ?? null, 8),
                'year_built' => (int) ($a['YR_CONSTR'] ?? 0) >= 1700 ? (int) $a['YR_CONSTR'] : null,
                'zip' => $this->clean($a['ZIP5'] ?? null, 10),
                'records_as_of' => isset($a['PCLLASTUPD']) && is_numeric($a['PCLLASTUPD']) ? date('Y-m-d', (int) ($a['PCLLASTUPD'] / 1000)) : null,
                '_lat' => isset($f['centroid']['y']) ? (float) $f['centroid']['y'] : null,
                '_lon' => isset($f['centroid']['x']) ? (float) $f['centroid']['x'] : null,
            ];
        }
        $sources[] = ['name' => 'NJ parcels (MOD-IV)', 'status' => $out ? 'ok' : 'no_match',
            'message' => $out ? count($out).' parcel(s) found.' : 'No parcel found. Try block and lot from your tax bill.'];

        return $out;
    }

    private function rentcast(?string $address, array &$sources): ?array
    {
        $key = (string) config('legacy.property_lookup.rentcast_api_key');
        if ($key === '' || ! $address) {
            $sources[] = ['name' => 'RentCast AVM', 'status' => 'not_configured', 'message' => 'Set RENTCAST_API_KEY to add automated value estimates.'];

            return null;
        }
        try {
            $res = $this->http()->withHeaders(['X-Api-Key' => $key])->get((string) config('legacy.property_lookup.rentcast_url'), ['address' => $address, 'compCount' => 10]);
        } catch (ConnectionException) {
            $sources[] = ['name' => 'RentCast AVM', 'status' => 'unavailable', 'message' => 'Valuation service did not respond.'];

            return null;
        }
        $price = $res->json('price');
        if (! $res->successful() || ! is_numeric($price)) {
            $sources[] = ['name' => 'RentCast AVM', 'status' => $res->status() === 404 ? 'no_match' : 'unavailable', 'message' => 'No estimate (HTTP '.$res->status().').'];

            return null;
        }
        $s = (array) $res->json('subjectProperty', []);
        $sources[] = ['name' => 'RentCast AVM', 'status' => 'ok', 'message' => 'Estimate received.'];

        return [
            'source' => 'RentCast AVM', 'value' => $this->money($price), 'low' => $this->money($res->json('priceRangeLow')), 'high' => $this->money($res->json('priceRangeHigh')),
            'as_of' => now()->toDateString(), 'url' => null,
            'details' => array_filter(['bedrooms' => $s['bedrooms'] ?? null, 'bathrooms' => $s['bathrooms'] ?? null, 'square_feet' => $s['squareFootage'] ?? null,
                'lot_size_sqft' => $s['lotSize'] ?? null, 'year_built' => $s['yearBuilt'] ?? null, 'property_type' => $this->clean($s['propertyType'] ?? null, 40),
                'last_sale_date' => isset($s['lastSaleDate']) ? substr((string) $s['lastSaleDate'], 0, 10) : null, 'last_sale_price' => $s['lastSalePrice'] ?? null,
                'comparables' => count((array) $res->json('comparables', []))], fn ($v) => $v !== null),
        ];
    }

    private function zestimate(?string $address, array &$sources): ?array
    {
        $token = (string) config('legacy.property_lookup.bridge_token');
        if ($token === '' || ! $address) {
            $sources[] = ['name' => 'Zillow Zestimate', 'status' => 'not_configured',
                'message' => 'Zillow values require a Zillow Bridge API agreement (BRIDGE_ZESTIMATE_TOKEN). Use the Zillow link meanwhile.'];

            return null;
        }
        try {
            $res = $this->http()->get((string) config('legacy.property_lookup.bridge_url'), ['access_token' => $token, 'address' => $address]);
        } catch (ConnectionException) {
            $sources[] = ['name' => 'Zillow Zestimate', 'status' => 'unavailable', 'message' => 'Zillow Bridge did not respond.'];

            return null;
        }
        $z = $res->json('bundle.0');
        if (! $res->successful() || ! is_array($z) || ! is_numeric($z['zestimate'] ?? null)) {
            $sources[] = ['name' => 'Zillow Zestimate', 'status' => $res->successful() ? 'no_match' : 'unavailable', 'message' => 'No Zestimate (HTTP '.$res->status().').'];

            return null;
        }
        $v = (float) $z['zestimate'];
        $low = is_numeric($z['lowPercent'] ?? null) ? $v * (1 - $z['lowPercent'] / 100) : null;
        $high = is_numeric($z['highPercent'] ?? null) ? $v * (1 + $z['highPercent'] / 100) : null;
        $url = is_string($z['zillowUrl'] ?? null) && str_starts_with($z['zillowUrl'], 'https://www.zillow.com/') ? $z['zillowUrl'] : null;
        $sources[] = ['name' => 'Zillow Zestimate', 'status' => 'ok', 'message' => 'Zestimate received.'];

        return ['source' => 'Zillow Zestimate (Bridge)', 'value' => $this->money($v), 'low' => $this->money($low), 'high' => $this->money($high),
            'as_of' => isset($z['timestamp']) ? substr((string) $z['timestamp'], 0, 10) : now()->toDateString(), 'url' => $url, 'details' => []];
    }

    /**
     * Comparable-sales indication from the public tax list: recent usable sales of nearby same-class parcels,
     * expressed as sale price ÷ current assessment and applied to the subject's assessment. Deterministic, sourced, and
     * only as fresh as the MOD-IV sales feed (which can lag closings by a year or more).
     */
    public function comps(Record $p): array
    {
        if (! $this->ctx->can('real_estate', 'read', $p->scope_id)) {
            throw ApiError::notFound();
        }
        if (! $p->mun_code || ! $p->latitude || ! $p->longitude || ! $p->assessed_total) {
            throw ApiError::unsupported('Look up public records for this property first (needs the parcel location and assessment).');
        }
        $years = 3;
        $cutoff = now()->subYears($years)->toDateString();
        $class = $p->property_class ?: '2';
        $key = 'property-comps:'.hash('sha256', implode('|', [$p->mun_code, $class, $p->latitude, $p->longitude]));
        $sources = [];
        $rows = Cache::remember($key, now()->addHours((int) config('legacy.property_lookup.cache_hours')), fn () => $this->parcelQuery([
            'where' => "PCL_MUN='".preg_replace('/\D/', '', $p->mun_code)."' AND PROP_CLASS='".preg_replace('/[^0-9A-Z]/', '', strtoupper($class))."' AND SALE_PRICE>100000",
            'geometry' => $p->longitude.','.$p->latitude, 'geometryType' => 'esriGeometryPoint', 'inSR' => 4326, 'spatialRel' => 'esriSpatialRelIntersects',
            'distance' => 1600, 'units' => 'esriSRUnit_Meter',
        ], $sources));
        $subjectAssessed = Dec::norm($p->assessed_total);
        $comps = [];
        foreach ($rows as $r) {
            if ($r['pin'] === $p->parcel_pin || ! $r['sale_price'] || ! $r['assessed_total'] || ! $r['deed_date'] || $r['deed_date'] < $cutoff || ! empty($r['sales_code'])) {
                continue;
            }
            $ratio = (float) $r['sale_price'] / max(1.0, (float) $r['assessed_total']);
            // Outside 0.5–2.0 is almost always a non-arm's-length transfer or a since-improved lot.
            if ($ratio < 0.5 || $ratio > 2.0) {
                continue;
            }
            $comps[] = ['location' => $r['location'], 'block' => $r['block'], 'lot' => $r['lot'], 'sale_date' => $r['deed_date'], 'sale_price' => $r['sale_price'],
                'assessed_total' => $r['assessed_total'], 'ratio' => round($ratio, 4), 'year_built' => $r['year_built'],
                'distance_m' => isset($r['_lat']) ? (int) round($this->meters((float) $p->latitude, (float) $p->longitude, $r['_lat'], $r['_lon'])) : null];
        }
        usort($comps, fn ($a, $b) => [$b['sale_date'], $a['distance_m']] <=> [$a['sale_date'], $b['distance_m']]);
        $comps = array_slice($comps, 0, 15);
        $out = ['subject' => ['assessed_total' => $subjectAssessed, 'property_class' => $class], 'window_years' => $years, 'comps' => $comps, 'sources' => $sources,
            'method' => 'Median of (sale price ÷ current assessment) for usable sales within 1.6 km in the last '.$years.' years, times this property’s assessment.'];
        if (count($comps) < 3) {
            return $out + ['status' => 'insufficient', 'message' => 'Fewer than three recent usable sales nearby in the public tax list.'];
        }
        $ratios = array_column($comps, 'ratio');
        sort($ratios);
        $q = fn (float $f, bool $up = false) => $ratios[(int) ($up ? ceil($f * (count($ratios) - 1)) : floor($f * (count($ratios) - 1)))];
        $value = fn (float $r) => Dec::norm(Dec::round(Dec::mul(Dec::div($subjectAssessed, '1000', 8), (string) $r, 8), 0).'000');

        return $out + ['status' => 'ok', 'median_ratio' => $q(0.5), 'value' => $value($q(0.5)), 'low' => $value($q(0.25)), 'high' => $value($q(0.75, true)),
            'newest_sale' => $comps[0]['sale_date']];
    }

    /** Listing sites and county clerks have no public API (and block automated access), so the app links out. */
    private function links(?array $geo, ?array $parcel): array
    {
        $links = [];
        $addr = $geo['matched'] ?? null;
        if ($addr) {
            $links[] = ['kind' => 'listing', 'label' => 'Zillow', 'url' => 'https://www.zillow.com/homes/'.trim(preg_replace('/[^A-Za-z0-9]+/', '-', $addr), '-').'_rb/'];
            if (! empty($geo['zip'])) {
                $links[] = ['kind' => 'listing', 'label' => 'Realtor.com (ZIP '.$geo['zip'].')', 'url' => 'https://www.realtor.com/realestateandhomes-search/'.rawurlencode($geo['zip'])];
            }
        }
        $county = strtoupper((string) ($parcel['county'] ?? $geo['county'] ?? ''));
        if (($parcel['mun_code'] ?? null) || in_array($county, ['MONMOUTH', 'OCEAN'], true)) {
            $links[] = ['kind' => 'tax', 'label' => 'NJ assessment records (MOD-IV)', 'url' => 'https://tax1.co.monmouth.nj.us/cgi-bin/prc6.cgi?&ms_user=monm&passwd=&srch_type=0&adv=0&out_type=0'
                .(! empty($parcel['mun_code']) ? '&district='.rawurlencode($parcel['mun_code']) : '')];
        }
        if ($county === 'MONMOUTH') {
            if (! empty($parcel['mun_code']) && ! empty($parcel['block']) && ! empty($parcel['lot']) && ($key = self::monmouthCardKey($parcel['mun_code'], $parcel['block'], $parcel['lot'], $parcel['qualifier'] ?? null))) {
                $links[] = ['kind' => 'tax', 'label' => 'Property record card & tax list history', 'url' => 'https://tax1.co.monmouth.nj.us/cgi-bin/m4.cgi?district='.rawurlencode($parcel['mun_code']).'&l02='.$key];
            }
            $links[] = ['kind' => 'clerk', 'label' => 'Monmouth County Clerk OPRS (deeds & mortgages)', 'url' => 'https://oprs.co.monmouth.nj.us/oprs/'];
        } elseif ($county === 'OCEAN') {
            $links[] = ['kind' => 'tax', 'label' => 'Ocean County Board of Taxation tax list search', 'url' => 'https://tax.co.ocean.nj.us/frmTaxBoardTaxListSearch'];
            $links[] = ['kind' => 'clerk', 'label' => 'Ocean County Clerk public records search (deeds & mortgages)', 'url' => 'http://sng.co.ocean.nj.us/searchapplication/'];
        }

        return $links;
    }

    /** Record-card key used by the county tax board site: district, 5-digit block/lot with right-aligned suffixes, 5-char qualifier. */
    public static function monmouthCardKey(string $mun, string $block, string $lot, ?string $qual): ?string
    {
        $part = function (string $v): ?string {
            if (! preg_match('/^(\d{1,5})(?:\.(\d{1,4}))?$/', trim($v), $m)) {
                return null;
            }

            return str_pad($m[1], 5, '0', STR_PAD_LEFT).str_pad($m[2] ?? '', 4, '_', STR_PAD_LEFT);
        };
        $b = $part($block);
        $l = $part($lot);
        $q = strtoupper(trim((string) $qual));
        if (! preg_match('/^\d{4}$/', $mun) || ! $b || ! $l || ! preg_match('/^[A-Z0-9.\-]{0,5}$/', $q)) {
            return null;
        }

        return $mun.$b.$l.str_pad($q, 5, '_').'M';
    }

    // ---------------- Helpers ----------------

    private function clean(mixed $v, int $max): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $v));

        return $s === '' ? null : mb_substr($s, 0, $max);
    }

    private function money(mixed $v): ?string
    {
        return is_numeric($v) && (float) $v > 0 ? Dec::norm(number_format((float) $v, 2, '.', '')) : null;
    }

    private function deedDate(mixed $v): ?string
    {
        if (! is_string($v) || ! preg_match('/^(\d{2})(\d{2})(\d{2})$/', $v, $m)) {
            return null;
        }
        $yy = (int) $m[1];
        $year = $yy > ((int) date('y') + 1) ? 1900 + $yy : 2000 + $yy;

        return checkdate((int) $m[2], (int) $m[3], $year) ? sprintf('%04d-%02d-%02d', $year, $m[2], $m[3]) : null;
    }

    private const UNIT_ORD = ['FIRST' => 1, 'SECOND' => 2, 'THIRD' => 3, 'FOURTH' => 4, 'FIFTH' => 5, 'SIXTH' => 6, 'SEVENTH' => 7, 'EIGHTH' => 8, 'NINTH' => 9,
        'TENTH' => 10, 'ELEVENTH' => 11, 'TWELFTH' => 12, 'THIRTEENTH' => 13, 'FOURTEENTH' => 14, 'FIFTEENTH' => 15, 'SIXTEENTH' => 16, 'SEVENTEENTH' => 17,
        'EIGHTEENTH' => 18, 'NINETEENTH' => 19, 'TWENTIETH' => 20, 'THIRTIETH' => 30, 'FORTIETH' => 40, 'FIFTIETH' => 50, 'SIXTIETH' => 60, 'SEVENTIETH' => 70,
        'EIGHTIETH' => 80, 'NINETIETH' => 90];

    private const TENS = ['TWENTY' => 20, 'THIRTY' => 30, 'FORTY' => 40, 'FIFTY' => 50, 'SIXTY' => 60, 'SEVENTY' => 70, 'EIGHTY' => 80, 'NINETY' => 90];

    /** Tax lists often spell ordinal streets ("TWENTY-FOURTH AVE"); geocoders use digits ("24TH AVE"). Both normalize to digits. */
    private function normStreet(string $s): string
    {
        $words = preg_split('/\s+/', strtoupper(preg_replace('/[^A-Za-z0-9 ]+/', ' ', $s)), -1, PREG_SPLIT_NO_EMPTY);
        $out = [];
        for ($i = 0; $i < count($words); $i++) {
            $w = $words[$i];
            $n = null;
            if (isset(self::TENS[$w]) && isset(self::UNIT_ORD[$words[$i + 1] ?? '']) && self::UNIT_ORD[$words[$i + 1]] < 10) {
                $n = self::TENS[$w] + self::UNIT_ORD[$words[++$i]];
            } elseif (isset(self::UNIT_ORD[$w])) {
                $n = self::UNIT_ORD[$w];
            }
            $out[] = $n !== null ? self::ordinal($n) : (self::ABBREV[$w] ?? $w);
        }

        return implode(' ', $out);
    }

    private static function ordinal(int $n): string
    {
        $suffix = in_array($n % 100, [11, 12, 13], true) ? 'TH' : (['TH', 'ST', 'ND', 'RD'][$n % 10] ?? 'TH');

        return $n.$suffix;
    }

    /** "24TH" → "TWENTY-FOURTH" for text searches against tax lists. */
    private static function ordinalWords(string $core): ?string
    {
        if (! preg_match('/^(\d{1,2})(ST|ND|RD|TH)$/', $core, $m)) {
            return null;
        }
        $n = (int) $m[1];
        $units = array_flip(array_filter(self::UNIT_ORD, fn ($v) => $v < 20));
        if ($n < 20) {
            return $units[$n] ?? null;
        }
        $tensOrd = array_flip(array_filter(self::UNIT_ORD, fn ($v) => $v >= 20));
        $tens = array_flip(self::TENS);

        return $n % 10 === 0 ? ($tensOrd[$n] ?? null) : (isset($tens[$n - $n % 10], $units[$n % 10]) ? $tens[$n - $n % 10].'-'.$units[$n % 10] : null);
    }

    private function streetCore(string $normStreet): string
    {
        $parts = array_values(array_filter(explode(' ', $normStreet), fn ($w) => ! in_array($w, ['N', 'S', 'E', 'W', 'ST', 'AVE', 'RD', 'DR', 'LN', 'CT', 'PL', 'BLVD', 'TER', 'CIR', 'PKWY', 'HWY'], true)));

        return $parts[0] ?? $normStreet;
    }

    private function meters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $r = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return 2 * $r * asin(min(1, sqrt($a)));
    }
}
