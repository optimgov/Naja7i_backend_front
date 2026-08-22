<?php

return [
    'coupon_introuvable' => "Ce code n'existe pas. Vérifiez la saisie — les codes ne contiennent ni O ni I ni chiffre 1.",
    'coupon_epuise' => 'Ce code a déjà été utilisé.',
    'coupon_expire' => 'Ce code a expiré.',
    'coupon_pas_encore_valide' => "Ce code n'est pas encore actif.",
    'coupon_revoque' => "Ce code n'est plus valable.",
    'coupon_code_absent' => 'Saisissez un code.',
    'coupon_plan_inactif' => "L'offre associée à ce code n'est plus proposée.",
    'coupon_indisponible' => 'Ce code ne peut pas être utilisé.',
    'coupon_plan_introuvable' => "Cette offre n'existe pas.",
    'coupon_hors_periode' => "Cette offre n'est pas proposée à la vente en ce moment.",
    /*
     * L'ÉLIGIBILITÉ PAR PUBLIC — Q-19, reportée de M-004 aux murs.
     *
     * Sobre par nécessité : le refus ne nomme aucun autre compte et n'apprend
     * rien qui ne soit déjà au catalogue. Il renvoie là où la réponse est —
     * chaque offre y porte sa catégorie de public.
     */
    'coupon_public_non_eligible' => "Cette offre s'adresse à une autre catégorie de candidats. Le catalogue indique celles qui vous sont ouvertes.",

    'coupon_version_indisponible' => "Cette version de l'offre n'est plus disponible. Actualisez la page.",
    'unite_questions' => 'questions',
    'source_essai' => 'Essai',
    'source_transitoire' => 'Accès transitoire',
    'source_achetee' => 'Inclus dans votre abonnement',
    'etat_essai' => 'Essai en cours',
    'etat_actif' => 'Forfait actif',
    'etat_epuise' => 'Forfait terminé',
    'sortie_epuise' => 'Renouvelez ou choisissez un autre forfait pour retrouver vos accès.',
    'en_attente' => 'Votre code est en cours de validation par notre équipe.',
];
