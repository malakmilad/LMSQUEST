<?php

namespace App\Filament\Resources\InstructorResource\Widgets;

use App\Filament\Resources\InstructorResource;
use App\Models\Instructor;
use App\Models\Payout;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class InstructorBalanceOverview extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $available = (int) Instructor::query()->sum('available_balance_cents');
        $inFlight = (int) Payout::query()->whereIn('status', ['pending', 'processing', 'unknown'])->sum('amount_cents');
        $paid = (int) Payout::query()->where('status', 'succeeded')->sum('amount_cents');

        return [
            Stat::make('Instructor available', InstructorResource::money($available)),
            Stat::make('In-flight payouts', InstructorResource::money($inFlight)),
            Stat::make('Paid out (succeeded)', InstructorResource::money($paid)),
        ];
    }
}
