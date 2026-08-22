<?php

/*
 * Le paquet `filament/support` ne traduit pas ces clés en français — elles
 * n'existent que dans son anglais et son arabe. Comme `app.fallback_locale`
 * vaut `fr` dans ce dépôt, il n'y a rien derrière : une clé absente ne retombe
 * pas sur l'anglais, elle S'IMPRIME. Le panneau des versions d'une offre est la
 * première surface à rendre une section repliable, et le balayage de
 * `PanneauInvariantsTest` l'a vu tout de suite.
 */
return [

    'actions' => [

        'collapse' => [
            'label' => 'Replier la section',
        ],

        'expand' => [
            'label' => 'Déplier la section',
        ],

    ],

];
