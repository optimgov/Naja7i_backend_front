<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Une annale importée n'a pas de justification, et ne doit pas pouvoir se
 * publier pour autant.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * LA GARDE EXISTAIT, ET C'EST UNE BONNE NOUVELLE
 *
 * `question_options.rationale` était NOT NULL. L'import des annales s'y est
 * heurté au premier enregistrement — et c'est le comportement correct : F04
 * exige une justification par option, le schéma la rendait obligatoire dès
 * l'écriture.
 *
 * Mais elle était obligatoire AU MAUVAIS MOMENT. Une annale entre en file
 * éditoriale sans corrigé : le corpus n'en contient aucun. Exiger la
 * justification à l'INSERTION revient à interdire d'importer ce qu'on veut
 * faire relire — c'est-à-dire à interdire la file elle-même.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * ON DÉPLACE L'EXIGENCE, ON NE LA RETIRE PAS
 *
 * La colonne devient nullable. La publication, elle, la redemande — et le
 * déclencheur devait être corrigé pour cela, car il comptait
 * `length(btrim(rationale)) = 0`, ce qui est NUL sur une valeur nulle : un
 * `FILTER (WHERE …)` n'incrémente pas sur NULL. **Rendre la colonne nullable
 * sans toucher au déclencheur aurait donc ouvert un trou** — une option sans
 * aucune justification serait passée à la publication, là où une option à
 * justification VIDE était refusée.
 *
 * C'est le genre d'assouplissement qui se paie trois mois plus tard. Le
 * déclencheur compte désormais `rationale IS NULL OR length(btrim(...)) = 0`.
 *
 * Le service `QuestionIntegrityChecker` était déjà correct : `trim((string)
 * $option->rationale) === ''` traite NULL comme vide.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * CE QUI BLOQUE UNE ANNALE IMPORTÉE, ET C'EST TRIPLE
 *
 * Une question importée porte zéro option correcte, aucune justification et
 * aucune cause de distracteur. Trois refus indépendants l'attendent donc à la
 * publication — « exactement une bonne réponse est attendue (0 trouvée) »,
 * « N option(s) sans justification », et pour le diagnostic « N distracteur(s)
 * sans cause d'erreur ». Un test le prouve dans les deux sens : le brouillon
 * existe, et il ne peut pas sortir.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE question_options ALTER COLUMN rationale DROP NOT NULL');

        DB::statement(
            'COMMENT ON COLUMN question_options.rationale IS
             $$Justification F04. NULLE seulement sur un brouillon — typiquement une annale importee,
             que le corpus fournit sans corrige. La publication la redemande.$$'
        );

        $this->gardeDePublication();
    }

    public function down(): void
    {
        DB::statement("UPDATE question_options SET rationale = '' WHERE rationale IS NULL");
        DB::statement('ALTER TABLE question_options ALTER COLUMN rationale SET NOT NULL');
    }

    /**
     * Reconduite à l'identique du PAS-38, à une ligne près : le décompte des
     * justifications manquantes compte désormais les valeurs NULLES.
     */
    private function gardeDePublication(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION assert_question_publishable()
            RETURNS TRIGGER AS $$
            DECLARE
                nb_options       int;
                nb_correctes     int;
                nb_sans_justif   int;
                nb_sans_cause    int;
                nb_sources_ok    int;
                options_attendu  int;
                statut_precedent question_status;
                ignore           bigint;
            BEGIN
                IF NEW.status <> 'published' THEN
                    RETURN NEW;
                END IF;

                statut_precedent := CASE WHEN TG_OP = 'UPDATE' THEN OLD.status ELSE NULL END;

                IF statut_precedent = 'published' THEN
                    RETURN NEW;
                END IF;

                IF TG_OP = 'INSERT' OR statut_precedent IS DISTINCT FROM 'pedagogically_validated' THEN
                    RAISE EXCEPTION
                        'Une question ne se publie que depuis l''état « validée pédagogiquement » (état actuel : %).',
                        COALESCE(statut_precedent::text, 'création directe');
                END IF;

                IF NEW.validator_id IS NULL THEN
                    RAISE EXCEPTION 'Publication refusée : aucun valideur enregistré.';
                END IF;

                IF NEW.validator_id = NEW.author_id THEN
                    RAISE EXCEPTION
                        'Publication refusée : le valideur ne peut pas être l''auteur (METHODE §7.2).';
                END IF;

                /* Verrou sur les enfants AVANT de les compter. Sans lui, une
                 * suppression concurrente non validée reste invisible et la
                 * question se publie sur un décompte périmé.
                 * La ligne parente est déjà verrouillée par l'UPDATE en cours :
                 * l'ordre parent → enfants est donc respecté. */
                PERFORM 1 FROM question_options WHERE question_id = NEW.id FOR UPDATE;
                PERFORM 1 FROM question_sources WHERE question_id = NEW.id FOR UPDATE;

                SELECT count(*),
                       count(*) FILTER (WHERE is_correct),
                       count(*) FILTER (WHERE rationale IS NULL OR length(btrim(rationale)) = 0),
                       count(*) FILTER (WHERE NOT is_correct AND cause IS NULL)
                INTO nb_options, nb_correctes, nb_sans_justif, nb_sans_cause
                FROM question_options WHERE question_id = NEW.id;

                /* Le nombre d'options ANNONCÉ par l'épreuve, quand elle
                 * l'annonce. Sinon le plancher de structure. */
                SELECT options_count INTO options_attendu
                FROM exams WHERE id = NEW.exam_id;

                IF NEW.kind = 'qcm_single' THEN
                    IF options_attendu IS NOT NULL AND nb_options <> options_attendu THEN
                        RAISE EXCEPTION
                            'Publication refusée : cette épreuve annonce % options par question (% trouvée(s)).',
                            options_attendu, nb_options;
                    END IF;

                    IF options_attendu IS NULL AND nb_options < 4 THEN
                        RAISE EXCEPTION
                            'Publication refusée : un QCM à réponse unique compte au moins 4 options (% trouvée(s)).',
                            nb_options;
                    END IF;
                END IF;

                IF nb_correctes <> 1 THEN
                    RAISE EXCEPTION
                        'Publication refusée : exactement une bonne réponse est attendue (% trouvée(s)).',
                        nb_correctes;
                END IF;

                IF nb_sans_justif > 0 THEN
                    RAISE EXCEPTION
                        'Publication refusée : % option(s) sans justification.', nb_sans_justif;
                END IF;

                IF NEW.eligible_for_diagnostic AND nb_sans_cause > 0 THEN
                    RAISE EXCEPTION
                        'Publication refusée : % distracteur(s) sans cause d''erreur (fiche F03).', nb_sans_cause;
                END IF;

                IF NEW.eligible_for_diagnostic OR NEW.eligible_for_simulation THEN
                    SELECT count(*) INTO nb_sources_ok
                    FROM question_sources
                    WHERE question_id = NEW.id AND verification = 'verified';

                    IF nb_sources_ok = 0 THEN
                        RAISE EXCEPTION
                            'Publication refusée : une question de diagnostic ou de simulation exige une source de contenu vérifiée.';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
    }
};
