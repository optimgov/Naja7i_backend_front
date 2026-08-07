<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Partage de ressources entre origines (CORS)
    |--------------------------------------------------------------------------
    |
    | L'authentification repose sur un COOKIE de session (ADR-0004), pas sur un
    | jeton porté par le JavaScript de la page. Un cookie ne traverse une
    | frontière d'origine que si le serveur l'autorise explicitement — d'où
    | `supports_credentials`. Et une origine autorisée à envoyer des
    | identifiants ne peut jamais être `*` : la spécification l'interdit, et
    | c'est heureux. On énumère donc les origines, en les lisant dans
    | l'environnement pour ne pas figer les domaines dans le dépôt.
    |
    | `sanctum/csrf-cookie` figure dans les chemins : c'est la route qui pose le
    | cookie XSRF-TOKEN avant la première écriture.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', env('FRONTEND_URL', 'http://localhost:3000')))
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => ['X-Request-Id'],

    'max_age' => 0,

    'supports_credentials' => true,

];
