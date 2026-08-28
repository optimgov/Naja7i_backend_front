<?php

/*
 * LES GUIDES D'ÉCRAN — écrits pour être lus, pas pour être exacts.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * POURQUOI ILS VIVENT ICI ET PAS DANS LE CODE
 *
 * Le back-office se lit en français ET en arabe : `SetLocale` suit la
 * préférence du compte. Un guide écrit en dur dans une classe PHP ne suivrait
 * pas — et c'est exactement ce qui est arrivé au CADRE du panneau, resté
 * français jusqu'à l'audit du 28 août. Les libellés communs sont donc ici
 * aussi, sous `commun`.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * LA RÈGLE D'ÉCRITURE, ET ELLE N'EST PAS COSMÉTIQUE
 *
 * Un guide ne définit pas, il EXPLIQUE.
 *
 *   – on nomme les choses comme la personne les nomme, pas comme la base ;
 *   – on dit ce qu'on FAIT, pas ce que l'écran CONTIENT ;
 *   – un terme métier qu'on ne peut pas éviter s'explique DANS LA PHRASE ;
 *   – une liste vide a deux causes opposées : on les sépare, on ne les fond
 *     pas dans un paragraphe que le lecteur devra démêler ;
 *   – on donne une porte de sortie, jamais un cul-de-sac ;
 *   – pas de majuscules d'alerte : « GELÉE » criait pour rien.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * CE QUI A ÉTÉ VÉRIFIÉ DANS LE CODE AVANT D'ÊTRE ÉCRIT ICI
 *
 *   – « au moins deux par langue » : `CouvertureBanque::SOEURS_MINIMUM = 2`.
 *   – « environ douze questions par nœud » n'est PAS une règle : c'est un
 *     ordre de grandeur déduit de `AttemptService::MIROIRS_PAR_COUPLE = 3`
 *     multiplié par le nombre de causes qu'un chapitre mobilise. Il est écrit
 *     comme tel.
 *   – la vérification d'une source est UNE condition parmi les douze que
 *     `QuestionIntegrityChecker::publicationIssues()` peut lever, pas la porte.
 *   – sans profil de taxonomie, le contrôle de profondeur est SAUTÉ : la
 *     publication n'est pas bloquée, la structure n'est simplement plus
 *     vérifiée.
 */

return [

    // ── Le cadre, commun à tous les écrans ──────────────────────────────

    'commun' => [
        'gestes' => 'Ce que vous pouvez faire ici',
        'vide' => 'Si la liste est vide',
        'ensuite' => 'Étape suivante',
    ],

    // ── Le poste de rédaction ───────────────────────────────────────────

    'couverture' => [
        'titre' => 'Comprendre la couverture',
        'role' => 'Cet écran classe les sujets pour lesquels les candidats ont besoin de '
            .'davantage de questions. Commencez par le premier : c’est le besoin le plus '
            .'fréquent qui n’est pas encore assez couvert.',
        'gestes' => [
            'Choisissez l’épreuve à analyser.',
            'Ouvrez le premier besoin de la liste.',
            'Rédigez les questions manquantes — il en faut au moins deux par langue — puis revenez vérifier le nouveau classement.',
        ],
        'vide' => [
            'Tout est couvert : des diagnostics ont été passés, et chaque besoin observé dispose déjà d’assez de questions.',
            'Pas encore de données : personne n’a passé de diagnostic sur cette épreuve, il n’y a donc aucun besoin à mesurer.',
        ],
        'ensuite_ecrire' => 'Écrire une question',
        'ensuite_file' => 'La file de qualification',
    ],

    'file_de_qualification' => [
        'titre' => 'Traiter la file de qualification',
        'role' => 'Les questions importées arrivent ici avant d’entrer dans la banque. Pour '
            .'chacune, vous vérifiez son classement et la réponse indiquée par le document '
            .'d’origine.',
        'gestes' => [
            'Comparez la question avec le passage du document source affiché à côté — le texte de la source ne se recopie pas dans vos champs, un champ pré-rempli étant accepté sans être lu.',
            'Choisissez le chapitre correspondant ; si aucun ne convient, écartez la question avec un motif.',
            'Confirmez la bonne réponse, puis envoyez la question dans la banque, où elle passera par la relecture, la validation et la publication.',
        ],
        'vide' => [
            'Tout est traité : les questions importées sur cette épreuve ont toutes reçu une décision.',
            'Rien n’a été importé : la file n’a simplement jamais reçu de questions pour cette épreuve.',
        ],
        'ensuite_couverture' => 'Ce qu’il manque à la banque',
        'ensuite_questions' => 'La banque de questions',
    ],

    'questions' => [
        'titre' => 'Utiliser la banque de questions',
        'role' => 'La banque rassemble toutes les questions et indique leur étape de '
            .'préparation. Utilisez les filtres pour retrouver le travail qui correspond à '
            .'votre rôle.',
        'gestes' => [
            'Filtrez par état : « à vérifier » si vous relisez, « brouillon » si vous écrivez.',
            'Chaque ligne porte ses actions : soumettre à la relecture, marquer relue, valider, publier, retirer.',
            'Une question publiée ne se modifie plus directement. Pour la corriger, l’action « Corriger — nouvelle version » ouvre une copie en brouillon.',
        ],
        'vide' => [
            'Un filtre est actif : retirez-le avant de conclure que la banque est vide.',
            'Cette épreuve n’a pas encore de questions : c’est le cas d’une épreuve neuve.',
        ],
        'ensuite_ecrire' => 'Écrire une question',
        'ensuite_couverture' => 'Voir ce qui manque',
    ],

    'sources' => [
        'titre' => 'Vérifier les sources',
        'role' => 'Cet écran recense les documents utilisés pour rédiger les questions. '
            .'Vérifier une source confirme que le document a été consulté et correctement '
            .'identifié ; cela ne valide pas les questions qui le citent.',
        'gestes' => [
            'Contrôlez le titre, l’organisme, la date et le lien du document.',
            'Marquez la source comme vérifiée uniquement après l’avoir consultée — c’est votre nom qui l’atteste.',
            'Après toute modification de ses références, vérifiez-la de nouveau : la modification annule le contrôle précédent.',
        ],
        'vide' => [
            'Aucun document n’est encore enregistré : une question ne peut pas être servie au diagnostic sans une source vérifiée, parmi les autres conditions de publication.',
        ],
        'ensuite_questions' => 'Les questions qui s’y appuient',
    ],

    'competency_nodes' => [
        'titre' => 'Organiser l’arbre des compétences',
        'role' => 'L’arbre organise les domaines, sous-domaines et chapitres d’une épreuve. '
            .'Chaque question s’y rattache, ce qui permet de classer les résultats d’un '
            .'candidat et de lui proposer des révisions ciblées.',
        'gestes' => [
            'Gardez uniquement les niveaux qui seront réellement utilisés.',
            'Relisez et confirmez les éléments proposés à partir des programmes officiels : ils arrivent sans avoir été validés par personne.',
            'Chaque élément conservé devra disposer d’assez de questions — environ douze dans le fonctionnement actuel — pour alimenter les révisions.',
            'Un élément ne se déplace pas vers une autre épreuve : chaque épreuve a son propre arbre.',
        ],
        'vide' => [
            'Cette épreuve n’a pas encore d’arbre : aucune question ne peut y être rattachée tant qu’il n’existe pas.',
        ],
        'ensuite_taxonomies' => 'Les profils de taxonomie',
    ],

    'taxonomy_profiles' => [
        'titre' => 'Définir un profil de taxonomie',
        'role' => 'Un profil définit la structure attendue pour les arbres d’une épreuve : le '
            .'nom de chaque niveau, et le niveau auquel les questions doivent être rattachées.',
        'gestes' => [
            'Créez ou vérifiez le profil avant de construire l’arbre.',
            'Nommez clairement chaque niveau — par exemple domaine, sous-domaine et chapitre.',
            'Indiquez le niveau minimal auquel une question peut être rattachée pour être publiée.',
        ],
        'vide' => [
            'Aucun profil n’est défini. Les arbres existants restent publiables, mais leur structure n’est plus vérifiée : une question peut alors être rattachée à n’importe quel niveau.',
        ],
        'ensuite_noeuds' => 'Les arbres de compétences',
    ],

    'difficulty_levels' => [
        'titre' => 'Comprendre les crans de difficulté',
        'role' => 'Ces cinq crans servent à nommer la difficulté prévue d’une question. Après '
            .'suffisamment de réponses, la plateforme calcule aussi sa difficulté observée. '
            .'Comparer les deux aide à repérer les questions à revoir.',
        'gestes' => [
            'Donnez à chaque cran un libellé clair en français et en arabe — un nombre nu ne dit rien à personne.',
            'Comparez la difficulté prévue au taux de réussite réel lorsqu’il existe assez de réponses.',
            'Examinez les écarts importants avant de modifier la question ou son classement.',
        ],
        'vide' => [
            'Aucun cran n’est nommé : l’interface afficherait des nombres nus, ce qu’elle s’interdit.',
        ],
        'ensuite_questions' => 'La banque de questions',
    ],

];
