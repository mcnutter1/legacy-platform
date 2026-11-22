<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IdempotencyAndConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_mutations_require_an_idempotency_key(): void
    {
        $u = $this->makeUser('owner');
        [$ws, , $joint] = $this->makeWorkspace($u);
        $this->signIn($u)->postJson($this->wsUrl($ws, 'r/persons'), ['scope_id' => $joint->id, 'display_name' => 'A'])
            ->assertStatus(400)->assertJsonPath('error.code', 'idempotency_key_required');
    }

    public function test_replays_return_the_original_response_and_mismatches_are_rejected(): void
    {
        $u = $this->makeUser('owner');
        [$ws, , $joint] = $this->makeWorkspace($u);
        $h = ['Idempotency-Key' => 'key-123456789'];
        $first = $this->signIn($u)->send('POST', $this->wsUrl($ws, 'r/persons'), ['scope_id' => $joint->id, 'display_name' => 'A'], $h)->assertCreated();
        $second = $this->signIn($u)->send('POST', $this->wsUrl($ws, 'r/persons'), ['scope_id' => $joint->id, 'display_name' => 'A'], $h)->assertCreated();
        $second->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, DB::table('persons')->where('workspace_id', $ws->id)->count());

        $this->signIn($u)->send('POST', $this->wsUrl($ws, 'r/persons'), ['scope_id' => $joint->id, 'display_name' => 'B'], $h)
            ->assertStatus(422)->assertJsonPath('error.code', 'idempotency_mismatch');
    }

    public function test_updates_require_if_match_and_detect_stale_versions(): void
    {
        $u = $this->makeUser('owner');
        [$ws, , $joint] = $this->makeWorkspace($u);
        $id = $this->signIn($u)->send('POST', $this->wsUrl($ws, 'r/persons'), ['scope_id' => $joint->id, 'display_name' => 'A'])->json('data.id');

        $this->signIn($u)->send('PATCH', $this->wsUrl($ws, "r/persons/{$id}"), ['display_name' => 'B'])->assertStatus(428);
        $this->signIn($u)->send('PATCH', $this->wsUrl($ws, "r/persons/{$id}"), ['display_name' => 'B'], ['If-Match' => '"1"'])
            ->assertOk()->assertJsonPath('data.edit_version', 2);
        $this->signIn($u)->send('PATCH', $this->wsUrl($ws, "r/persons/{$id}"), ['display_name' => 'C'], ['If-Match' => '"1"'])
            ->assertStatus(409)->assertJsonPath('error.code', 'conflict');
        $this->assertSame('B', DB::table('persons')->where('id', $id)->value('display_name'));
    }

    public function test_validation_rejects_unknown_enum_values_and_foreign_refs(): void
    {
        $u = $this->makeUser('owner');
        $other = $this->makeUser('other');
        [$ws, , $joint] = $this->makeWorkspace($u);
        [$ws2, , $joint2] = $this->makeWorkspace($other);
        $foreign = $this->signIn($other)->send('POST', $this->wsUrl($ws2, 'r/persons'), ['scope_id' => $joint2->id, 'display_name' => 'Foreign'])->json('data.id');

        $this->signIn($u)->send('POST', $this->wsUrl($ws, 'r/accounts'), ['scope_id' => $joint->id, 'name' => 'X', 'kind' => 'not_a_kind'])->assertStatus(422);
        $this->signIn($u)->send('POST', $this->wsUrl($ws, 'r/relationships'), ['scope_id' => $joint->id, 'person_id' => $foreign, 'related_person_id' => $foreign, 'kind' => 'spouse'])
            ->assertStatus(422);
    }

    public function test_audit_chain_links_each_event_to_the_previous(): void
    {
        $u = $this->makeUser('owner');
        [$ws, , $joint] = $this->makeWorkspace($u);
        $this->signIn($u)->send('POST', $this->wsUrl($ws, 'r/persons'), ['scope_id' => $joint->id, 'display_name' => 'A'])->assertCreated();
        $rows = DB::table('audit_events')->where('workspace_id', $ws->id)->orderBy('created_at')->orderBy('id')->get(['hash', 'prev_hash']);
        $this->assertGreaterThan(1, $rows->count());
        for ($i = 1; $i < $rows->count(); $i++) {
            $this->assertSame($rows[$i - 1]->hash, $rows[$i]->prev_hash);
        }
    }
}
