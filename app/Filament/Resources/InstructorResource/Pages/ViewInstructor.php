<?php

namespace App\Filament\Resources\InstructorResource\Pages;

use App\Filament\Resources\InstructorResource;
use App\Filament\Resources\InstructorResource\Widgets\InstructorPositionStats;
use Filament\Resources\Pages\ViewRecord;

class ViewInstructor extends ViewRecord
{
    protected static string $resource = InstructorResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            InstructorPositionStats::class,
        ];
    }
}
