<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Lot 3A.5 — Le quota ne se saisit pas, il se sélectionne.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * POURQUOI UN OBJET, ET PAS UNE COLONNE SUR L'OFFRE
 *
 * L'ADR-0027 le pose : « Les quotas sont des objets distincts, typés et
 * bornés : unité, valeur, fenêtre et portée. Une unité inconnue ou une valeur
 * hors bornes est refusée. Il n'existe ni JSON libre, ni valeur sentinelle
 * négative pour illimité. »
 *
 * La raison est un partage de responsabilité, pas une élégance de modèle :
 * le quota est un réglage PÉDAGOGIQUE (A-03) qui vit sur un objet COMMERCIAL.
 * L'admin pédagogique DÉFINIT des profils bornés ; l'admin commerciale en
 * SÉLECTIONNE un ; le serveur REFUSE toute valeur hors borne, quel que soit le
 * chemin. Sans cette séparation, un quota de 3 se saisit pour améliorer une
 * conversion, et la carte de maîtrise devient invisible avant achat.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * CE QUI EST ICI, ET CE QUI N'Y EST PAS
 *
 * La PORTÉE n'est pas une colonne. Décision Q-20 : « l'enveloppe est portée
 * par le droit et sa portée », donc un profil n'en déclare aucune — il hérite
 * de celle du droit qui le matérialisera. Une colonne de portée créerait une
 * seconde source de vérité en face de `access_grants.scope_type`.
 *
 * Le RATTACHEMENT À UNE VERSION D'OFFRE n'est pas ici non plus : c'est le
 * geste de l'admin commerciale, et il appartient au pas suivant. Ce pas livre
 * le registre et ses gardes ; rien n'y sélectionne encore.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * LES BORNES SONT EN BASE, PAS DANS UN FORMULAIRE
 *
 * `min_value <= value <= max_value` est une CONTRAINTE. Un service se
 * contourne — une console, un correctif à chaud, une seeder de démonstration —
 * une contrainte non. Et la borne basse ne vaut que par sa JUSTIFICATION
 * ÉCRITE : `MasteryScore::SEUIL_FAIBLE = 5` fait que sous cinq réponses par
 * nœud aucun score ne s'affiche. Un quota trop bas rend la carte de maîtrise
 * invisible avant achat, et le candidat repart convaincu que le produit ne
 * fonctionne pas. Une borne sans justification est donc refusée par la base
 * elle-même, pas seulement par l'écran qui la saisit.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE TYPE quota_unit AS ENUM ('questions')");
        DB::statement("CREATE TYPE quota_periodicity AS ENUM ('cumulative_grant')");
        DB::statement(<<<'SQL'
            CREATE TYPE quota_profile_event_type AS ENUM (
                'defined',
                'renamed',
                'value_changed',
                'bounds_changed',
                'availability_changed'
            )
            SQL);

        Schema::create('quota_profiles', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            /* Stable et lisible : il désignera le profil dans une version
             * d'offre et dans les enveloppes qui en découleront. */
            $table->string('code', 64)->unique();

            $table->string('name_fr');
            $table->string('name_ar');

            $table->unsignedInteger('value');
            $table->unsignedInteger('min_value');
            $table->unsignedInteger('max_value');

            /* Écrites, pas cochées. Le registre des paramètres pédagogiques
             * demande une justification opposable, relisible sans nous. */
            $table->text('min_justification');
            $table->text('max_justification');

            /* Retiré de la sélection, jamais supprimé : une version d'offre
             * pourra le référencer, et ce qui a été vendu ne s'efface pas. */
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('position')->default(0);

            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE quota_profiles ADD COLUMN unit quota_unit NOT NULL');
        DB::statement('ALTER TABLE quota_profiles ADD COLUMN periodicity quota_periodicity NOT NULL');

        DB::statement(<<<'SQL'
            ALTER TABLE quota_profiles
            ADD CONSTRAINT quota_profiles_value_within_bounds
            CHECK (min_value > 0 AND min_value <= value AND value <= max_value)
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE quota_profiles
            ADD CONSTRAINT quota_profiles_bounds_justified
            CHECK (
                length(btrim(min_justification)) >= 20
                AND length(btrim(max_justification)) >= 20
            )
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE quota_profiles
            ADD CONSTRAINT quota_profiles_code_format
            CHECK (code ~ '^[a-z][a-z0-9-]{2,63}$')
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE quota_profiles
            ADD CONSTRAINT quota_profiles_named_in_both_languages
            CHECK (btrim(name_fr) <> '' AND btrim(name_ar) <> '')
            SQL);

        Schema::create('quota_profile_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('quota_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->jsonb('before')->default(DB::raw("'{}'::jsonb"));
            $table->jsonb('after')->default(DB::raw("'{}'::jsonb"));
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['quota_profile_id', 'occurred_at'], 'quota_profile_events_timeline_idx');
        });

        DB::statement(
            'ALTER TABLE quota_profile_events
             ADD COLUMN event_type quota_profile_event_type NOT NULL'
        );

        DB::statement(
            "ALTER TABLE quota_profile_events
             ADD CONSTRAINT quota_profile_events_payload_objects
             CHECK (jsonb_typeof(before) = 'object' AND jsonb_typeof(after) = 'object')"
        );

        $this->protegerLeRegistre();
        $this->semerLeProfilDecouverte();
    }

    /**
     * Un profil ne se supprime pas, et son journal ne se réécrit pas.
     *
     * Le journal est la seule preuve qu'une borne a été abaissée avec sa
     * raison. Un journal modifiable prouve ce qu'on veut, donc rien.
     */
    private function protegerLeRegistre(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION refuse_quota_profile_deletion()
            RETURNS TRIGGER AS $$
            BEGIN
                RAISE EXCEPTION 'Un profil de quota se retire de la sélection, il ne se supprime jamais.';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER quota_profiles_never_deleted
                BEFORE DELETE ON quota_profiles
                FOR EACH ROW EXECUTE FUNCTION refuse_quota_profile_deletion();

            CREATE OR REPLACE FUNCTION refuse_quota_profile_event_mutation()
            RETURNS TRIGGER AS $$
            BEGIN
                RAISE EXCEPTION 'Le journal des profils de quota est en ajout seul.';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER quota_profile_events_append_only
                BEFORE UPDATE OR DELETE ON quota_profile_events
                FOR EACH ROW EXECUTE FUNCTION refuse_quota_profile_event_mutation();
        SQL);
    }

    /**
     * « Découverte » — le profil décidé, semé comme donnée de référence.
     *
     * Les quatre nombres viennent des décisions, pas de nous : l'ADR-0027 fixe
     * la valeur de départ recommandée à 40 questions cumulatives, et le
     * scénario S-16 donne le profil au complet — borne basse 35, borne haute
     * 120. Le semer ici plutôt que d'attendre une saisie évite qu'un pas
     * ultérieur ait à inventer une valeur pour l'offre gratuite.
     *
     * IL N'EST RATTACHÉ À AUCUN ÉVÉNEMENT DE JOURNAL, et c'est exact : le
     * journal conserve des gestes HUMAINS, avec l'auteur qui les a posés. Une
     * migration n'a pas d'auteur ; lui en fabriquer un serait la première
     * ligne fausse du registre. Le profil semé est une donnée de référence, au
     * même titre que `capability_definitions`.
     */
    private function semerLeProfilDecouverte(): void
    {
        $maintenant = now();

        DB::table('quota_profiles')->insert([
            'uuid' => (string) Str::uuid7(),
            'code' => 'decouverte',
            'name_fr' => 'Découverte',
            'name_ar' => 'الاكتشاف',
            'unit' => 'questions',
            'periodicity' => 'cumulative_grant',
            'value' => 40,
            'min_value' => 35,
            'max_value' => 120,
            'min_justification' => 'Sous 35 questions, la carte de maîtrise reste vide : '
                .'le produit exige cinq réponses par nœud avant d’afficher un score, et un '
                .'candidat qui n’a jamais vu la carte conclut que le produit ne fonctionne pas '
                .'au lieu de conclure qu’il n’a pas encore assez répondu.',
            'max_justification' => 'Au-delà de 120 questions gratuites, la découverte cesse '
                .'d’être un aperçu : le palier payant ne vend plus rien de nouveau au candidat '
                .'qui a déjà couvert l’essentiel de son épreuve sans payer.',
            'active' => true,
            'position' => 10,
            'created_at' => $maintenant,
            'updated_at' => $maintenant,
        ]);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS quota_profile_events_append_only ON quota_profile_events');
        DB::unprepared('DROP FUNCTION IF EXISTS refuse_quota_profile_event_mutation()');
        DB::unprepared('DROP TRIGGER IF EXISTS quota_profiles_never_deleted ON quota_profiles');
        DB::unprepared('DROP FUNCTION IF EXISTS refuse_quota_profile_deletion()');

        Schema::dropIfExists('quota_profile_events');
        Schema::dropIfExists('quota_profiles');

        DB::statement('DROP TYPE IF EXISTS quota_profile_event_type');
        DB::statement('DROP TYPE IF EXISTS quota_periodicity');
        DB::statement('DROP TYPE IF EXISTS quota_unit');
    }
};
