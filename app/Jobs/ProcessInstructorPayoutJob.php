<?php

namespace App\Jobs;

use App\Actions\ProcessInstructorPayout;
use App\Models\Payout;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessInstructorPayoutJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 8;

    public int $uniqueFor = 3600;

    /** @var list<int> */
    public array $backoff = [5, 15, 30, 60, 120];

    public function __construct(public Payout $payout) {}

    public function uniqueId(): string
    {
        return 'payout:'.$this->payout->id;
    }

    public function handle(ProcessInstructorPayout $action): void
    {
        $action->handle($this->payout);
    }
}
