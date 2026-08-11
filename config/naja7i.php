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
];
