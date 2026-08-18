<?php

namespace App\Filament\Resources\Sources;

use App\Filament\Resources\Sources\Pages\CreateSource;
use App\Filament\Resources\Sources\Pages\EditSource;
use App\Filament\Resources\Sources\Pages\ListSources;
use App\Filament\Resources\Sources\Schemas\SourceForm;
use App\Filament\Resources\Sources\Tables\SourcesTable;
use App\Models\Source;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Le registre documentaire — seconde moitié du lot A4.
 *
 * LA VÉRIFICATION EST UN ACTE SUR LA SOURCE, PAS SUR LA CITATION (DET-46,
 * tranché au PAS-28). Une source est citée par plusieurs questions ; la
 * contrôler une fois profite à toutes. C'est pourquoi cette surface existe à
 * part de celle des questions, et non comme un champ de plus dans le
 * formulaire de rédaction.
 */
class SourceResource extends Resource
{
    /**
     * LA PERMISSION QUI OUVRE CETTE SURFACE — D-13.
     *
     * Déclarée ici parce qu'un `abort(403)` ne transporte aucun code : la
     * politique (SourcePolicy::viewAny) rend un booléen, et le nom de
     * ce qui manque est perdu au moment où l'on pourrait le dire. La page
     * 403 la lit pour nommer ce qu'il faut demander.
     *
     * Une déclaration à côté d'une politique dérive : `RefusNommeTest` la
     * tient contre elle, surface par surface.
     */
    public const PERMISSION_REQUISE = 'questions.view';

    protected static ?string $model = Source::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCheck;

    protected static ?string $modelLabel = 'source';

    protected static ?string $pluralModelLabel = 'sources';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return SourceForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SourcesTable::configure($table);
    }

    public static function getPages(): array
    {
        /* Pas de page « view » : une source n'a rien à montrer qu'un rédacteur
         * autorisé ne puisse pas voir dans le formulaire, et une page de plus
         * serait une navigation de plus pour la même information. */
        return [
            'index' => ListSources::route('/'),
            'create' => CreateSource::route('/create'),
            'edit' => EditSource::route('/{record}/edit'),
        ];
    }
}
