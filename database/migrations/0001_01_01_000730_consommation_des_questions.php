<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lot 3B, pas 1 — La consommation des questions.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * LE RELIQUAT EST DÉRIVÉ, JAMAIS STOCKÉ
 *
 * Il n'y a ici aucune colonne `remaining`, aucun compteur à décrémenter. Le
 * reliquat se lit :
 *
 *     quota_value − count(consommations rattachées à ce droit)
 *
 * La raison est celle qui gouverne déjà l'état commercial (ADR-0033) : un
 * second dépositaire de la vérité finit toujours par diverger du premier, et
 * c'est alors le faux qui s'affiche. Un compteur stocké aurait à être corrigé
 * après chaque incident ; une somme n'a jamais à l'être.
 *
 * `CauseRevealCounter` est un compteur stocké, et il N'EST PAS le modèle à
 * suivre : il compte un cumul à vie, sans fenêtre ni droit porteur, donc sans
 * rien à recalculer.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * L'UNICITÉ EST UNE CONTRAINTE, PAS UN GARDE-FOU DE SERVICE
 *
 * `UNIQUE (user_id, attempt_id, item_id)` est ce qui rend le débit idempotent.
 * Un `if` dans un service se contourne — par une commande, par un job, par un
 * futur chemin d'ouverture — une contrainte non. Le rejeu d'une même ouverture
 * est donc absorbé PAR LA BASE, et le service se contente de ne pas s'en
 * émouvoir.
 *
 * Le triplet porte `attempt_id` alors que `item_id` le détermine déjà. C'est
 * voulu : la clé dit la règle telle qu'elle se lit — « une unité par item servi
 * dans une tentative pour ce compte » — et une clé qui se lit se relit.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * PAS DE `tenant_id`, COMME LES DROITS
 *
 * `access_grants` n'en porte pas : un droit appartient à la PERSONNE, pas à
 * l'organisme où elle est passée. Le débit suit le droit. Poser un
 * `tenant_id` ici ferait dépendre un reliquat du centre par lequel le candidat
 * s'est connecté, ce qui serait faux et invisible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('question_consumptions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attempt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('attempt_items')->cascadeOnDelete();

            /* L'ENVELOPPE DÉBITÉE — nulle quand la consommation fut LIBRE.
             *
             * Nul ne veut pas dire « on ne sait pas » : il dit « aucune
             * enveloppe ne gouvernait, la consommation était illimitée ». La
             * ligne existe quand même, et c'est ce qui rend l'idempotence
             * uniforme : le rejeu d'une ouverture libre est absorbé par la
             * même contrainte que le rejeu d'une ouverture débitée.
             *
             * `restrictOnDelete` : un droit qui a été débité ne s'efface pas.
             * On clôt un droit, on ne le supprime pas (Q-17) — et effacer
             * celui-ci réécrirait un reliquat déjà opposé au candidat. */
            $table->foreignId('access_grant_id')->nullable()
                ->constrained('access_grants')->restrictOnDelete();

            $table->timestampTz('consumed_at');

            /* LA RÈGLE, EN BASE. Une unité par item servi, et une seule. */
            $table->unique(['user_id', 'attempt_id', 'item_id'], 'question_consumptions_unique_service');

            /* LA LECTURE DU RELIQUAT, et rien d'autre : compter les lignes
             * d'un droit. `consumed_at` la complète pour le jour où une
             * fenêtre glissante existera — elle ne coûte rien aujourd'hui. */
            $table->index(['access_grant_id', 'consumed_at'], 'question_consumptions_par_droit_idx');
        });

        DB::statement('ALTER TABLE question_consumptions ALTER COLUMN uuid SET DEFAULT uuid7()');
    }

    public function down(): void
    {
        Schema::dropIfExists('question_consumptions');
    }
};
