<?php

namespace App\Filament\Resources\InstructorResource\Pages;

use App\Filament\Resources\InstructorResource;
use Filament\Resources\Pages\ViewRecord;

class ViewInstructor extends ViewRecord
{
    protected static string $resource = InstructorResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
