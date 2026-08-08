<?php

return [
    'validation_failed' => 'Les données envoyées sont invalides.',
    // PAS-4 : formulation volontairement ambiguë. Distinguer « n'existe pas »
    // de « pas encore publié » laisserait deviner le catalogue à venir.
    'not_found' => "Cette ressource n'existe pas ou n'est pas encore publiée.",
    'forbidden' => "Vous n'avez pas les droits nécessaires pour cette action.",
    'method_not_allowed' => "Cette méthode n'est pas autorisée sur cette ressource.",
    'rate_limited' => 'Trop de requêtes. Patientez avant de réessayer.',
    'csrf_mismatch' => 'Votre session a expiré. Rechargez la page et réessayez.',
    'internal' => "Une erreur interne est survenue. Communiquez l'identifiant de requête au support.",
];
