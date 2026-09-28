<?php

namespace App\Actions;

use App\Enums\PayoutStatus;
use App\Models\Instructor;
use App\Models\Payout;

final class DispatchInstructorPayouts
{
    public function __construct(private readonly InitiateInstructorPayout $initiate) {}

    /**
     * @return list<Payout>
     */
    public function handle(int $minCents = 0, ?int $limit = null): array
    {
        $query = Instructor::query()
            ->where('available_balance_cents', '>=', $minCents)
            ->where('available_balance_cents', '>', 0)
            ->whereNull('in_flight_payout_id')
            ->orderBy('id');

        if ($limit !== null) {
            $query->limit($limit);
        }

        $created = [];

        $query->chunkById(100, function ($instructors) use (&$created, $minCents) {
            foreach ($instructors as $instructor) {
                $payout = $this->initiate->handle($instructor, $minCents);

                if ($payout !== null) {
                    $created[] = $payout;
                }
            }
        });

        return $created;
    }
}
