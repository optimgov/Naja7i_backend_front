<?php

return [
    /*
     * LES MOTS DU POSTE DE TRAVAIL — lot Q2.
     *
     * Aucune de ces chaînes n'a le droit de vivre dans un composant : l'écran
     * est bilingue, et un libellé écrit en dur ne se traduit jamais. Les
     * LIBELLÉS ET ANCRES DE DIFFICULTÉ, eux, ne sont pas ici — ils vivent en
     * base (`difficulty_levels`), parce qu'ils se corrigent sans déploiement.
     */
    'signalement_stem_doubtful' => 'Énoncé douteux',
    'signalement_options_ambiguous' => 'Options ambiguës',
    'signalement_answer_disputed' => 'Corrigé contesté',
    'signalement_taxonomy_wrong' => 'Rattachement taxonomique faux',

    /*
     * O-6 — LE CODE DU HASARD SE DIT DANS LES MOTS DU CANDIDAT.
     *
     * « Indéterminé » est un mot d'ingénieur : il décrit l'état du système, pas
     * ce que la personne a fait. « J'ai répondu sans savoir » décrit le geste,
     * et c'est ce geste que la remédiation doit adresser.
     */
    'cause_indetermine' => 'J’ai répondu sans savoir',
    'cause_confusion_notions' => 'Deux notions confondues',
    'cause_lecture_enonce' => 'Énoncé mal lu',
    'cause_regle_mal_appliquee' => 'Règle connue, mal appliquée',
    'cause_connaissance_absente' => 'Connaissance absente',
    'cause_source_perimee' => 'Source périmée',
    'cause_calcul' => 'Erreur de calcul',
    'cause_piege_formulation' => 'Piège de formulation',
    'cause_hors_nomenclature' => 'Aucun de ces codes — à nommer',

    'observee_non_significative' => 'Pas encore significative — :tentatives réponse(s) sur :seuil.',
    'observee_significative' => ':tentatives réponses, :taux % de réussite.',

    'source_lecture_seule' => 'Ce que la source dit — pour information, jamais recopié dans vos champs.',

    'etat_imported' => 'À qualifier',
    'etat_qualified' => 'Qualifiée, réponse à confirmer',
    'etat_answered' => 'Réponse confirmée',
    'etat_transferred' => 'Transférée à la banque',
    'etat_illegible' => 'Illisible — à retranscrire',
    'etat_duplicate' => 'Doublon',
    'etat_rejected' => 'Écartée',
    'etat_replaced' => 'Remplacée',
];
