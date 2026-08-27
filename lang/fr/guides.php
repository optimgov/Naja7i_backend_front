<?php

/*
 * LES GUIDES D'ÉCRAN — écrits pour être lus, pas pour être exacts.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * POURQUOI ILS VIVENT ICI ET PAS DANS LE CODE
 *
 * Le back-office se lit en français ET en arabe : `SetLocale` suit la
 * préférence du compte. Un guide écrit en dur dans une classe PHP ne suivrait
 * pas, et un expert arabophone lirait son poste de travail dans une langue
 * qu'il n'a pas choisie — précisément ce que DET-98 reproche déjà aux locales
 * serveur, que personne ne contrôle.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * LA RÈGLE D'ÉCRITURE, ET ELLE N'EST PAS COSMÉTIQUE
 *
 * Un guide ne définit pas, il EXPLIQUE. « Couples (compétence, cause) attendus
 * par des candidats et servis par moins de deux questions » est exact et
 * illisible : c'est le vocabulaire du modèle rendu à qui ne l'a pas écrit.
 *
 *   – on nomme les choses comme la personne les nomme, pas comme la base ;
 *   – on dit ce qu'on FAIT, pas ce que l'écran CONTIENT ;
 *   – une liste vide a presque toujours deux causes opposées : on les sépare ;
 *   – on donne une porte de sortie, jamais un cul-de-sac.
 */

return [

    // ── Le poste de rédaction ───────────────────────────────────────────

    'couverture' => [
        'role' => 'Ce qu’il faut écrire en priorité. La liste montre les points du programme '
            .'sur lesquels des candidats se sont trompés et où la banque manque de questions '
            .'pour les faire progresser.',
        'gestes' => [
            'Lire la première ligne : c’est le point que le plus de candidats attendent.',
            'Écrire les questions manquantes sur ce point — il en faut au moins deux par langue.',
            'Revenir ici : la liste se réordonne toute seule, elle suit les erreurs réelles.',
        ],
        'vide' => 'Deux causes opposées, et il faut les distinguer. Si l’épreuve a des questions '
            .'publiées et que des candidats ont passé des diagnostics, une liste vide est une '
            .'bonne nouvelle : tout ce qui est demandé est servi. Si personne n’a encore rien '
            .'passé, elle ne dit rien du tout — l’instrument n’a rien à mesurer. Le titre '
            .'affiché sous la liste distingue les deux cas.',
        'ensuite_ecrire' => 'Écrire une question',
        'ensuite_file' => 'La file de qualification',
    ],

    'file_de_qualification' => [
        'role' => 'Votre poste de travail. Le corpus importé arrive ici brut : une question à '
            .'la fois, la plus ancienne d’abord, vous décidez de quoi elle relève et si sa '
            .'réponse est bien celle-là.',
        'gestes' => [
            'Lire la question et, à côté, ce que la source imprime — jamais recopier l’un dans l’autre.',
            'Lui donner son chapitre. Si aucun ne convient, l’écarter avec un motif plutôt que de forcer.',
            'Confirmer la bonne réponse, puis la transférer à la banque où elle suivra la chaîne éditoriale.',
        ],
        'vide' => 'Deux causes opposées. Ou tout le corpus importé est traité — et il faut en '
            .'importer davantage. Ou rien n’a jamais été importé sur cette épreuve, et la file '
            .'n’a simplement rien reçu.',
        'ensuite_couverture' => 'Ce qu’il manque à la banque',
        'ensuite_questions' => 'La banque de questions',
    ],

    'questions' => [
        'role' => 'La banque. Toutes les questions, à tous les stades — du brouillon à la '
            .'question retirée. C’est ici qu’on écrit, qu’on relit, qu’on valide et qu’on publie.',
        'gestes' => [
            'Filtrer par état pour voir ce qui vous attend : « à vérifier » si vous relisez, « brouillon » si vous écrivez.',
            'Les actes de la chaîne sont sur chaque ligne : soumettre, relire, valider, publier, retirer.',
            'Une question publiée est GELÉE. Pour la corriger, l’action « Corriger — nouvelle version » ouvre une copie en brouillon.',
        ],
        'vide' => 'Aucune question ne correspond au filtre. Retirez le filtre avant d’en conclure '
            .'que la banque est vide — elle ne l’est presque jamais entièrement.',
        'ensuite_ecrire' => 'Écrire une question',
        'ensuite_couverture' => 'Voir ce qui manque',
    ],

    'sources' => [
        'role' => 'Les documents sur lesquels les questions s’appuient. Une source vérifiée '
            .'atteste que quelqu’un a eu la pièce sous les yeux — sans cela, aucune question qui '
            .'la cite ne peut être servie au diagnostic.',
        'gestes' => [
            'Vérifier une source une seule fois : toutes les questions qui la citent en profitent.',
            'Ne vérifier que ce que vous avez réellement lu — c’est ce que votre nom atteste ensuite.',
            'Modifier le titre, l’autorité ou l’adresse d’une source ANNULE sa vérification, et rebloque la publication jusqu’à re-contrôle.',
        ],
        'vide' => 'Aucune source enregistrée. Une banque sans source ne peut rien publier au '
            .'diagnostic : c’est le premier objet à créer, avant même la première question.',
        'ensuite_questions' => 'Les questions qui les citent',
    ],

    'competency_nodes' => [
        'role' => 'L’arbre des compétences d’une épreuve — ses chapitres. C’est lui qui décide '
            .'où s’inscrit chaque résultat sur la carte du candidat, et donc ce que la plateforme '
            .'sait lui dire.',
        'gestes' => [
            'Élaguer d’abord : un nœud gardé réclame une douzaine de questions pour que la révision fonctionne.',
            'Confirmer les nœuds proposés, qui arrivent des programmes officiels sans avoir été relus.',
            'Un nœud n’appartient qu’à UNE épreuve et ne se déplace pas vers une autre — la base le refuse.',
        ],
        'vide' => 'Cette épreuve n’a pas d’arbre. Aucune question ne peut y être rattachée tant '
            .'qu’il n’existe pas : c’est le premier travail sur une épreuve neuve.',
        'ensuite_taxonomies' => 'Les profils de taxonomie',
    ],

    'taxonomy_profiles' => [
        'role' => 'La forme que doit avoir un arbre pour qu’une épreuve soit publiable : combien '
            .'de niveaux, et à quelle profondeur une question doit être rattachée.',
        'gestes' => [
            'Vérifier qu’un profil existe avant de bâtir un arbre : c’est lui qui dira si l’arbre est recevable.',
            'Un rattachement trop haut fait échouer la publication — le profil est ce qui l’annonce à l’avance.',
        ],
        'vide' => 'Aucun profil défini. Les arbres existants restent utilisables, mais rien ne '
            .'contrôle plus leur forme.',
        'ensuite_noeuds' => 'Les arbres de compétences',
    ],

    'difficulty_levels' => [
        'role' => 'Les cinq crans de difficulté et leurs libellés. Vous déclarez la difficulté '
            .'d’une question ; la plateforme calcule séparément celle qu’elle OBSERVE sur les '
            .'réponses réelles, et l’écart entre les deux est votre meilleur signal.',
        'gestes' => [
            'Nommer chaque cran dans les deux langues — un nombre nu ne dit rien à personne.',
            'Comparer ensuite déclarée et observée : une question « facile » que deux tiers ratent ne mesure pas ce que vous croyez.',
        ],
        'vide' => 'Aucun cran nommé. L’interface affichera des nombres nus, ce qu’elle s’interdit : '
            .'nommez-les avant d’ouvrir la difficulté aux experts.',
        'ensuite_questions' => 'La banque de questions',
    ],

];
