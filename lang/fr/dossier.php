<?php

return [
    'navigation' => 'Mon dossier',
    'title' => 'Mon dossier',
    'sections' => [
        'contact' => 'Mes coordonnées',
        'account' => 'État du compte',
        'password' => 'Changer mon mot de passe',
    ],
    'fields' => [
        'email' => 'E-mail',
        'phone' => 'Téléphone (E.164)',
        'locale' => 'Langue',
        'email_verification' => 'Vérification de l’e-mail',
        'phone_verification' => 'Vérification du téléphone',
        'status' => 'État',
        'roles' => 'Rôles dans ce tenant',
        'current_password' => 'Mot de passe actuel',
        'password' => 'Nouveau mot de passe',
        'password_confirmation' => 'Confirmer le nouveau mot de passe',
    ],
    'locales' => ['fr' => 'Français', 'ar' => 'العربية'],
    'statuses' => [
        'active' => 'Actif',
        'suspended' => 'Suspendu',
        'deletion_requested' => 'Suppression demandée',
        'anonymized' => 'Anonymisé',
    ],
    'verification' => ['verified' => 'Vérifié', 'unverified' => 'Non vérifié'],
    'actions' => [
        'save_account' => 'Enregistrer mes coordonnées',
        'save_password' => 'Changer mon mot de passe',
    ],
    'notifications' => [
        'account_saved' => 'Vos coordonnées ont été enregistrées.',
        'password_saved' => 'Votre mot de passe a été modifié.',
    ],
];
