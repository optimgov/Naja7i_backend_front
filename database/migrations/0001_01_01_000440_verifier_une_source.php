<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La vérification est un acte sur LA SOURCE, pas sur la question.
 *
 * DET-46, tranché. `verification` vivait uniquement sur le pivot
 * `question_sources` : chaque citation portait son propre verdict, et aucune
 * route ne le posait jamais. La chaîne éditoriale du PAS-27 allait donc jusqu'à
 * la validation pédagogique puis butait sur une publication de diagnostic que
 * rien ne pouvait débloquer.
 *
 * CE QUI TRANCHE ENTRE LES DEUX PLACEMENTS : une source est citée par plusieurs
 * questions. La vérifier une fois doit profiter à toutes celles qui s'y
 * appuient — en faire un acte par question ferait recontrôler vingt fois le
 * même arrêté ministériel, sans que rien ne garantisse que les vingt verdicts
 * concordent.
 *
 * QUI ET QUAND, ET C'EST LA VALEUR DU CHAMP. Une plateforme qui affirme que son
 * contenu est sourcé doit pouvoir dire par qui la source a été contrôlée. Sans
 * traçabilité, `verified` est une case cochée : elle rassure sans engager
 * personne.
 *
 * Le pivot GARDE son drapeau : c'est lui que lisent `hasVerifiedContentSource`
 * et le trigger de publication, et il enregistre l'état de la citation au
 * moment où elle a été faite. Vérifier une source le propage aux citations non
 * encore gelées — celles des questions publiées ne bougent pas, leur correction
 * a déjà été servie.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->timestampTz('verified_at')->nullable();

            /* `nullOnDelete` : le départ d'un relecteur n'annule pas le
             * contrôle qu'il a fait. On perd le nom, jamais le fait. */
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
        });

        DB::statement(
            'ALTER TABLE sources ADD CONSTRAINT sources_verification_tracee
             CHECK ((verified_at IS NULL) = (verified_by IS NULL))'
        );

        DB::statement(
            'COMMENT ON COLUMN sources.verified_at IS
             $$Date du controle documentaire. Avec verified_by, elle est la valeur du drapeau : une verification anonyme n engage personne.$$'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE sources DROP CONSTRAINT IF EXISTS sources_verification_tracee');

        Schema::table('sources', function (Blueprint $table) {
            $table->dropForeign(['verified_by']);
            $table->dropColumn(['verified_at', 'verified_by']);
        });
    }
};
