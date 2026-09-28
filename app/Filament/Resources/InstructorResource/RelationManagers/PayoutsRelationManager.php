<?php

namespace App\Filament\Resources\InstructorResource\RelationManagers;

use App\Filament\Resources\InstructorResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class PayoutsRelationManager extends RelationManager
{
    protected static string $relationship = 'payouts';

    protected static ?string $title = 'Payout history';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id'),
                Tables\Columns\TextColumn::make('amount_cents')
                    ->label('Amount')
                    ->formatStateUsing(fn (int $state): string => InstructorResource::money($state)),
                Tables\Columns\TextColumn::make('status')->badge(),
                Tables\Columns\TextColumn::make('provider_reference')->placeholder('—')->copyable(),
                Tables\Columns\TextColumn::make('attempt_count')->label('Attempts'),
                Tables\Columns\TextColumn::make('last_error')->limit(40)->placeholder('—'),
                Tables\Columns\TextColumn::make('dispatched_at')->since(),
                Tables\Columns\TextColumn::make('confirmed_at')->since()->placeholder('—'),
            ])
            ->defaultSort('id', 'desc')
            ->paginated([10, 25, 50]);
    }
}
