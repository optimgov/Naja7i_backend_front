<?php

namespace App\Support;

/**
 * LES NIVEAUX QU'UN CANDIDAT PEUT DÉCLARER — et ce qu'ils décident.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * POURQUOI UNE LISTE FERMÉE PLUTÔT QU'UN CHAMP LIBRE
 *
 * `academic_level` était une chaîne libre de 150 caractères. Mesuré sur la
 * préproduction le 29 août : un compte portait « tronc commun », un autre
 * rien du tout. Rien ne pouvait en être déduit, et surtout pas la seule chose
 * qui compte — CE QUE LA PERSONNE PRÉPARE.
 *
 * Conséquence observée, et elle était absurde : un lycéen de tronc commun a dû
 * déclarer qu'il préparait « CRMEF — Spécialité Langue française », un concours
 * de recrutement d'enseignants, uniquement pour que son dossier soit accepté.
 * C'était la seule liste qu'on lui proposait.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * CE QUE LE NIVEAU DÉCIDE
 *
 * Un niveau de LYCÉE dit « je suis élève, je prépare mon année scolaire ». Un
 * niveau POST-BAC dit « je prépare un concours ». Les deux ne voient pas le
 * même catalogue et n'ont pas les mêmes obligations : on ne demande pas à un
 * lycéen de choisir un concours pour pouvoir entrer.
 *
 * La liste est fermée pour que cette déduction soit possible. Elle reste
 * ouverte par le bas : `autre` existe, et n'emporte aucune conclusion.
 */
final class NiveauxAcademiques
{
    /** Les niveaux du lycée marocain. Déclarer l'un d'eux, c'est être élève. */
    public const LYCEE = [
        'tronc-commun',
        'premiere-bac',
        'deuxieme-bac',
    ];

    /** Les niveaux qui suivent le baccalauréat. */
    public const POST_BAC = [
        'bac-obtenu',
        'licence',
        'master',
        'doctorat',
        'enseignant-en-poste',
    ];

    /** N'emporte aucune déduction : la personne dira elle-même ce qu'elle prépare. */
    public const AUTRE = 'autre';

    /** @return list<string> */
    public static function tous(): array
    {
        return [...self::LYCEE, ...self::POST_BAC, self::AUTRE];
    }

    /**
     * LA QUESTION QUE TOUT LE RESTE POSE : parle-t-on à un lycéen ?
     *
     * Un niveau inconnu ou vide rend `false` — on ne suppose pas un statut
     * scolaire à quelqu'un qui n'en a pas déclaré.
     */
    public static function estLyceen(?string $niveau): bool
    {
        return $niveau !== null && in_array($niveau, self::LYCEE, true);
    }
}
