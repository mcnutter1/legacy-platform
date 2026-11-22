<?php

namespace App\Console\Commands;

use App\Services\Notifications\NotificationService;
use Illuminate\Console\Command;

class NotificationsTestCommand extends Command
{
    protected $signature = 'legacy:notifications-test {to : email address to receive a generic test message}';

    protected $description = 'Send one generic test email through notifications.mcnutt.cloud and report the provider response';

    public function handle(NotificationService $svc): int
    {
        $to = (string) $this->argument('to');
        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->error('Invalid email address.');

            return self::FAILURE;
        }
        $res = $svc->sendTest($to);
        $this->line(json_encode($res));

        return ($res['accepted'] ?? false) ? self::SUCCESS : self::FAILURE;
    }
}
