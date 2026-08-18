<?php

namespace App\Filament\Resources\Questions;

use App\Filament\Resources\Questions\Pages\CreateQuestion;
use App\Filament\Resources\Questions\Pages\EditQuestion;
use App\Filament\Resources\Questions\Pages\ListQuestions;
use App\Filament\Resources\Questions\Schemas\QuestionForm;
use App\Filament\Resources\Questions\Tables\QuestionsTable;
use App\Models\Question;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class QuestionResource extends Resource
{
    /**
     * LA PERMISSION QUI OUVRE CETTE SURFACE — D-13.
     *
     * Déclarée ici parce qu'un `abort(403)` ne transporte aucun code : la
     * politique (QuestionPolicy::viewAny) rend un booléen, et le nom de
     * ce qui manque est perdu au moment où l'on pourrait le dire. La page
     * 403 la lit pour nommer ce qu'il faut demander.
     *
     * Une déclaration à côté d'une politique dérive : `RefusNommeTest` la
     * tient contre elle, surface par surface.
     */
    public const PERMISSION_REQUISE = 'questions.view';

    protected static ?string $model = Question::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return QuestionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return QuestionsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListQuestions::route('/'),
            'create' => CreateQuestion::route('/create'),
            'edit' => EditQuestion::route('/{record}/edit'),
        ];
    }
}
