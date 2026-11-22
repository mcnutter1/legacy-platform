<?php

namespace Tests\Unit;

use App\Services\Reports\ReportService;
use App\Support\Audit;
use App\Support\Dec;
use Tests\TestCase;

class SafetyHelpersTest extends TestCase
{
    public function test_csv_cells_neutralize_formula_injection_but_keep_numbers(): void
    {
        $r = app(ReportService::class);
        $this->assertSame("'=HYPERLINK(\"http://x\")", $r->cell('=HYPERLINK("http://x")'));
        $this->assertSame("'+1+1", $r->cell('+1+1'));
        $this->assertSame("'@SUM(A1)", $r->cell('@SUM(A1)'));
        $this->assertSame("'-cmd|' /C calc'!A0", $r->cell("-cmd|' /C calc'!A0"));
        $this->assertSame('-125.50', $r->cell('-125.50'));
        $this->assertSame('42', $r->cell(42));
        $csv = $r->csv([['name' => '=evil()', 'amount' => '10.00']]);
        $this->assertStringContainsString("'=evil()", $csv);
    }

    public function test_audit_metadata_redacts_sensitive_keys(): void
    {
        $out = Audit::sanitize(['token' => 'abc', 'amount' => '100', 'nested' => ['client_secret' => 'x', 'ok' => 'y'], 'status' => 'fine']);
        $this->assertSame('[redacted]', $out['token']);
        $this->assertSame('[redacted]', $out['amount']);
        $this->assertSame('[redacted]', $out['nested']['client_secret']);
        $this->assertSame('y', $out['nested']['ok']);
        $this->assertSame('fine', $out['status']);
    }

    public function test_decimal_math_is_exact(): void
    {
        $this->assertSame('0.3000', Dec::add('0.1', '0.2'));
        $this->assertSame('-5.0000', Dec::norm('-5'));
    }
}
