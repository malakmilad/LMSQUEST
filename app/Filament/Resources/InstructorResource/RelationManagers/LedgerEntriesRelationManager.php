<?php

namespace App\Filament\Resources\InstructorResource\RelationManagers;

use App\Enums\LedgerEntryType;
use App\Filament\Resources\InstructorResource;
use App\Models\LedgerEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class LedgerEntriesRelationManager extends RelationManager
{
    protected static string $relationship = 'ledgerEntries';

    protected static ?string $title = 'Ledger';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('occurred_at')->label('Date')->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (LedgerEntryType $state) => str_replace('_', ' ', ucfirst($state->value)))
                    ->color(fn (LedgerEntryType $state) => match ($state) {
                        LedgerEntryType::Earning => 'success',
                        LedgerEntryType::RefundReversal => 'danger',
                        LedgerEntryType::Payout => 'info',
                        LedgerEntryType::Adjustment => 'warning',
                    }),
                Tables\Columns\TextColumn::make('amount_minor')
                    ->label('Amount')
                    ->alignEnd()
                    ->formatStateUsing(fn (int $state, LedgerEntry $record) => InstructorResource::money($state, $record->currency)),
                Tables\Columns\TextColumn::make('entry_key')->label('Reference')->limit(40)->tooltip(fn (LedgerEntry $record) => $record->entry_key),
            ])
            ->defaultSort('occurred_at', 'desc')
            ->paginated([10, 25, 50]);
    }
}
