<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Trois faits IMPRIMÉS sur les sujets, que le produit ne savait pas.
 *
 * Source : `docs/corpus/CRMEF-extraction-20260815.md` §4.2, cité mot pour mot.
 * Ce ne sont pas des hypothèses de conception : ce sont des consignes lues sur
 * les épreuves elles-mêmes.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * F1 — CINQ OPTIONS, PAS QUATRE
 *
 * « اقتُرحت لكل سؤال خمسة أجوبة (A ; B ; C ; D ; E) واحد منها هو الصحيح، فقط »
 * (2024 et 2025 ; les sujets 2023 en comptent quatre.)
 *
 * Le produit exigeait EXACTEMENT quatre options pour publier — en deux endroits,
 * `QuestionIntegrityChecker` et le déclencheur PostgreSQL. Conséquence directe :
 * une question fidèle à l'épreuve réelle depuis 2024 ne pouvait pas être
 * publiée. La garde était juste dans son intention et fausse dans son chiffre.
 *
 * CE QUI CHANGE, ET CE QUI NE CHANGE PAS. Le nombre exact n'est plus une
 * constante universelle : c'est une propriété de l'ÉPREUVE, que le sujet
 * imprime. `exams.options_count` la porte quand elle est connue, et la garde
 * l'exige alors À L'UNITÉ. Quand elle est nulle — page de garde manquante,
 * épreuve non documentée — il reste un PLANCHER de quatre.
 *
 * Le plancher n'est pas une invention de fait : c'est l'ancienne règle rendue à
 * son rôle réel. Aucun sujet du corpus ne descend sous quatre options, et une
 * question à deux options reste refusée à la publication comme avant.
 *
 * L'option E est très souvent « جميع الاختيارات المقترحة خاطئة » / « Aucun des
 * choix précédents ». Elle ne fait pas que baisser la probabilité au hasard de
 * 25 % à 20 % : ELLE REND L'ÉLIMINATION PROGRESSIVE INOPÉRANTE. Un candidat qui
 * écarte trois options ne peut plus conclure — la quatrième restante peut
 * encore être fausse. C'est une compétence différente, pas un exercice plus
 * difficile.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * F2 — LA PÉNALITÉ NÉGATIVE (2025, les trois voies)
 *
 * « تنقط الإجابة بنقطة واحدة (1ن)، وصفر (0) في حالة عدم الإجابة؛ يعتمد تنقيط
 * سالب عن كل إجابة خاطئة أو ملغاة »
 *
 * TROIS ÉTATS, ET LE TROISIÈME EST LE PLUS IMPORTANT :
 *
 *   `true`  — la pénalité est imprimée sur le sujet (2025) ;
 *   `false` — le sujet imprime explicitement zéro pour une erreur (2023 :
 *             « Chaque réponse incorrecte sera notée par zéro (0) ») ;
 *   `null`  — on ne sait pas, et c'est le défaut.
 *
 * Un booléen non nul par défaut à `false` aurait affirmé « pas de pénalité » sur
 * toutes les épreuves dont la page de garde manque. L'absence ne vaut pas zéro —
 * c'est la règle du dépôt, et elle s'applique ici littéralement.
 *
 * LA VALEUR N'EST PAS IMPRIMÉE, SEULE SON EXISTENCE L'EST. `negative_marking_points`
 * reste donc nul, et la contrainte interdit de le renseigner sans que la
 * pénalité soit déclarée existante. Le jour où un descriptif donne « -0,25 »,
 * la colonne l'accueille ; d'ici là, le produit dit « une pénalité s'applique,
 * son montant n'est pas publié » — ce qui est exactement ce qu'on sait.
 *
 * CE FAIT RENCONTRE LA CERTITUDE (F02), et c'est ce qui le rend intéressant.
 * « sûr · hésitant · au hasard » existe depuis les fondations ; le barème réel
 * lui donne enfin une conséquence chiffrée, puisque répondre au hasard devient
 * statistiquement perdant. Le produit se contente d'en tenir le compte. AUCUN
 * CONSEIL DE STRATÉGIE n'en découle ici : « vous auriez dû vous abstenir » est
 * un jugement, et le rapport rend des chiffres.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * F3 — LA NUMÉROTATION RÉELLE
 *
 * Les blocs ne commencent pas à Q1. Le primaire répond de Q101 à Q125, le
 * collège commence à Q61, sur une feuille de réponses COMMUNE à plusieurs
 * blocs. Le corpus est explicite : « Un décalage de report d'une seule ligne
 * invalide la totalité du bloc. »
 *
 * Un examen blanc qui numérote 1, 2, 3 entraîne donc au report sur la mauvaise
 * ligne. `exams.first_question_number` porte le numéro de départ imprimé.
 *
 * SUR L'ÉPREUVE ET NON SUR LA TENTATIVE : le numéro est une propriété du bloc
 * imprimé, pas de la séance du candidat. Le stocker par tentative dupliquerait
 * une donnée de référentiel sur chaque série, et la première correction du
 * référentiel laisserait derrière elle des séries figées sur l'ancienne valeur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            /* Le nombre d'options ANNONCÉ par le sujet. Nul = non documenté. */
            $table->unsignedSmallInteger('options_count')->nullable();

            /* Le premier numéro imprimé du bloc. Nul = non documenté, le client
             * numérote alors à partir de 1 comme aujourd'hui. */
            $table->unsignedSmallInteger('first_question_number')->nullable();
        });

        DB::statement(
            'COMMENT ON COLUMN exams.options_count IS
             $$Nombre d options imprime sur le sujet (4 en 2023, 5 depuis 2024).
             NUL = non documente : la garde de publication applique alors son plancher de 4.$$'
        );

        DB::statement(
            'COMMENT ON COLUMN exams.first_question_number IS
             $$Premier numero imprime du bloc (Q101 pour le primaire, Q61 pour le college).
             NUL = non documente. Un decalage de report d une ligne invalide le bloc entier.$$'
        );

        /* Quatre est un PLANCHER de structure, pas un nombre officiel : aucun
         * sujet du corpus n'en compte moins, et un QCM à trois options n'est
         * plus le même exercice. */
        DB::statement(
            'ALTER TABLE exams ADD CONSTRAINT exams_options_count_plancher
             CHECK (options_count IS NULL OR options_count >= 4)'
        );

        Schema::table('blueprints', function (Blueprint $table) {
            /* NULLABLE À TROIS ÉTATS — voir l'en-tête. `false` est une
             * information imprimée, pas un défaut. */
            $table->boolean('negative_marking')->nullable();

            /* Points retirés par réponse fausse. Positif : c'est un montant, le
             * signe est porté par le sens de la colonne. */
            $table->decimal('negative_marking_points', 4, 2)->nullable();
        });

        DB::statement(
            'COMMENT ON COLUMN blueprints.negative_marking IS
             $$Une penalite negative est-elle imprimee sur le sujet ?
             true = imprimee (2025) ; false = le sujet imprime zero pour une erreur (2023) ; NULL = inconnu.$$'
        );

        /* ON NE CONNAÎT PAS LE MONTANT SANS CONNAÎTRE L'EXISTENCE. Un montant
         * posé sur une pénalité nulle ou absente serait une valeur que rien ne
         * justifie — exactement ce que ce projet refuse de laisser entrer. */
        DB::statement(
            'ALTER TABLE blueprints ADD CONSTRAINT blueprints_penalite_coherente
             CHECK (negative_marking_points IS NULL OR negative_marking IS TRUE)'
        );

        $this->gardeDePublication();
    }

    /**
     * LA GARANTIE DE DERNIER RECOURS PORTE LE MÊME CHIFFRE — et devait donc
     * changer aussi.
     *
     * `QuestionIntegrityChecker` et ce déclencheur disaient tous deux « exactement
     * quatre ». Corriger le seul service aurait laissé PostgreSQL refuser en
     * dernier ce que l'application venait d'accepter : une question à cinq
     * options aurait franchi le back-office pour échouer à l'écriture, avec un
     * message venu du moteur. Les deux gardes disent la même règle, ou aucune ne
     * vaut.
     *
     * Le reste de la fonction est reconduit à l'identique — c'est un
     * `CREATE OR REPLACE`, il remplace le corps entier.
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
                       count(*) FILTER (WHERE length(btrim(rationale)) = 0),
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

    public function down(): void
    {
        DB::statement('ALTER TABLE blueprints DROP CONSTRAINT IF EXISTS blueprints_penalite_coherente');
        DB::statement('ALTER TABLE exams DROP CONSTRAINT IF EXISTS exams_options_count_plancher');

        Schema::table('blueprints', function (Blueprint $table) {
            $table->dropColumn(['negative_marking', 'negative_marking_points']);
        });

        Schema::table('exams', function (Blueprint $table) {
            $table->dropColumn(['options_count', 'first_question_number']);
        });
    }
};
