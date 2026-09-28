<?php

namespace App\Console\Commands;

use App\Actions\Revenue\RecognizeRevenue;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use Illuminate\Console\Command;

class RecognizeRevenueCommand extends Command
{
    protected $signature = 'revenue:recognize';

    protected $description = 'Post earning entries for every subscription period that has started';

    public function handle(RecognizeRevenue $recognize): int
    {
        $posted = $recognize->handle();

        $expired = Subscription::query()
            ->where('status', SubscriptionStatus::Active)
            ->where('ends_at', '<=', now())
            ->update(['status' => SubscriptionStatus::Expired]);

        $this->info("Earning entries posted: {$posted}. Subscriptions expired: {$expired}.");

        return self::SUCCESS;
    }
}
