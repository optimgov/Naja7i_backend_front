<?php

namespace App\Enums;

use App\Contracts\AccessGrant;

/**
 * Les unités qu'un quota peut compter — fermées en code (ADR-0027).
 *
 * « Aucun des deux ne peut créer un quota sur une unité que le code ne compte
 * pas. » Une unité n'existe donc ici que si une capacité produit la consomme
 * réellement. Il n'y a ni champ libre, ni JSON, ni valeur sentinelle négative
 * pour « illimité » : l'illimité est l'ABSENCE de profil sur le droit, jamais
 * un nombre.
 *
 * LE QUOTA F03 N'EST PAS UNE UNITÉ ICI, et c'est délibéré. L'ADR-0027 le
 * décrit comme un compteur global par compte de causes révélées, cumulatif à
 * vie, « distinct du quota de questions », dont ce lot « ne change ni la fiche
 * validée, ni le comportement ». Lui donner une unité ouvrirait un second
 * circuit sur un compteur qui a déjà le sien.
 */
enum QuotaUnit: string
{
    case QUESTIONS = 'questions';

    /**
     * La capacité qui consomme cette unité.
     *
     * C'est ce lien, et lui seul, qui permet de refuser un profil sélectionné
     * pour une capacité qui ne compte pas son unité — la validation exigée par
     * la matrice des champs de l'administration commerciale : « profil
     * existant, autorisé pour l'unité, dans ses bornes ».
     */
    public function capability(): string
    {
        return match ($this) {
            self::QUESTIONS => AccessGrant::QUESTIONS_ANSWER,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::QUESTIONS => 'questions',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
