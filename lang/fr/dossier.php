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
        'current_password_for_email' => 'Mot de passe actuel',
        'current_password_for_email_help' => 'Obligatoire uniquement pour modifier votre e-mail.',
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
    // ── Les niveaux académiques, liste fermée (NiveauxAcademiques) ──
    'niveau_tronc-commun' => 'Lycée — tronc commun',
    'niveau_premiere-bac' => 'Lycée — 1re année du baccalauréat',
    'niveau_deuxieme-bac' => 'Lycée — 2e année du baccalauréat',
    'niveau_bac-obtenu' => 'Baccalauréat obtenu',
    'niveau_licence' => 'Licence',
    'niveau_master' => 'Master',
    'niveau_doctorat' => 'Doctorat',
    'niveau_enseignant-en-poste' => 'Enseignant en poste',
    'niveau_autre' => 'Autre',
    'niveau_choisir' => 'Choisir votre niveau',
    'niveau_aide' => 'Votre niveau décide de ce que la plateforme vous propose : un élève du lycée prépare son année scolaire, les autres préparent un concours.',

];
