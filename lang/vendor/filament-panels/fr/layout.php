<?php

return [
    'direction' => 'ltr',
    'skip_to_content' => [
        'label' => 'Aller au contenu',
    ],
    'actions' => [
        'billing' => [
            'label' => 'Gérer l\'abonnement',
        ],
        'logout' => [
            'label' => 'Déconnexion',
        ],
        'open_database_notifications' => [
            'label' => 'Ouvrir les notifications',
            'label_with_unread_count' => '{1} Notifications, :count notification non lue|[2,*] Notifications, :count notifications non lues',
        ],
        'open_user_menu' => [
            'label' => 'Menu utilisateur',
        ],
        'sidebar' => [
            'collapse' => [
                'label' => 'Réduire la barre latérale',
            ],
            'expand' => [
                'label' => 'Agrandir la barre latérale',
            ],
        ],
        'theme_switcher' => [
            'dark' => [
                'label' => 'Activer le mode sombre',
            ],
            'light' => [
                'label' => 'Désactiver le mode sombre',
            ],
            'system' => [
                'label' => 'Activer le thème système',
            ],
            'label' => 'Thème',
        ],
    ],
    'avatar' => [
        'alt' => 'Avatar de :name',
    ],
    'logo' => [
        'alt' => 'Logo de :name',
    ],
    'navigation' => [
        'label' => 'Navigation latérale',
    ],
    'topbar' => [
        'label' => 'Barre supérieure',
    ],
    'tenant_menu' => [
        'search_field' => [
            'label' => 'Recherche d’organisme',
            'placeholder' => 'Rechercher',
        ],
    ],
];
