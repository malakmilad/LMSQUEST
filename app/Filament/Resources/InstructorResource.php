<?php

namespace App\Filament\Resources;

use App\Filament\Resources\InstructorResource\Pages;
use App\Filament\Resources\InstructorResource\RelationManagers;
use App\Models\Instructor;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class InstructorResource extends Resource
{
    protected static ?string $model = Instructor::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Instructor ledger';

    protected static ?string $modelLabel = 'instructor';

    protected static ?string $pluralModelLabel = 'instructors';

    protected static ?string $navigationGroup = 'Revenue';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Instructor')
                    ->schema([
                        Infolists\Components\TextEntry::make('user.name')->label('Name'),
                        Infolists\Components\TextEntry::make('user.email')->label('Email'),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->sortable(),
                Tables\Columns\TextColumn::make('user.name')->label('Instructor')->searchable(),
                Tables\Columns\TextColumn::make('user.email')->label('Email')->searchable(),
                Tables\Columns\TextColumn::make('available_balance_cents')
                    ->label('Available')
                    ->formatStateUsing(fn (int $state, Instructor $record): string => self::money($state, $record->currency))
                    ->sortable(),
                Tables\Columns\TextColumn::make('inFlightPayout.status')
                    ->label('In-flight payout')
                    ->badge()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('updated_at')->since()->sortable(),
            ])
            ->defaultSort('id')
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\PayoutsRelationManager::class,
            RelationManagers\LedgerEntriesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInstructors::route('/'),
            'view' => Pages\ViewInstructor::route('/{record}'),
        ];
    }

    public static function money(int $cents, string $currency = 'EGP'): string
    {
        $sign = $cents < 0 ? '-' : '';
        $absolute = abs($cents);

        return sprintf('%s%d.%02d %s', $sign, intdiv($absolute, 100), $absolute % 100, $currency);
    }
}
