<?php

return [
    'invalid_credentials' => "L'adresse e-mail ou le mot de passe est incorrect.",
    'email_already_used' => 'Un compte existe déjà avec cette adresse e-mail.',
    'account_suspended' => 'Ce compte est suspendu. Contactez le support.',
    'unauthenticated' => 'Vous devez être connecté pour accéder à cette ressource.',
    'throttled' => 'Trop de tentatives. Réessayez dans :seconds secondes.',
    'terms_required' => 'Vous devez accepter les conditions générales pour créer un compte.',
    'privacy_required' => 'Vous devez confirmer avoir pris connaissance de la politique de confidentialité.',
    'legal_not_revocable' => "Les conditions générales et la politique de confidentialité ne se retirent pas ici. Pour cesser d'utiliser le service, demandez la suppression de votre compte.",
    'email_not_verified' => 'Confirmez votre adresse e-mail pour continuer. Un lien vous a été envoyé.',

    // --- PAS-3 : vérification d'e-mail et mot de passe oublié ---
    'verification_token_invalid' => "Ce lien de confirmation n'est plus valable. Demandez-en un nouveau.",
    'verification_link_sent' => "Si cette adresse correspond à un compte non confirmé, un lien vient d'être envoyé.",
    'reset_link_sent' => "Si cette adresse correspond à un compte, un lien de réinitialisation vient d'être envoyé.",
    'reset_token_invalid' => "Ce lien de réinitialisation n'est plus valable. Demandez-en un nouveau.",
    'password_updated' => 'Votre mot de passe a été mis à jour. Vous pouvez vous connecter.',
    'current_password_invalid' => 'Le mot de passe actuel est incorrect.',
    'password_identity_required' => 'Ce compte ne possède pas encore d’identité par mot de passe.',
    'invitation_invalid' => "Cette invitation n'est plus valable.",
    'invitation_accepted' => 'Votre invitation est acceptée. Vous pouvez vous connecter.',

    // PAS-11 — refus d'autorisation fine (ADR-0009).
    'permission_denied' => "Vous n'avez pas la permission nécessaire pour cette action.",
];
