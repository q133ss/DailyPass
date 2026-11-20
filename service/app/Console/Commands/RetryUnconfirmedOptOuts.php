<?php

namespace App\Console\Commands;

use App\Services\StopService;
use Illuminate\Console\Command;

final class RetryUnconfirmedOptOuts extends Command
{
    protected $signature = 'subscriptions:retry-optouts';

    protected $description = 'Retry opt-outs that were not confirmed by the carrier';

    public function handle(StopService $stops): int
    {
        $stats = $stops->retryUnconfirmed();

        $this->table(['Metric', 'Value'], [
            ['Checked', $stats['checked']],
            ['Confirmed', $stats['confirmed']],
            ['Still unconfirmed', $stats['stillUnconfirmed']],
        ]);

        return self::SUCCESS;
    }
}
