<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Vérification de l'e-mail
    |--------------------------------------------------------------------------
    | Décision OptimGov du 7 août 2026 : bloquant dès l'inscription.
    |
    | Réserve d'architecture consignée : c'est le point de friction le plus
    | coûteux d'un tunnel d'inscription, et le plan à 90 jours mesure
    | précisément l'activation (diagnostic terminé + recommandation consultée).
    | Le réglage est donc externalisé : si le pilote montre un décrochage entre
    | inscription et diagnostic, on bascule sans toucher au code.
    |
    | Valeurs : 'registration' (bloquant immédiat)
    |           'after_diagnostic' (premier diagnostic autorisé, puis bloquant)
    |           'never' (jamais bloquant, sauf achat et certification)
    */
    'email_verification_gate' => env('EMAIL_VERIFICATION_GATE', 'registration'),

    /*
    |--------------------------------------------------------------------------
    | Politique de mot de passe
    |--------------------------------------------------------------------------
    | 12 caractères, aucune règle de composition, vérification obligatoire
    | contre les bases de fuites connues.
    |
    | ÉCART ASSUMÉ : NIST SP 800-63B-4 exige 15 caractères pour une
    | authentification à facteur unique. Nous retenons 12 pour limiter la
    | friction à l'inscription, en compensant par le contrôle anti-fuite —
    | qui écarte les mots de passe réellement dangereux bien mieux que la
    | longueur seule. Nous ne revendiquons donc PAS la conformité NIST tant
    | qu'un second facteur n'est pas proposé. Voir ADR-0007.
    */
    'password' => [
        'min_length' => env('PASSWORD_MIN_LENGTH', 12),
        'max_length' => 128,
        'check_compromised' => env('PASSWORD_CHECK_COMPROMISED', true),
    ],

    'staff_invitation' => [
        'expire_hours' => env('STAFF_INVITATION_EXPIRE_HOURS', 24),
    ],

    /*
    |--------------------------------------------------------------------------
    | Limitation des tentatives de connexion
    |--------------------------------------------------------------------------
    | Trois agrégats indépendants (correction D2-08) : sans cela, un attaquant
    | répartit ses tentatives sur plusieurs IP ou plusieurs adresses e-mail et
    | passe sous chaque seuil pris isolément.
    */
    'login_throttle' => [
        'per_email_ip' => ['attempts' => 5,   'decay_seconds' => 300],
        'per_email' => ['attempts' => 10,  'decay_seconds' => 900],
        'per_ip' => ['attempts' => 30,  'decay_seconds' => 900],
    ],

    /*
    |--------------------------------------------------------------------------
    | Quota gratuit de causes d'erreur révélées
    |--------------------------------------------------------------------------
    | Fiche F03 : un compte gratuit voit la cause de ses erreurs deux fois.
    | Le décompte est CUMULATIF et n'est jamais remis à zéro — un compteur
    | quotidien laisserait le candidat attendre le lendemain plutôt que
    | s'abonner, et viderait la règle de son sens.
    |
    | Externalisé parce que c'est un curseur commercial, pas une constante
    | technique : le pilote dira si deux est le bon seuil.
    */
    'free_cause_quota' => env('FREE_CAUSE_QUOTA', 2),

    /*
    |--------------------------------------------------------------------------
    | Fuseau horaire du candidat — frontière de journée (F07)
    |--------------------------------------------------------------------------
    | « Échu aujourd'hui » suppose de savoir quand commence aujourd'hui. Le
    | projet n'avait aucun traitement de fuseau : `app.timezone` est en UTC, ce
    | qui décale la frontière d'une heure sur le Maroc et ferait apparaître les
    | rendez-vous à 01h00 locale.
    |
    | Clé GLOBALE et non colonne d'utilisateur : l'audience est marocaine, et
    | une préférence par compte serait une complexité sans demandeur. Le jour où
    | un candidat de la diaspora s'en plaindra, cette clé devient une colonne —
    | voir la dette.
    |
    | Quand le module Opportunités arrivera, il consomme cette clé plutôt que
    | d'en déclarer une seconde.
    */
    'timezone_candidat' => env('TIMEZONE_CANDIDAT', 'Africa/Casablanca'),

    /*
    |--------------------------------------------------------------------------
    | Examen blanc
    |--------------------------------------------------------------------------
    | LE NOMBRE DE QUESTIONS N'EST PAS OFFICIEL, ET C'EST TOUT LE PROBLÈME.
    |
    | `blueprints.official_question_count` est NUL pour les trois épreuves, et
    | `official_scoring_note_fr` dit explicitement « Barème détaillé non précisé
    | par le descriptif ». Les descriptifs 2025 donnent les domaines et leurs
    | poids, jamais le format de l'épreuve.
    |
    | Le simulateur a pourtant besoin d'un nombre. On l'externalise ici plutôt
    | que de l'écrire en dur, et le rapport publie l'absence de barème officiel
    | à côté du score : le candidat sait que la LONGUEUR est une convention du
    | produit, quand la RÉPARTITION, elle, suit les poids officiels.
    |
    | Le jour où un descriptif donne le nombre réel, cette clé devient une
    | lecture de `official_question_count` — et le simulateur cesse d'avoir une
    | convention à assumer.
    */
    'simulation' => [
        'default_question_count' => env('SIMULATION_QUESTION_COUNT', 20),
    ],

    /*
    |--------------------------------------------------------------------------
    | Limitation de débit des routes — DEUX PROFILS, ET UNE CLÉ PAR LIMITEUR
    |--------------------------------------------------------------------------
    | Ces seuils étaient écrits en clair dans `routes/api.php` sous la forme
    | `throttle:6,1`. Deux défauts, découverts par la première exécution de la
    | recette de bout en bout en intégration continue :
    |
    | 1. LE COMPTEUR ÉTAIT PARTAGÉ. Pour une requête sans session, la signature
    |    de `ThrottleRequests` est `sha1(domaine|ip)` — elle ne contient PAS la
    |    route. Toutes les routes publiques comptaient donc dans le MÊME seau,
    |    chacune le comparant à son propre plafond : le plus bas des plafonds
    |    (`register`, 6) fermait la porte à tous les autres. Cinq requêtes
    |    d'ouverture de session suffisaient à faire refuser une inscription.
    |    Chaque limiteur nommé porte maintenant son propre espace de clé.
    |
    | 2. AUCUN RÉGLAGE POSSIBLE PAR ENVIRONNEMENT. La recette rejoue en quelques
    |    secondes ce qu'un candidat fait en une heure ; elle attendait donc la
    |    fenêtre, 260 s d'attente pure sur 521. Le profil `recette` relève les
    |    seuils de TRANSPORT — et eux seuls.
    |
    | CE QUE LE PROFIL `recette` NE RELÈVE JAMAIS :
    |
    |   - `reponse`, la route qu'écoule la file d'envoi hors connexion. Elle
    |     garde 120/min dans tous les profils : c'est la seule route dont un
    |     vrai 429 a déjà produit un faux vert, et la recette doit continuer de
    |     rencontrer un limiteur réel.
    |   - les limiteurs de SÉCURITÉ, qui ne sont pas ici : `LoginThrottle`
    |     (trois agrégats, `login_throttle` ci-dessus) et le limiteur par
    |     adresse du renvoi de vérification (3 par 900 s, dans son contrôleur).
    |     Ceux-là vivent dans le domaine, pas dans le transport, et restent
    |     réels en recette comme en production. Le profil ne les voit pas.
    |
    | `production` est le défaut. Aucun environnement ne bascule sans le dire.
    */
    'rate_limits' => [
        'profile' => env('RATE_LIMIT_PROFILE', 'production'),

        'limits' => [
            // route publique                  produit   recette
            'demonstration' => ['production' => 30, 'recette' => 600],
            'email-verify' => ['production' => 10, 'recette' => 600],
            'email-resend' => ['production' => 6, 'recette' => 600],
            'password-request' => ['production' => 6, 'recette' => 600],
            'password-reset' => ['production' => 10, 'recette' => 600],
            'staff-invitation' => ['production' => 10, 'recette' => 600],
            'register' => ['production' => 6, 'recette' => 600],
            'login' => ['production' => 20, 'recette' => 600],

            // ouverture d'une série : diagnostic, entraînement, séance mémoire
            'ouverture-serie' => ['production' => 10, 'recette' => 600],
            'miroir' => ['production' => 20, 'recette' => 600],
            'profil' => ['production' => 30, 'recette' => 600],

            /* La saisie d'un coupon est le geste le plus sensible de la
             * surface commerciale : un code se devine par force brute. Le
             * seuil est BAS, et il est relevé en recette comme les autres
             * limiteurs de transport — le vrai garde est l'entropie du code
             * (~57 bits), pas ce compteur. */
            'coupon' => ['production' => 10, 'recette' => 600],

            // JAMAIS relevé — voir l'encadré ci-dessus.
            'reponse' => ['production' => 120, 'recette' => 120],
        ],
    ],
];
