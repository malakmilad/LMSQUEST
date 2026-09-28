<?php

namespace App\Jobs;

use App\Actions\ProcessInstructorPayout;
use App\Models\Payout;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ReconcileUnknownPayoutJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $uniqueFor = 3600;

    public function __construct(public Payout $payout) {}

    public function uniqueId(): string
    {
        return 'reconcile-payout:'.$this->payout->id;
    }

    public function handle(ProcessInstructorPayout $action): void
    {
        $action->reconcile($this->payout);
    }
}
