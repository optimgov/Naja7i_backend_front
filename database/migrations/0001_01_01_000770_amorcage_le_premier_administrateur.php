<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M-018 — Une invitation peut n'avoir invité personne.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * L'ŒUF SANS POULE
 *
 * `canAccessPanel()` exige au moins une permission, donc une adhésion à un
 * rôle. Sur une base neuve, personne n'en a — et les invitations de personnel
 * ne cassent pas le cercle, puisque les émettre demande déjà un compte
 * autorisé. `staff_invitations.invited_by` était `NOT NULL` : le mécanisme
 * était structurellement incapable de servir au PREMIER compte.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * NUL SE LIT « AUCUN HUMAIN N'A INVITÉ », ET C'EST UN FAIT, PAS UN TROU
 *
 * C'est exactement le raisonnement de `plan_versions.composed_by`, nullable
 * depuis le lot 3A.6 : « une composition par semis ou par migration n'a pas
 * d'auteur, et lui en fabriquer un serait la première ligne fausse du
 * journal ». Ici de même — l'amorçage d'une machine neuve n'a pas d'invitant,
 * et faire pointer l'invitation vers le compte qu'elle crée écrirait qu'il
 * s'est invité lui-même, ce qui est faux.
 *
 * LA NULLITÉ PORTE DONC LE SENS : une invitation sans invitant est une
 * invitation d'amorçage, et il n'y en aura jamais qu'une poignée. On ne pose
 * pas de table de trace pour cela — la ligne se lit déjà.
 *
 * Rien d'autre ne change : le jeton reste opaque, haché, à usage unique et
 * daté ; `accept()` ne regarde pas qui a invité.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE staff_invitations ALTER COLUMN invited_by DROP NOT NULL');
    }

    public function down(): void
    {
        /* Le retour arrière ne peut pas inventer un invitant pour les lignes
         * d'amorçage : on les retire, elles n'appartiennent qu'à ce mécanisme.
         * Une invitation d'amorçage consommée a déjà donné son mot de passe ;
         * la perdre ne retire aucun accès. */
        DB::statement('DELETE FROM staff_invitations WHERE invited_by IS NULL');
        DB::statement('ALTER TABLE staff_invitations ALTER COLUMN invited_by SET NOT NULL');
    }
};
