<?php

namespace Tests\Feature;

use App\Jobs\JobService;
use App\Jobs\RunnerClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Monmouth tax-board record card + County Clerk OPRS index, parsed from fictional fixtures. */
class CountyRecordsTest extends TestCase
{
    use RefreshDatabase;

    private const CARD = <<<'HTML'
<table><tr><td nowrap> <font size=2 face=verdana color=BLACK> Taxes: </font> </td> <td nowrap> <font size=2 face=verdana color=FIREBRICK> 20100.50 / 20400.75 </font> </td></tr></table>
<table><tr> <td bgcolor=#3055A3 align=center> <font color=WHITE face=verdana size=2> Sr1a </td> <td> <font> Date </td> <td>Book</td><td>Page</td><td>Price</td><td>NU#</td><td>Ratio</td><td>Grantee</td> </tr>
<tr> <td nowrap> <font> <a href="sr.cgi?x=1"> More Info </a> </font> </td> <td nowrap> <font> 06/14/19 </font> </td> <td nowrap> <font> 9100 </font> </td> <td nowrap> <font> 77 </font> </td> <td nowrap> <font> 690000 </font> </td> <td nowrap> <font> </font> </td> <td nowrap> <font> 92.10 </font> </td> <td nowrap> <font> EXAMPLE, ALEX &amp; EXAMPLE, JORDAN </font> </td> </tr>
<tr> <td nowrap> <font> <a href="sr.cgi?x=2"> More Info </a> </font> </td> <td nowrap> <font> 03/01/12 </font> </td> <td nowrap> <font> 8800 </font> </td> <td nowrap> <font> 12 </font> </td> <td nowrap> <font> 1 </font> </td> <td nowrap> <font> 10 </font> </td> <td nowrap> <font> 0.01 </font> </td> <td nowrap> <font> SAMPLE BUILDER LLC </font> </td> </tr></table>
<table><tr><td colspan=8> <font> TAX-LIST-HISTORY </font> </td></tr>
<tr><td>Year</td><td>Property Location</td><td>Land/Imp/Tot</td><td>Exemption</td><td>Assessed</td><td>Property Class</td></tr>
<tr> <td nowrap> <font>2026</font> </td> <td nowrap><font> &nbsp &nbsp 12 EXAMPLE LANE </font> </td> <td align=right><font> 300000 </font> </td> <td align=right><font> 0 &nbsp</font> </td> <td align=right><font> 900000 </font> </td> <td align=center><font> 2 </font> </td> </tr>
<tr> <td>&nbsp</td> <td>&nbsp</td> <td align=right><font> 600000 </font> </td> <td>&nbsp</td> <td>&nbsp</td> </tr>
<tr> <td>&nbsp</td> <td>&nbsp</td> <td align=right><font> 900000 </font> </td> <td>&nbsp</td> <td>&nbsp</td> </tr>
<tr> <td nowrap> <a href="javascript:taxlist_win(2025);">2025</a> </td> <td nowrap><font> 12 EXAMPLE LANE </font> </td> <td align=right><font> 280000 </font> </td> <td align=right><font> 0 </font> </td> <td align=right><font> 850000 </font> </td> <td align=center><font> 2 </font> </td> </tr>
<tr> <td>&nbsp</td> <td>&nbsp</td> <td align=right><font> 570000 </font> </td> </tr> <tr> <td>&nbsp</td> <td>&nbsp</td> <td align=right><font> 850000 </font> </td> </tr>
</table>
HTML;

    private const OPRS_HOME = '<form><input type="hidden" name="__VIEWSTATE" id="__VIEWSTATE" value="vs1" /><input type="hidden" name="__EVENTVALIDATION" id="__EVENTVALIDATION" value="ev1" /></form>';

    private const OPRS_RESULT = '<table><tr class="itemstyle"><td>DEEDS From 10/01/1996 to Present</td><td> SAMPLE BUILDER LLC </td><td> EXAMPLE ALEX </td><td width="100">2019055501</td><td> 7/2/2019 </td><td>EXAMPLE TOWN</td><td>7</td><td>2</td><td>OR-9100</td><td>77</td><td><input type="image" /></td></tr>'
        .'<tr class="altitemstyle"><td>DEEDS From 10/01/1996 to Present</td><td> SAMPLE BUILDER LLC </td><td> EXAMPLE JORDAN </td><td width="100">2019055501</td><td> 7/2/2019 </td><td>EXAMPLE TOWN</td><td>7</td><td>2</td><td>OR-9100</td><td>77</td><td></td></tr></table>';

    public function test_card_history_taxes_sales_and_oprs_index_are_imported(): void
    {
        Http::fake(function (Request $r) {
            if (str_contains($r->url(), 'tax1.co.monmouth.nj.us/cgi-bin/m4.cgi')) {
                return Http::response(self::CARD);
            }
            if (str_contains($r->url(), 'oprs.co.monmouth.nj.us')) {
                if ($r->method() === 'GET') {
                    return Http::response(self::OPRS_HOME);
                }
                $d = $r->data();

                return Http::response(($d['ctl00$ContentPlaceHolder1$txtBookNumberTab3'] ?? '') === '9100' && ($d['__VIEWSTATE'] ?? '') === 'vs1' ? self::OPRS_RESULT : '<p>No results found</p>');
            }

            return Http::response('', 404);
        });
        $u = $this->makeUser('owner');
        [$ws, , $joint] = $this->makeWorkspace($u);
        $id = $this->signIn($u)->send('POST', $this->wsUrl($ws, 'r/properties'), ['scope_id' => $joint->id, 'name' => 'Home', 'kind' => 'primary'])->json('data.id');
        $this->send('POST', $this->wsUrl($ws, "properties/{$id}/county-records"))->assertStatus(422);
        DB::table('properties')->where('id', $id)->update(['county' => 'Monmouth', 'mun_code' => '1351', 'block' => '7', 'lot' => '2']);
        $this->send('POST', $this->wsUrl($ws, "properties/{$id}/county-records"))->assertStatus(202);
        $jobs = app(JobService::class);
        while ($job = $jobs->claim('bulk')) {
            $jobs->execute($job, app(RunnerClient::class));
        }
        $this->assertSame('succeeded', DB::table('compute_jobs')->value('status'), (string) DB::table('compute_jobs')->value('error'));

        $y = (int) now()->format('Y');
        $years = DB::table('property_tax_years')->where('property_id', $id)->orderBy('tax_year')->get()->keyBy('tax_year');
        $this->assertEquals([850000, 900000, 600000], [(float) $years[2025]->assessed_total, (float) $years[2026]->assessed_total, (float) $years[2026]->assessed_improvement]);
        $this->assertSame('county_card', $years[2026]->source);
        $this->assertEquals(20400.75, (float) $years[$y]->tax_amount);

        $deeds = DB::table('recorded_instruments')->where('property_id', $id)->orderBy('recorded_on')->get();
        $this->assertCount(2, $deeds);
        $this->assertStringContainsString('NU code 10', $deeds[0]->notes);
        $d = $deeds[1];
        $this->assertSame(['9100', '77', '2019055501', '2019-07-02', 'deed'], [$d->book, $d->page, $d->instrument_number, $d->recorded_on, $d->kind]);
        $this->assertEquals(690000, (float) $d->consideration);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'tax1.co.monmouth.nj.us') && str_starts_with($r->header('User-Agent')[0] ?? '', 'McNuttLegacy/'));

        // Re-running enriches in place; nothing duplicates.
        $this->send('POST', $this->wsUrl($ws, "properties/{$id}/county-records"))->assertStatus(202);
        while ($job = $jobs->claim('bulk')) {
            $jobs->execute($job, app(RunnerClient::class));
        }
        $this->assertSame([2, 2], [DB::table('recorded_instruments')->count(), DB::table('property_tax_years')->where('property_id', $id)->whereIn('tax_year', [2025, 2026])->count()]);
    }
}
