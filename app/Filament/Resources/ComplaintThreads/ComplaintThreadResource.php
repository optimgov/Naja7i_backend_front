<?php

namespace App\Filament\Resources\ComplaintThreads;

use App\Filament\Resources\ComplaintThreads\Pages\ListComplaintThreads;
use App\Filament\Resources\ComplaintThreads\Pages\ViewComplaintThread;
use App\Models\ComplaintThread;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

final class ComplaintThreadResource extends Resource
{
    public const PERMISSION_REQUISE = 'complaints.view';

    protected static ?string $model = ComplaintThread::class;

    protected static ?string $slug = 'reclamations';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static ?string $navigationLabel = 'Réclamations';

    protected static ?string $modelLabel = 'réclamation';

    protected static ?string $pluralModelLabel = 'réclamations';

    protected static string|\UnitEnum|null $navigationGroup = 'Assistance';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('last_message_at')->label('Dernier message')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('candidate.email')->label('Candidat')->searchable(),
                TextColumn::make('subject')->label('Objet')->searchable()->wrap()->limit(80),
                TextColumn::make('category')->label('Catégorie')->badge()
                    ->formatStateUsing(fn (string $state): string => self::categoryLabel($state)),
                TextColumn::make('status')->label('État')->badge()
                    ->color(fn (string $state): string => $state === 'waiting_staff' ? 'warning' : 'info')
                    ->formatStateUsing(fn (string $state): string => $state === 'waiting_staff'
                        ? 'Attend l’équipe'
                        : 'Attend le candidat'),
            ])
            ->defaultSort('last_message_at', 'desc')
            ->filters([
                SelectFilter::make('status')->label('État')->options([
                    'waiting_staff' => 'Attend l’équipe',
                    'waiting_candidate' => 'Attend le candidat',
                ]),
                SelectFilter::make('category')->label('Catégorie')->options([
                    'technical' => 'Technique',
                    'pedagogical' => 'Pédagogique',
                    'account' => 'Compte',
                    'payment' => 'Paiement',
                    'other' => 'Autre',
                ]),
            ])
            ->recordActions([ViewAction::make()->label('Ouvrir')])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListComplaintThreads::route('/'),
            'view' => ViewComplaintThread::route('/{record}'),
        ];
    }

    public static function categoryLabel(string $category): string
    {
        return match ($category) {
            'technical' => 'Technique',
            'pedagogical' => 'Pédagogique',
            'account' => 'Compte',
            'payment' => 'Paiement',
            default => 'Autre',
        };
    }
}
