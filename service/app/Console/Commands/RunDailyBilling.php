<?php

namespace App\Console\Commands;

use App\Models\Subscriber;
use App\Services\BillingService;
use App\Support\BillingDay;
use Illuminate\Console\Command;
use Throwable;

final class RunDailyBilling extends Command
{
    protected $signature = 'billing:run
                            {--date= : Billing day in YYYY-MM-DD (billing timezone)}
                            {--subscriber= : Limit the run to one MSISDN or subscriberId}';

    protected $description = 'Create and process daily charge operations for one billing day';

    public function handle(BillingService $billing): int
    {
        try {
            $day = $this->option('date') !== null
                ? BillingDay::fromDateString((string) $this->option('date'))
                : BillingDay::now();
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $only = null;
        if ($this->option('subscriber') !== null) {
            $value = (string) $this->option('subscriber');
            $only = Subscriber::query()
                ->where('msisdn', $value)
                ->orWhere('subscriber_id', $value)
                ->first();

            if ($only === null) {
                $this->error('Subscriber not found: '.$value);

                return self::FAILURE;
            }
        }

        try {
            $stats = $billing->run($day, $only);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->table(['Metric', 'Value'], [
            ['Billing day', $stats['billingDay']],
            ['Timezone', $stats['timezone']],
            ['Amount', $stats['amount'].' '.$stats['currency']],
            ['Operations created', $stats['created']],
            ['Succeeded', $stats['succeeded']],
            ['Retry scheduled', $stats['retryScheduled']],
            ['Failed', $stats['failed']],
            ['Unknown / review', $stats['unknown']],
            ['Cancelled by STOP', $stats['cancelled']],
            ['Expired previous day', $stats['expiredOld']],
            ['Expired at boundary', $stats['expiredAtBoundary']],
            ['Skipped', $stats['skipped']],
        ]);

        return self::SUCCESS;
    }
}
