<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** A debt linked to an account takes its balance from the account instead of asking for it. */
class LiabilityBalanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_linked_debt_reads_balance_from_its_account(): void
    {
        $u = $this->makeUser('owner');
        [$ws, , $joint] = $this->makeWorkspace($u);
        $this->signIn($u);
        $card = $this->send('POST', $this->wsUrl($ws, 'r/accounts'), ['scope_id' => $joint->id, 'name' => 'Example Card', 'kind' => 'credit_card', 'is_liability' => true])->json('data.id');
        $this->send('POST', $this->wsUrl($ws, 'r/balance_snapshots'), ['scope_id' => $joint->id, 'account_id' => $card, 'as_of' => '2026-11-20', 'balance' => '-420.50'])->assertCreated();

        $this->send('POST', $this->wsUrl($ws, 'r/liabilities'), ['scope_id' => $joint->id, 'name' => 'Unlinked loan', 'kind' => 'personal_loc'])
            ->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['current_balance']]]);
        $debt = $this->send('POST', $this->wsUrl($ws, 'r/liabilities'), ['scope_id' => $joint->id, 'name' => 'Card debt', 'kind' => 'credit_card', 'account_id' => $card])
            ->assertCreated()->json('data.id');
        $row = DB::table('liabilities')->find($debt);
        $this->assertSame(['420.5', '2026-11-20'], [rtrim(rtrim($row->current_balance, '0'), '.'), $row->balance_as_of]);

        // A newer statement balance flows through.
        $this->send('POST', $this->wsUrl($ws, 'r/balance_snapshots'), ['scope_id' => $joint->id, 'account_id' => $card, 'as_of' => '2026-11-21', 'balance' => '-610.00'])->assertCreated();
        $this->assertEquals(610, (float) DB::table('liabilities')->where('id', $debt)->value('current_balance'));

        $meta = collect($this->getJson('/api/v1/meta')->json('data.resources.liabilities.fields'))->keyBy('name');
        $this->assertSame(['account_id', 'account_id'], [$meta['current_balance']['derived_from'], $meta['current_balance']['required_without']]);
    }
}
