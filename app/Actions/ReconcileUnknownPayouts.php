<?php

namespace App\Actions;

use App\Enums\PayoutStatus;
use App\Models\Payout;

final class ReconcileUnknownPayouts
{
    public function __construct(private readonly ProcessInstructorPayout $processor) {}

    /**
     * @return list<Payout>
     */
    public function handle(): array
    {
        $resolved = [];

        Payout::query()
            ->where('status', PayoutStatus::Unknown)
            ->orderBy('id')
            ->each(function (Payout $payout) use (&$resolved) {
                $resolved[] = $this->processor->reconcile($payout);
            });

        return $resolved;
    }
}
