<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Une source modifiée cesse d'être vérifiée.
 *
 * DET-47, mesure d'attente. Rien n'empêchait de changer le titre, l'autorité ou
 * l'URL d'une source APRÈS son contrôle : `verified_at` restait en place et
 * attestait d'un document qui n'était plus celui qui avait été vu. Le gel du
 * PAS-12 couvre les citations d'une question publiée et le contenu de la
 * question, jamais la ligne `sources` elle-même.
 *
 * ÇA ÉCHOUE DU BON CÔTÉ. Après invalidation, la publication pour diagnostic se
 * bloque jusqu'à re-vérification : le pire cas est un relecteur qui doit
 * recontrôler un document qu'il vient de corriger à la virgule. Sans
 * invalidation, le pire cas est une plateforme qui affirme avoir vérifié ce
 * qu'elle n'a pas lu.
 *
 * CE N'EST PAS LA SOLUTION, c'est ce qui tient jusqu'à elle. Le versionnement
 * de la source — une source contrôlée devient immuable, la corriger en crée une
 * nouvelle version, comme pour une question publiée — reste la seule réponse
 * cohérente avec ce dépôt. DET-47 demeure ouverte à ce titre.
 *
 * DEUX DÉCLENCHEURS, ET LE SECOND N'EST PAS FACULTATIF. Annuler `verified_at`
 * ne suffirait pas : ce que lisent `hasVerifiedContentSource()` et le trigger
 * de publication est le PIVOT `question_sources.verification`, propagé au
 * moment du contrôle. Sans rétrogradation des citations, la source cesserait
 * d'être vérifiée pendant que les questions continueraient de se publier au
 * diagnostic sur la foi d'un drapeau périmé — soit exactement le défaut qu'on
 * ferme, déplacé d'une table.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * COLONNES PORTEUSES DE SENS, et la liste se discute — elle est donc
         * écrite plutôt que devinée.
         *
         * DEDANS : `code` identifie le document dans les citations ; `kind`,
         * `title_*` et `authority_*` disent QUEL document et de quelle
         * autorité ; `session_label` de quelle année ; `url` où le relecteur
         * est allé le lire — une URL qui change peut désigner un tout autre
         * texte.
         *
         * DEHORS : `languages` recense les versions disponibles, et
         * `location_note_*` aide à trouver le document sans le constituer.
         * Aucune des deux ne change ce qui a été lu. Les y mettre ferait
         * invalider un contrôle pour une note de bas de page corrigée, et un
         * garde-fou qui crie pour rien finit désarmé.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION invalider_verification_source()
            RETURNS trigger AS $$
            BEGIN
                IF OLD.verified_at IS NULL THEN
                    RETURN NEW;
                END IF;

                IF NEW.code            IS DISTINCT FROM OLD.code
                   OR NEW.kind         IS DISTINCT FROM OLD.kind
                   OR NEW.title_fr     IS DISTINCT FROM OLD.title_fr
                   OR NEW.title_ar     IS DISTINCT FROM OLD.title_ar
                   OR NEW.authority_fr IS DISTINCT FROM OLD.authority_fr
                   OR NEW.authority_ar IS DISTINCT FROM OLD.authority_ar
                   OR NEW.session_label IS DISTINCT FROM OLD.session_label
                   OR NEW.url          IS DISTINCT FROM OLD.url
                THEN
                    NEW.verified_at := NULL;
                    NEW.verified_by := NULL;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::unprepared('DROP TRIGGER IF EXISTS sources_verification_invalidee ON sources');
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER sources_verification_invalidee
                BEFORE UPDATE ON sources
                FOR EACH ROW EXECUTE FUNCTION invalider_verification_source();
        SQL);

        /*
         * Rétrogradation des citations, bornée aux questions NON GELÉES.
         *
         * Celles d'une question publiée ou retirée ne bougent pas : le trigger
         * de gel les refuserait, et la correction déjà servie au candidat
         * s'appuyait sur l'état d'alors. Une question publiée dont la source a
         * été modifiée relève d'une décision éditoriale — la retirer — pas d'un
         * effet de bord silencieux.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION retrograder_citations_source()
            RETURNS trigger AS $$
            BEGIN
                IF OLD.verified_at IS NOT NULL AND NEW.verified_at IS NULL THEN
                    UPDATE question_sources qs
                       SET verification = 'unverified', updated_at = now()
                      FROM questions q
                     WHERE qs.question_id = q.id
                       AND qs.source_id = NEW.id
                       AND qs.verification = 'verified'
                       AND q.status NOT IN ('published', 'retired');
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::unprepared('DROP TRIGGER IF EXISTS sources_citations_retrogradees ON sources');
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER sources_citations_retrogradees
                AFTER UPDATE ON sources
                FOR EACH ROW EXECUTE FUNCTION retrograder_citations_source();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS sources_citations_retrogradees ON sources');
        DB::unprepared('DROP TRIGGER IF EXISTS sources_verification_invalidee ON sources');
        DB::unprepared('DROP FUNCTION IF EXISTS retrograder_citations_source()');
        DB::unprepared('DROP FUNCTION IF EXISTS invalider_verification_source()');
    }
};
