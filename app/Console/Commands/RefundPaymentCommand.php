<?php

namespace App\Console\Commands;

use App\Actions\Revenue\RefundSubscriptionPayment;
use App\Enums\RefundType;
use App\Models\SubscriptionPayment;
use App\Services\Money\Allocator;
use Illuminate\Console\Command;

class RefundPaymentCommand extends Command
{
    protected $signature = 'subscriptions:refund {payment : Subscription payment id} {--prorated : Refund only the periods that have not started} {--reason=}';

    protected $description = 'Refund a subscription payment (full by default)';

    public function handle(RefundSubscriptionPayment $refund): int
    {
        $payment = SubscriptionPayment::query()->findOrFail($this->argument('payment'));
        $type = $this->option('prorated') ? RefundType::Prorated : RefundType::Full;

        $result = $refund->handle($payment, $type, $this->option('reason'));

        $this->info(sprintf(
            'Refund #%d (%s): %s returned to the student.',
            $result->id,
            $result->type->value,
            Allocator::format($result->amount_minor, $result->currency),
        ));

        return self::SUCCESS;
    }
}
