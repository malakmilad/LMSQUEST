<?php

namespace App\Filament\Resources\InstructorResource\RelationManagers;

use App\Filament\Resources\InstructorResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class LedgerEntriesRelationManager extends RelationManager
{
    protected static string $relationship = 'ledgerEntries';

    protected static ?string $title = 'Ledger';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id'),
                Tables\Columns\TextColumn::make('type')->badge(),
                Tables\Columns\TextColumn::make('amount_cents')
                    ->label('Amount')
                    ->formatStateUsing(fn (int $state): string => InstructorResource::money($state)),
                Tables\Columns\TextColumn::make('idempotency_key')->limit(28)->tooltip(fn ($state) => $state),
                Tables\Columns\TextColumn::make('description')->limit(40)->placeholder('—'),
                Tables\Columns\TextColumn::make('occurred_at')->since(),
            ])
            ->defaultSort('id', 'desc')
            ->paginated([10, 25, 50]);
    }
}
