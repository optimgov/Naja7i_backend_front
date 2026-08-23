<?php

namespace App\Enums;

/**
 * Les quatre genres de signalement éditorial — lot Q2.
 *
 * FERMÉS EN CODE, et c'est ce qui les rend exploitables : quatre colonnes se
 * comptent, cinquante phrases libres ne se dépouillent pas. Chaque genre
 * appelle une conduite différente — réécrire l'énoncé, départager les options,
 * rouvrir le corrigé, reclasser le nœud — et c'est pour cela qu'on les sépare
 * plutôt que de compter « des problèmes ».
 *
 * Les libellés vivent aux clés de traduction : ce que l'expert lit est du
 * texte de produit, jamais un code d'énumération.
 */
enum EditorialFlagKind: string
{
    case STEM_DOUBTFUL = 'stem_doubtful';
    case OPTIONS_AMBIGUOUS = 'options_ambiguous';
    case ANSWER_DISPUTED = 'answer_disputed';
    case TAXONOMY_WRONG = 'taxonomy_wrong';

    public function label(?string $locale = null): string
    {
        return __('preparation.signalement_'.$this->value, [], $locale);
    }

    /** @return array<string, string> */
    public static function options(?string $locale = null): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label($locale);
        }

        return $options;
    }
}
