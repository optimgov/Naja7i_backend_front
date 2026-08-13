<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * L'échéance est passée : la réponse arrive trop tard.
 *
 * DISTINCTE DE « TENTATIVE CLOSE », et le candidat n'a pas la même conduite
 * dans les deux cas. Une tentative close a été soumise — par lui, ou par un
 * autre onglet ; il n'y a rien à comprendre, la série est finie. Une tentative
 * EXPIRÉE, elle, lui a été retirée par le chronomètre alors qu'il composait
 * encore, et sa dernière réponse est perdue. L'écran doit pouvoir le dire avec
 * ces mots-là, et la file d'envoi hors connexion doit pouvoir présenter le
 * refus sans le confondre avec une erreur de saisie.
 *
 * Les deux se ressemblent — 409, série terminée — et c'est précisément
 * pourquoi ils portent deux codes.
 */
final class AttemptExpired extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Cette tentative a expiré : le temps imparti est écoulé.');
    }
}
