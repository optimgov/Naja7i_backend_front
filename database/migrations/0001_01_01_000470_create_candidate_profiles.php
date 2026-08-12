<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Le profil candidat — DET-42.
 *
 * L'ÉPREUVE PRÉPARÉE SE DÉCLARE, ELLE NE SE DÉDUIT PLUS. Jusqu'ici, le seul
 * moyen de savoir quelle épreuve un candidat prépare était de regarder sa
 * tentative la plus récente. La déduction est fausse dans un cas ordinaire :
 * qui prépare le CRMEF français et passe un diagnostic de curiosité sur une
 * autre épreuve voit son tableau de bord changer d'allégeance. Le produit
 * savait quelle épreuve avait été TOUCHÉE, jamais laquelle était PRÉPARÉE.
 *
 * IL REMPLACE LA DÉDUCTION, IL NE S'AJOUTE PAS À CÔTÉ. Deux réponses à « quelle
 * épreuve je prépare » se contrediraient un jour, et le désaccord serait
 * d'autant plus difficile à reproduire qu'il dépendrait de l'ordre des
 * tentatives. Quand `exam_id` est nul, le frontend peut PROPOSER la dernière
 * épreuve travaillée — proposer n'est pas décider : le candidat tranche d'un
 * clic, et ce clic écrit ici.
 *
 * TROIS COLONNES, ET C'EST TOUT. Ni préférences d'interface, ni fuseau — DET-33
 * a déjà sa clé de configuration —, ni avatar. Chacune de ces colonnes attend
 * un demandeur ; les ajouter d'avance reviendrait à figer des décisions de
 * produit que personne n'a prises.
 *
 * Table d'ACTIVITÉ : isolée par tenant. Le même compte peut préparer une
 * épreuve en B2C et une autre au sein d'un centre partenaire — l'unicité porte
 * donc sur le couple (tenant, candidat), pas sur le candidat seul.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidate_profiles', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            /* L'épreuve PRÉPARÉE. Nullable : un compte qui vient d'être créé n'a
             * rien choisi, et l'absence de choix n'est pas une anomalie.
             * `restrictOnDelete` comme partout dans le catalogue — une épreuve
             * ne s'efface pas, elle se dépublie. */
            $table->foreignId('exam_id')->nullable()->constrained()->restrictOnDelete();

            /* L'objectif, en toutes lettres. Texte libre et non liste fermée :
             * ce qu'un candidat vise — un rang, un centre, une session — n'est
             * pas tranché dans `docs/`, et inventer ici une énumération
             * trancherait une décision de produit par défaut. Une chaîne courte
             * ne préempte rien. */
            $table->string('objective', 280)->nullable();

            /* L'échéance visée. DATE et non horodatage, pour la même raison que
             * `review_schedules.due_on` : la frontière de journée est celle du
             * candidat, et une heure n'aurait aucun sens sur un concours. */
            $table->date('target_date')->nullable();

            $table->timestampsTz();

            /* UN SEUL PROFIL PAR CANDIDAT ET PAR TENANT. Sans cette contrainte,
             * deux requêtes concurrentes de première déclaration créeraient deux
             * lignes, et « quelle épreuve je prépare » aurait de nouveau deux
             * réponses — le défaut même que ce pas ferme. C'est l'index qui
             * arbitre, pas un `firstOrCreate` optimiste. */
            $table->unique(['tenant_id', 'user_id'], 'candidate_profiles_unique');
        });

        /* L'échéance ne se vise pas dans le passé au moment où on la pose. La
         * garde est volontairement FAIBLE — elle ne repousse pas la date au fil
         * du temps : un profil dont la session est passée reste valide, et le
         * candidat n'a pas à être verrouillé hors de son propre profil parce
         * qu'il a laissé filer une échéance. */
        DB::statement(
            "ALTER TABLE candidate_profiles ADD CONSTRAINT candidate_profiles_target_plausible
             CHECK (target_date IS NULL OR target_date >= DATE '2020-01-01')"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_profiles');
    }
};
