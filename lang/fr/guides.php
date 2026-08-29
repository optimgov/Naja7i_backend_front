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
            'Comparez la question au passage affiché à côté. Ne recopiez pas ce texte dans vos champs : un champ déjà rempli est accepté sans être relu.',
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

    // ── Le poste commercial ─────────────────────────────────────────────

    'audiences' => [
        'titre' => 'Définir les catégories de public',
        'role' => 'Une catégorie de public dit à QUI une offre s’adresse : les candidats au '
            .'CRMEF, les élèves du lycée. Toute offre en vise une ; sans catégorie, rien ne peut '
            .'être mis en vente.',
        'gestes' => [
            'Créez la catégorie avec son libellé en français ET en arabe — le libellé arabe n’est pas optionnel, faute de quoi un candidat arabophone lirait un code.',
            'Une catégorie ne se supprime pas, même vide : la base le refuse, car des offres vendues y renvoient. Désactivez-la plutôt.',
            'Le code identifie la catégorie pour toujours : arrêtez-le avant la première vente.',
        ],
        'vide' => [
            'Aucune catégorie n’existe encore : aucune offre ne peut être composée tant qu’il n’y en a pas au moins une.',
        ],
        'ensuite_offres' => 'Composer une offre',
    ],

    'capability_definitions' => [
        'titre' => 'Nommer ce qu’une offre ouvre',
        'role' => 'Chaque capacité est une chose que l’offre autorise — passer un examen blanc, '
            .'lire sa carte de maîtrise. Le logiciel en fixe la liste ; vous en écrivez ici le '
            .'nom que le candidat lira, dans les deux langues.',
        'gestes' => [
            'Rien ne se crée ni ne se supprime ici : les capacités sont posées par le logiciel, et cette liste est fermée.',
            'Écrivez un libellé et une description compréhensibles par un candidat, en français et en arabe.',
            'Le marqueur « à relire » signale un texte posé par un développeur, jamais relu. Enregistrer EST la relecture : le marqueur tombe alors de lui-même, et ne se repose pas à la main.',
        ],
        'vide' => [
            'Cette liste ne peut pas être vide : ses lignes viennent du logiciel. Si elle l’est, signalez-le — c’est une anomalie d’installation.',
        ],
        'ensuite_offres' => 'Les offres qui les ouvrent',
    ],

    'plans' => [
        'titre' => 'Composer une offre',
        'role' => 'Une offre assemble ce que le logiciel déclare : une catégorie de public, des '
            .'capacités, une durée, un profil de quota, un prix. Vous composez, vous n’inventez '
            .'aucune brique.',
        'gestes' => [
            'Choisissez la catégorie de public visée, puis les capacités que l’offre ouvre.',
            'Sélectionnez un profil de quota — aucun nombre de questions ne se tape ici ; c’est l’admin pédagogique qui les fixe.',
            'Une offre vendue ne se réécrit pas : on en publie une nouvelle version, et l’ancienne cesse d’être proposée.',
        ],
        'vide' => [
            'Aucune offre n’est composée : le catalogue payant est donc vide, et seul le palier gratuit reste accessible.',
        ],
        'ensuite_publics' => 'Les catégories de public',
        'ensuite_quotas' => 'Les profils de quota',
    ],

    'coupons' => [
        'titre' => 'Émettre et suivre les coupons',
        'role' => 'Un coupon donne accès à une offre sans paiement en ligne : il se dicte au '
            .'téléphone, se remet en main propre, et le candidat le saisit lui-même. Émettre un '
            .'lot de coupons revient à ouvrir autant d’abonnements.',
        'gestes' => [
            'Engendrez les coupons : leur code est tiré par le système, jamais saisi — un code choisi à la main se devine.',
            'Suivez qui les a consommés, et quand.',
            'Révoquez un coupon non consommé s’il a fui ou s’il a été émis par erreur ; un coupon déjà consommé ne se reprend pas — c’est un abonnement ouvert.',
        ],
        'vide' => [
            'Aucun coupon n’a été émis pour l’instant.',
            'Un filtre est actif : retirez-le avant de conclure que le registre est vide.',
        ],
        'ensuite_offres' => 'Les offres ouvrables par coupon',
        'ensuite_commandes' => 'Les commandes reçues',
    ],

    'orders' => [
        'titre' => 'Traiter les commandes',
        'role' => 'Chaque ligne est une demande d’abonnement en attente de décision. La valider '
            .'ouvre un droit qui vaut de l’argent ; la refuser ferme la demande en la motivant.',
        'gestes' => [
            'Ouvrez la commande et vérifiez le paiement annoncé avant toute décision.',
            'Validez : l’abonnement s’ouvre immédiatement, et le candidat en est averti.',
            'Refusez avec un motif : c’est ce motif que le candidat lira, et c’est lui qui évite la réclamation qui suivrait un refus muet.',
        ],
        'vide' => [
            'Aucune commande n’attend : toutes ont reçu une décision.',
            'Un filtre est actif — l’état par défaut peut masquer les commandes déjà traitées.',
        ],
        'ensuite_coupons' => 'Les coupons émis',
        'ensuite_offres' => 'Les offres proposées',
    ],

    // ── Le poste pédagogique et le poste d’administration ───────────────

    'quota_profiles' => [
        'titre' => 'Fixer les bornes de travail',
        'role' => 'Un profil de quota dit combien de questions une offre autorise, et sur quelle '
            .'période. C’est le SEUL écran du produit où ces nombres se saisissent : l’offre les '
            .'choisit dans une liste, elle ne les tape jamais.',
        'gestes' => [
            'Nommez le profil par ce qu’il permet, pas par un nombre — « découverte » se retient, « profil 3 » non.',
            'Fixez les bornes, et justifiez-les : la justification est demandée à chaque modification, et elle est conservée.',
            'Un profil déjà employé par une offre vendue se modifie avec précaution : le changement porte sur les abonnements en cours.',
        ],
        'vide' => [
            'Aucun profil n’est défini : aucune offre ne peut être composée, puisqu’elle doit en sélectionner un.',
        ],
        'ensuite_offres' => 'Les offres qui les emploient',
    ],

    'users' => [
        'titre' => 'Gérer les personnes et leurs rôles',
        'role' => 'Cet écran recense les comptes et ce que chacun a le droit de faire. Un rôle '
            .'n’est pas un titre : c’est l’ensemble des écrans et des gestes qu’il ouvre.',
        'gestes' => [
            'Cherchez par e-mail : c’est l’identifiant unique d’un compte.',
            'Accordez le rôle le plus étroit qui suffit — un rôle se retire aussi facilement qu’il se donne, mais ce qu’il a permis, lui, reste fait.',
            'Suspendre un compte lui ferme l’accès sans effacer son travail ni son historique.',
        ],
        'vide' => [
            'Un filtre est actif : retirez-le avant de conclure qu’aucun compte ne correspond.',
        ],
        'ensuite_commandes' => 'Les commandes des candidats',
    ],

    'complaint_threads' => [
        'titre' => 'Répondre aux réclamations',
        'role' => 'Un candidat conteste quelque chose — une facturation, une question, un accès. '
            .'Chaque ligne est un échange ouvert, avec sa date de dernier message.',
        'gestes' => [
            'Ouvrez d’abord ce qui attend depuis le plus longtemps : la colonne « Dernier message » les classe.',
            'Filtrez par état pour ne plus voir que ce qui reste à traiter, et par catégorie pour regrouper les cas semblables.',
            'Répondez dans la langue du candidat — celle de son compte, pas la vôtre.',
        ],
        'vide' => [
            'Aucune réclamation n’attend : toutes ont été traitées.',
            'Un filtre d’état est actif : les réclamations closes sont masquées par défaut.',
        ],
    ],

    'droit_transitoire' => [
        'titre' => 'Poser un droit transitoire',
        'role' => 'Un droit transitoire ouvre à un compte, pour un temps, ce qu’il n’a pas '
            .'acheté : un geste commercial, un dédommagement, une phase de test. Il s’éteint '
            .'seul à son échéance.',
        'gestes' => [
            'Renseignez le compte, les capacités visées et l’échéance.',
            'Prévisualisez d’abord : cette étape n’écrit rien et annonce exactement ce qui serait ouvert.',
            'Posez ensuite, une fois l’impact lu. Les deux boutons prennent les mêmes paramètres : ce que la prévisualisation a montré est ce que la pose écrira.',
        ],
        'vide' => [
            'Cet écran n’a pas de liste : il pose. Ce qui est déjà posé se lit sur l’écran des droits en cours.',
        ],
        'ensuite_poses' => 'Les droits transitoires en cours',
    ],

    'droits_transitoires_poses' => [
        'titre' => 'Suivre les droits transitoires en cours',
        'role' => 'Chaque ligne est un COMPTE qui bénéficie d’un droit transitoire, avec son '
            .'échéance. Les gestes portent sur tout son droit, jamais sur une capacité isolée.',
        'gestes' => [
            'Ajustez l’échéance pour prolonger ou raccourcir le geste.',
            'Révoquez pour fermer le droit avant terme — cela ferme toutes les capacités ensemble.',
            'Ne cherchez pas à retirer une seule capacité : un sevrage en escalier, une porte fermée le lundi et une autre le jeudi, n’a été décidé par personne.',
        ],
        'vide' => [
            'Aucun droit transitoire n’est ouvert en ce moment.',
            'Tous ceux qui existaient sont arrivés à échéance : ils s’éteignent seuls, sans geste de votre part.',
        ],
        'ensuite_poser' => 'Poser un nouveau droit',
    ],

    'mon_dossier' => [
        'titre' => 'Tenir à jour votre propre compte',
        'role' => 'Vos coordonnées, la langue dans laquelle le back-office vous parle, et votre '
            .'mot de passe. Cet écran ne concerne que vous : il ne touche à aucun compte de '
            .'candidat.',
        'gestes' => [
            'Changez la langue : tout le back-office, guides compris, suit ce réglage.',
            'Changer votre adresse e-mail exige votre mot de passe courant — c’est ce qui protège votre compte si votre session reste ouverte quelque part.',
            'Choisissez un mot de passe long plutôt que compliqué : une phrase se retient et se casse mal.',
        ],
        'vide' => [
            'Cet écran n’a pas de liste : il n’affiche que votre compte.',
        ],
    ],

];
