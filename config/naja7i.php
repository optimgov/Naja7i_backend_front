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
];
