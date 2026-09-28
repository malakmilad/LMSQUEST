<?php

namespace App\Filament\Resources\InstructorResource\Pages;

use App\Filament\Resources\InstructorResource;
use App\Filament\Resources\InstructorResource\Widgets\InstructorBalanceOverview;
use Filament\Resources\Pages\ListRecords;

class ListInstructors extends ListRecords
{
    protected static string $resource = InstructorResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            InstructorBalanceOverview::class,
        ];
    }
}
