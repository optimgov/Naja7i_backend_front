<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * DET-48 — `mirror_question_id` sort du gel du contenu publié.
 *
 * LE POINTEUR EST DE L'USAGE, PAS DU CONTENU. Désigner un autre miroir ne
 * change rien à ce qu'un candidat a VU : `mirror_available` se calcule à la
 * lecture, et aucune correction déjà servie n'y fait référence. Le précédent
 * est `eligible_for_diagnostic`, exempté depuis la contre-revue du PAS-12 pour
 * exactement cette raison — il désigne l'usage d'une question, pas ce qu'elle
 * dit.
 *
 * PAR LE MÊME MÉCANISME, ET PAR AUCUN AUTRE. La colonne rejoint la soustraction
 * `to_jsonb(OLD) - '…'` qui exempte déjà le statut, le retrait, l'horodatage et
 * les deux drapeaux d'éligibilité. Aucun déclencheur nouveau, aucune branche
 * conditionnelle : une exemption qui s'écrit ailleurs que dans cette liste
 * serait une seconde règle de gel, et deux règles de gel finissent par diverger.
 *
 * DÉGELER N'EST PAS DÉRÉGLEMENTER. Ce qui encadrait la désignation reste en
 * place, et rien ici ne l'affaiblit :
 *  - `questions_mirror_not_self` (CHECK, PAS-5) interdit toujours qu'une
 *    question se désigne elle-même ;
 *  - la clé étrangère impose toujours une question existante ;
 *  - `QuestionsSoeurs::designee()` continue d'exiger, À LA LECTURE, que la
 *    désignée soit servable et de la même langue, et se replie sur le couple
 *    (compétence, cause) sinon (PAS-30).
 *
 * CE QUI RESTE GELÉ, ET POURQUOI. La question RETIRÉE ne bouge pas d'un iota :
 * `assert_retired_question_frozen` refuse toute écriture, y compris celle-ci.
 * Une question retirée n'est plus servie — lui désigner un miroir ne désignerait
 * rien, et rouvrir une colonne sur une ligne archivée coûterait une garantie
 * pour zéro usage.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->gelPublie(exempterLeMiroir: true);
    }

    public function down(): void
    {
        $this->gelPublie(exempterLeMiroir: false);
    }

    /**
     * La fonction du PAS-12 (`000320`), à une soustraction près.
     *
     * Recopiée en entier plutôt que rapiécée : `CREATE OR REPLACE FUNCTION` ne
     * sait pas modifier un corps, et une migration qui reconstruirait la
     * fonction à partir de fragments rendrait illisible ce qui est réellement
     * en base à un instant donné.
     */
    private function gelPublie(bool $exempterLeMiroir): void
    {
        $miroir = $exempterLeMiroir ? "\n                                       - 'mirror_question_id'" : '';

        /* Le message suit l'exemption : annoncer une colonne modifiable que la
         * fonction refuse serait pire que ne rien annoncer. */
        $modifiables = $exempterLeMiroir
            ? 'Seuls le statut, le retrait, l\'\'éligibilité et la question miroir désignée changent (ADR-0015 §5, DET-48).'
            : 'Seuls le statut, le retrait et l\'\'éligibilité changent (ADR-0015 §5).';

        DB::unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION assert_published_question_frozen()
            RETURNS TRIGGER AS \$\$
            DECLARE avant jsonb; apres jsonb;
            BEGIN
                IF OLD.status <> 'published' THEN
                    RETURN NEW;
                END IF;

                -- Une seule sortie possible. Sans cette borne, il suffisait de
                -- repasser en brouillon pour rouvrir toutes les colonnes.
                IF NEW.status NOT IN ('published', 'retired') THEN
                    RAISE EXCEPTION
                        'Une question publiée ne peut que rester publiée ou être retirée (destination demandée : %). Créez une nouvelle version (ADR-0015 §5).',
                        NEW.status;
                END IF;

                IF NEW.status = 'retired' AND NEW.retired_at IS NULL THEN
                    RAISE EXCEPTION 'Un retrait doit être horodaté.';
                END IF;

                -- DET-48 : `mirror_question_id` désigne l'USAGE de la question,
                -- comme les deux drapeaux d'éligibilité. Il n'entre pas dans ce
                -- que le candidat a lu.
                avant := to_jsonb(OLD) - 'status' - 'retired_at' - 'updated_at'
                                       - 'eligible_for_diagnostic' - 'eligible_for_simulation'{$miroir};
                apres := to_jsonb(NEW) - 'status' - 'retired_at' - 'updated_at'
                                       - 'eligible_for_diagnostic' - 'eligible_for_simulation'{$miroir};

                IF avant IS DISTINCT FROM apres THEN
                    RAISE EXCEPTION
                        'Une question publiée est gelée. {$modifiables}';
                END IF;

                IF (NEW.eligible_for_diagnostic AND NOT OLD.eligible_for_diagnostic)
                OR (NEW.eligible_for_simulation AND NOT OLD.eligible_for_simulation) THEN
                    RAISE EXCEPTION
                        'L''éligibilité d''une question publiée ne peut pas être élargie : republiez une nouvelle version.';
                END IF;

                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;
        SQL);
    }
};
