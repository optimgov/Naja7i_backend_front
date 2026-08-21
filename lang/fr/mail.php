<?php

return [
    'signature' => "L'équipe Naja7i.ma",

    'verify' => [
        'subject' => 'Confirmez votre adresse e-mail',
        'greeting' => 'Bienvenue sur Naja7i.ma',
        'line_1' => "Il ne reste qu'une étape : confirmez votre adresse e-mail pour accéder à votre préparation.",
        'action' => 'Confirmer mon adresse',
        'expiry' => 'Ce lien est valable 24 heures.',
        'ignore' => "Si vous n'avez pas créé de compte, vous pouvez ignorer ce message.",
    ],

    'reset' => [
        'subject' => 'Réinitialiser votre mot de passe',
        'greeting' => 'Demande de nouveau mot de passe',
        'line_1' => 'Vous avez demandé à réinitialiser votre mot de passe. Choisissez-en un nouveau en suivant ce lien.',
        'action' => 'Choisir un nouveau mot de passe',
        'expiry' => 'Ce lien est valable 60 minutes.',
        'ignore' => "Si vous n'êtes pas à l'origine de cette demande, ignorez ce message : votre mot de passe reste inchangé.",
    ],

    'staff_invitation' => [
        'subject' => "Invitation à rejoindre l'équipe Naja7i.ma",
        'greeting' => 'Bienvenue dans l’équipe Naja7i.ma',
        'line_1' => 'Un compte personnel a été préparé pour vous. Définissez votre mot de passe pour l’activer.',
        'action' => 'Accepter mon invitation',
        'expiry' => 'Ce lien est valable :hours heures et ne peut être utilisé qu’une fois.',
        'ignore' => 'Si vous n’attendiez pas cette invitation, ignorez ce message.',
    ],
];
