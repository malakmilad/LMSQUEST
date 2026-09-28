<?php

namespace App\Filament\Resources\InstructorResource\RelationManagers;

use App\Enums\PayoutStatus;
use App\Filament\Resources\InstructorResource;
use App\Models\Payout;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class PayoutsRelationManager extends RelationManager
{
    protected static string $relationship = 'payouts';

    protected static ?string $title = 'Payout history';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('Date')->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('amount_minor')
                    ->label('Amount')
                    ->alignEnd()
                    ->formatStateUsing(fn (int $state, Payout $record) => InstructorResource::money($state, $record->currency)),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (PayoutStatus $state) => ucfirst($state->value))
                    ->color(fn (PayoutStatus $state) => $state->color()),
                Tables\Columns\TextColumn::make('provider_reference')->label('Provider reference')->placeholder('—')->copyable(),
                Tables\Columns\TextColumn::make('attempts'),
                Tables\Columns\TextColumn::make('failure_reason')->label('Note')->limit(40)->placeholder('—')
                    ->tooltip(fn (Payout $record) => $record->failure_reason),
                Tables\Columns\TextColumn::make('paid_at')->dateTime()->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('idempotency_key')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(
                    collect(PayoutStatus::cases())->mapWithKeys(fn (PayoutStatus $s) => [$s->value => ucfirst($s->value)])->all()
                ),
            ])
            ->paginated([10, 25, 50]);
    }
}
