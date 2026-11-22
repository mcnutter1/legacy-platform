<?php

namespace Tests\Feature;

use App\Services\VaultService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class VaultTest extends TestCase
{
    use RefreshDatabase;

    private function upload($test, $ws, string $scopeId, string $content, string $name = 'note.txt')
    {
        return $test->withHeaders(['Idempotency-Key' => (string) Str::uuid(), 'Accept' => 'application/json'])
            ->post($this->wsUrl($ws, 'vault/documents'), ['file' => UploadedFile::fake()->createWithContent($name, $content), 'scope_id' => $scopeId, 'doc_type' => 'other']);
    }

    public function test_files_are_encrypted_at_rest_quarantined_until_scanned_and_downloads_are_single_use(): void
    {
        $u = $this->makeUser('owner');
        [$ws, , $joint] = $this->makeWorkspace($u);
        $plain = 'Account 99887766 statement — confidential plaintext marker';
        $res = $this->upload($this->signIn($u), $ws, $joint->id, $plain)->assertCreated();
        $docId = $res->json('data.document_id');
        $v = DB::table('document_versions')->where('document_id', $docId)->first();

        $raw = file_get_contents(config('legacy.vault.root').'/'.$v->storage_path);
        $this->assertStringNotContainsString('confidential plaintext marker', $raw);
        $this->assertSame($plain, app(VaultService::class)->decrypt($v));

        // Quarantined: no download until scanned.
        $this->signIn($u)->send('POST', $this->wsUrl($ws, "vault/documents/{$docId}/download"))->assertStatus(409);
        app(VaultService::class)->scan($v->id);
        $url = $this->signIn($u)->send('POST', $this->wsUrl($ws, "vault/documents/{$docId}/download"))->assertOk()->json('data.url');
        $path = parse_url($url, PHP_URL_PATH);

        $dl = $this->signIn($u)->get($path)->assertOk();
        $this->assertSame($plain, $dl->getContent());
        $dl->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox");
        $this->signIn($u)->get($path)->assertNotFound();
    }

    public function test_download_tokens_are_bound_to_the_issuing_user(): void
    {
        $u = $this->makeUser('owner');
        $other = $this->makeUser('other');
        [$ws, , $joint] = $this->makeWorkspace($u);
        $docId = $this->upload($this->signIn($u), $ws, $joint->id, 'hello world file')->json('data.document_id');
        app(VaultService::class)->scan(DB::table('document_versions')->where('document_id', $docId)->value('id'));
        $url = $this->signIn($u)->send('POST', $this->wsUrl($ws, "vault/documents/{$docId}/download"))->json('data.url');

        $this->signIn($other)->get(parse_url($url, PHP_URL_PATH))->assertNotFound();
    }

    public function test_disallowed_and_duplicate_files_are_rejected(): void
    {
        $u = $this->makeUser('owner');
        [$ws, , $joint] = $this->makeWorkspace($u);
        $this->upload($this->signIn($u), $ws, $joint->id, "#!/bin/sh\necho hi\n", 'run.sh')->assertStatus(415);
        $this->upload($this->signIn($u), $ws, $joint->id, '%PDF-1.4 /Type /Page /JavaScript (app.alert(1))', 'x.pdf')->assertStatus(422);
        $this->upload($this->signIn($u), $ws, $joint->id, 'same bytes twice')->assertCreated();
        $this->upload($this->signIn($u), $ws, $joint->id, 'same bytes twice')->assertStatus(409);
    }
}
