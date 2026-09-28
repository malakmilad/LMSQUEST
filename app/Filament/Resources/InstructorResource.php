<?php

namespace App\Filament\Resources;

use App\Filament\Resources\InstructorResource\Pages;
use App\Filament\Resources\InstructorResource\RelationManagers;
use App\Models\Instructor;
use App\Services\Money\Allocator;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Read-only view of instructor balances. Figures come from the
 * instructor_balances projection (one eager-loaded row per instructor), which
 * is maintained in the same transaction as every ledger entry.
 */
class InstructorResource extends Resource
{
    protected static ?string $model = Instructor::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Instructor balances';

    protected static ?string $navigationGroup = 'Revenue';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('balance');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Instructor')->searchable()->sortable()
                    ->description(fn (Instructor $record) => $record->email),
                self::moneyColumn('balance.earned_minor', 'Total earned')
                    ->state(fn (Instructor $record) => $record->balance?->netEarned() ?? 0)
                    ->tooltip('Recognized earnings minus refund reversals, plus adjustments'),
                self::moneyColumn('balance.paid_minor', 'Total paid'),
                self::moneyColumn('balance.outstanding_minor', 'Outstanding')->weight('bold')->color('success'),
                self::moneyColumn('balance.recoverable_minor', 'Recoverable')->color('danger'),
            ])
            ->defaultSort('name')
            ->actions([Tables\Actions\ViewAction::make()])
            ->bulkActions([]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make()
                ->schema([
                    Infolists\Components\TextEntry::make('name')->label('Instructor'),
                    Infolists\Components\TextEntry::make('email'),
                    Infolists\Components\TextEntry::make('currency'),
                ])
                ->columns(3),
            Infolists\Components\Section::make('Balance')
                ->schema([
                    self::moneyEntry('earned', 'Total earned', fn (Instructor $r) => $r->balance?->netEarned() ?? 0)
                        ->helperText(fn (Instructor $r) => 'Gross '.self::money($r->balance?->earned_minor ?? 0, $r->currency)
                            .', reversed '.self::money($r->balance?->reversed_minor ?? 0, $r->currency)),
                    self::moneyEntry('paid', 'Total paid', fn (Instructor $r) => $r->balance?->paid_minor ?? 0),
                    self::moneyEntry('outstanding', 'Outstanding', fn (Instructor $r) => $r->balance?->outstanding_minor ?? 0)
                        ->color('success'),
                    self::moneyEntry('recoverable', 'Recoverable', fn (Instructor $r) => $r->balance?->recoverable_minor ?? 0)
                        ->color('danger')
                        ->helperText('Refunded after payout; offset against future earnings'),
                ])
                ->columns(4),
        ]);
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

    public static function money(int $amountMinor, string $currency): string
    {
        return Allocator::format($amountMinor, $currency);
    }

    private static function moneyColumn(string $name, string $label): Tables\Columns\TextColumn
    {
        return Tables\Columns\TextColumn::make($name)
            ->label($label)
            ->alignEnd()
            ->default(0)
            ->formatStateUsing(fn ($state, Instructor $record) => self::money((int) $state, $record->currency));
    }

    private static function moneyEntry(string $name, string $label, callable $state): Infolists\Components\TextEntry
    {
        return Infolists\Components\TextEntry::make($name)
            ->label($label)
            ->state($state)
            ->size(Infolists\Components\TextEntry\TextEntrySize::Large)
            ->weight('bold')
            ->formatStateUsing(fn ($state, Instructor $record) => self::money((int) $state, $record->currency));
    }
}
