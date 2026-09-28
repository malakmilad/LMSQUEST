<?php

namespace App\Filament\Resources\InstructorResource\Widgets;

use App\Domain\Ledger\InstructorPositionService;
use App\Filament\Resources\InstructorResource;
use App\Models\Instructor;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class InstructorPositionStats extends StatsOverviewWidget
{
    public ?Instructor $record = null;

    protected function getStats(): array
    {
        if ($this->record === null) {
            return [];
        }

        $position = app(InstructorPositionService::class)->for($this->record);

        return [
            Stat::make('Available', $position->available->format())
                ->description('Payable now (ledger cache)'),
            Stat::make('Outstanding', $position->outstanding()->format())
                ->description('Available + in-flight holds'),
            Stat::make('Paid', $position->paid->format())
                ->description('Confirmed provider transfers'),
            Stat::make('Lifetime earned', $position->lifetimeEarned->format())
                ->description('Clawbacks '.$position->lifetimeClawedBack->format()),
        ];
    }
}
