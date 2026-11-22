<?php

namespace Tests\Feature;

use App\Services\Notifications\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** notifications.mcnutt.cloud contract: POST /api/send.php, Bearer token, {channel,to,from,subject,body} → {queued,id}. */
class NotificationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_queued_delivery_is_sent_with_the_documented_contract_and_marked_accepted_not_delivered(): void
    {
        config(['legacy.notifications.driver' => 'mcnutt', 'legacy.notifications.api_token' => 'test-token']);
        Http::fake(['notifications.mcnutt.cloud/api/send.php' => Http::response(['queued' => true, 'id' => 42])]);
        $owner = $this->makeUser('owner');
        [$ws, $m] = $this->makeWorkspace($owner);
        $svc = app(NotificationService::class);
        $this->assertSame(1, $svc->notify($ws->id, [$m->id], 'test', 'Estimated tax due soon', 'Open the app for details.', '/tax', 'k1'));
        $this->assertSame(0, $svc->notify($ws->id, [$m->id], 'test', 'Estimated tax due soon', null, '/tax', 'k1'), 'dedup key');

        $stats = $svc->dispatch();
        $this->assertSame(1, $stats['accepted']);
        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer test-token') && $r['channel'] === 'email'
            && $r['to'] === 'owner@example.test' && $r['subject'] === 'Estimated tax due soon' && str_contains($r['body'], 'Open the app'));
        $row = \DB::table('notification_deliveries')->first();
        $this->assertSame(['accepted', '42'], [$row->status, (string) $row->provider_message_id]);
    }

    public function test_rejections_fail_and_server_errors_retry(): void
    {
        config(['legacy.notifications.driver' => 'mcnutt', 'legacy.notifications.api_token' => 'test-token']);
        Http::fakeSequence('notifications.mcnutt.cloud/*')->push(['error' => 'invalid_from_address'], 400)->push('oops', 503);
        $owner = $this->makeUser('owner');
        [$ws, $m] = $this->makeWorkspace($owner);
        $svc = app(NotificationService::class);
        $svc->notify($ws->id, [$m->id], 'test', 'A', null, null, 'a');
        $svc->notify($ws->id, [$m->id], 'test', 'B', null, null, 'b');
        $stats = $svc->dispatch();
        $this->assertSame([1, 1], [$stats['failed'], $stats['retry']]);
    }
}
