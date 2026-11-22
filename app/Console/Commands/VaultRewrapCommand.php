<?php

namespace App\Console\Commands;

use App\Services\VaultService;
use Illuminate\Console\Command;

class VaultRewrapCommand extends Command
{
    protected $signature = 'legacy:vault-rewrap';

    protected $description = 'Rewrap document data keys under the current VAULT_ROOT_KEY_ID (key rotation)';

    public function handle(VaultService $vault): int
    {
        $n = $vault->rewrapAll();
        $this->info("Rewrapped {$n} data key(s). Remove the old key from VAULT_PREVIOUS_KEYS after verifying downloads.");

        return self::SUCCESS;
    }
}
