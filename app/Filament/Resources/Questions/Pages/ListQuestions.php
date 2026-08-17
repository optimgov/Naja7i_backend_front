<?php

namespace App\Filament\Resources\Questions\Pages;

use App\Filament\Pages\ListeAvecCreation;
use App\Filament\Resources\Questions\QuestionResource;

class ListQuestions extends ListeAvecCreation
{
    protected static string $resource = QuestionResource::class;
}
