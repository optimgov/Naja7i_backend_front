<?php

return [
    'diagnostic_indisponible' => "Le diagnostic n'est pas encore disponible pour cette épreuve : la banque de questions est en cours de constitution.",
    'correction_avant_soumission' => "La correction n'est visible qu'après avoir terminé la série.",
    'aucune_prediction' => "Naja7i mesure ce que vous avez démontré. Aucune prédiction de réussite au concours n'est produite.",
    'demo_indisponible' => "Aucune démonstration n'est disponible pour l'instant.",
    'demo_avertissement' => "Ceci est un exemple. Vous n'avez pas répondu à cette question et rien n'a été enregistré.",
    'entrainement_perimetre_vide' => "Aucun domaine à travailler n'a pu être déterminé pour cette épreuve.",
    'entrainement_perimetre_etroit' => 'Ce domaine ne compte pas encore assez de questions pour une session utile. Choisissez-en un autre, ou revenez quand la banque se sera étoffée.',
    'question_gelee' => "Le contenu d'une question publiée ou retirée est gelé : créez une nouvelle version.",
    'miroir_deja_ouvert' => "Un autre miroir est déjà en cours. Terminez-le avant d'en ouvrir un nouveau.",
    'miroir_sans_objet' => "Cette question n'a pas d'erreur à vérifier : soit vous y avez répondu juste, soit la série n'est pas encore terminée.",
    'miroir_indisponible' => "Aucune autre question ne travaille encore ce point. La banque s'étoffe ; rien ne vous manque de votre côté.",
    'cle_idempotence_reutilisee' => 'Cette demande a déjà servi pour une autre opération. Relancez-la avec une nouvelle clé.',
    'revision_rien_echu' => "Vous êtes à jour : aucune révision n'est prévue aujourd'hui.",
    'revision_sans_question_soeur' => "Vos révisions du jour n'ont pas encore de question disponible. La banque s'étoffe ; rien ne vous manque de votre côté.",
    'simulation_indisponible' => "L'examen blanc n'est pas encore disponible pour cette épreuve : la banque ne compte pas assez de questions publiées.",
    'simulation_duree_inconnue' => "La durée officielle de cette épreuve n'est pas encore établie. Un examen blanc ne peut pas être chronométré sans elle.",
    'simulation_base_de_notation' => "Cette note porte sur une série composée selon les poids officiels des domaines de l'épreuve. Elle mesure ce que vous avez démontré aujourd'hui, sur cette série.",

    /*
     * LA MÊME PHRASE, SANS LE MOT « OFFICIELS » — DET-60.
     *
     * Servie tant que la source des poids n'est pas vérifiée sur pièce. Le
     * descriptif qui les porte est nommé, daté et paginé, mais personne dans ce
     * dépôt ne l'a lu : dire « officiels » au candidat serait lui promettre une
     * fidélité que nous ne pouvons pas établir.
     *
     * On ne dit pas non plus « non officiels », ce qui serait faux dans l'autre
     * sens. On dit ce qui est vrai : les poids viennent du descriptif, et nous
     * ne les avons pas vérifiés.
     */
    'simulation_base_de_notation_rapportee' => "Cette note porte sur une série composée selon les poids des domaines rapportés par le descriptif de l'épreuve, que nous n'avons pas pu vérifier sur pièce. Elle mesure ce que vous avez démontré aujourd'hui, sur cette série.",
    'simulation_bareme_non_officiel' => "Le barème officiel du concours n'est pas public : cette note est exprimée en pourcentage pondéré, elle n'est pas une note sur 20.",
    /*
     * LE REFUS DU MUR PAYANT — lot 3A.9.
     *
     * Il nomme ce qui l'ouvrirait, et rien de plus : ni prix, ni palier, ni
     * « à partir de ». Le catalogue porte les offres et leurs montants ; les
     * répéter ici les ferait vieillir dans un fichier de langue.
     */
    'capacite_requise' => "Cette fonction n'est pas comprise dans votre accès actuel : elle demande « :capacite ». Le catalogue des offres indique lesquelles l'ouvrent.",

    'tentative_expiree' => "Le temps imparti est écoulé. Cette réponse n'a pas été enregistrée, et l'épreuve a été clôturée.",
];
