<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P-E — L'immuabilité devient différenciée, et la coquille a son canal.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * LA CONTRADICTION QUE CE PAS FERME
 *
 * Le lot 3A.4 a posé l'immuabilité TOTALE des versions : tout UPDATE lève,
 * tout DELETE lève. La spécification d'administration commerciale, elle,
 * autorise depuis l'arbitrage AR-1 une correction de coquille SANS nouvelle
 * version — « amende la version en place, aucune version nouvelle, ne peut pas
 * changer le sens : le journal le rend vérifiable ». Les deux ne pouvaient pas
 * tenir ensemble : versionner une faute d'accord ferait de chaque coquille un
 * contrat neuf, et laisser l'UPDATE ouvert rendrait le prix réécrivable.
 *
 * La sortie n'est pas un assouplissement, c'est une DIFFÉRENCIATION : les
 * champs contractuels non textuels restent absolument immuables, les quatre
 * textes ne bougent que par un canal qui journalise dans la même transaction.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * POURQUOI LA MARQUE DE TRANSACTION, ET PAS UNE COLONNE
 *
 * Le déclencheur doit distinguer « cet UPDATE vient du canal » de « cet UPDATE
 * vient d'une console ». Rien dans la ligne ne le dit : un drapeau sur la
 * table serait posé par celui-là même qu'on veut refuser. La marque est donc
 * POSÉE PAR LA FONCTION, locale à sa transaction (`set_config(..., true)`), et
 * elle porte l'identifiant EXACT de la version corrigée — une marque booléenne
 * laisserait passer, dans la même transaction, la réécriture d'une autre
 * ligne. La fonction l'efface juste après son UPDATE : une seule ligne, une
 * seule correction, une seule trace.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * LA LISTE DES COLONNES AUTORISÉES SE LIT EN NÉGATIF
 *
 * Le déclencheur ne compare pas les colonnes contractuelles une à une : il
 * compare la ligne ENTIÈRE moins les quatre textes. Toute colonne ajoutée
 * demain à `plan_versions` — un instantané de quota, une politique de
 * gratuité — est donc immuable par DÉFAUT, sans que personne ait à penser à
 * l'inscrire ici. Une liste positive, elle, aurait vieilli au premier ajout.
 */
return new class extends Migration
{
    /** Les seuls champs qu'une coquille peut atteindre. */
    private const TEXTES = ['name_fr', 'name_ar', 'description_fr', 'description_ar'];

    public function up(): void
    {
        $this->genererDesUuidV7EnBase();
        $this->creerLeJournal();
        $this->ouvrirLeCanal();
        $this->differencierLImmuabilite();
    }

    /**
     * UUIDv7 côté base — la règle R6 vaut aussi pour ce que le SQL insère.
     *
     * `gen_random_uuid()` produit un v4, en contradiction directe avec la
     * convention d'identifiants (ADR-0002), et le PAS-1.1 a déjà eu à réparer
     * cette faute une fois. PostgreSQL 16 n'a pas de générateur v7 natif — il
     * arrive en PG 18. La ligne de journal étant écrite PAR LA FONCTION SQL et
     * non par Eloquent, `HasPublicUuid` ne peut pas la servir : on génère donc
     * ici la même chose que `Str::uuid7()`, horodatée en tête et donc ordonnée
     * dans le temps.
     */
    private function genererDesUuidV7EnBase(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION uuid7() RETURNS uuid AS $$
            DECLARE
                millisecondes bigint := (extract(epoch FROM clock_timestamp()) * 1000)::bigint;
                alea text := replace(gen_random_uuid()::text, '-', '');
            BEGIN
                RETURN (
                    lpad(to_hex(millisecondes), 12, '0')      -- 48 bits d horodatage
                    || '7' || substr(alea, 14, 3)             -- version 7, puis 12 bits d alea
                    || substr('89ab', 1 + floor(random() * 4)::int, 1) || substr(alea, 18, 3)
                    || substr(alea, 21, 12)                   -- variante RFC, puis le reste
                )::uuid;
            END;
            $$ LANGUAGE plpgsql VOLATILE;
        SQL);
    }

    /**
     * Le journal — en ajout seul, comme celui des profils de quota.
     *
     * C'est la seule chose qui distingue une correction de coquille d'une
     * réécriture de promesse : l'avant, l'après, l'auteur et le motif. Un
     * journal modifiable prouverait ce qu'on veut, donc rien.
     */
    private function creerLeJournal(): void
    {
        Schema::create('plan_version_editorial_fixes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('plan_version_id')->constrained()->restrictOnDelete();
            $table->string('field', 32);
            $table->text('before_text')->nullable();
            $table->text('after_text');
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['plan_version_id', 'occurred_at'], 'plan_version_fixes_timeline_idx');
        });

        DB::statement('ALTER TABLE plan_version_editorial_fixes ALTER COLUMN uuid SET DEFAULT uuid7()');

        $textes = "'".implode("', '", self::TEXTES)."'";

        DB::statement(<<<SQL
            ALTER TABLE plan_version_editorial_fixes
            ADD CONSTRAINT plan_version_fixes_field_is_textual
            CHECK (field IN ({$textes}))
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE plan_version_editorial_fixes
            ADD CONSTRAINT plan_version_fixes_reason_written
            CHECK (length(btrim(reason)) >= 10)
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE plan_version_editorial_fixes
            ADD CONSTRAINT plan_version_fixes_after_not_empty
            CHECK (btrim(after_text) <> '')
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION refuse_editorial_fix_mutation()
            RETURNS TRIGGER AS $$
            BEGIN
                RAISE EXCEPTION 'Le journal des corrections editoriales est en ajout seul.';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER plan_version_editorial_fixes_append_only
                BEFORE UPDATE OR DELETE ON plan_version_editorial_fixes
                FOR EACH ROW EXECUTE FUNCTION refuse_editorial_fix_mutation();
        SQL);
    }

    /**
     * Le canal : la ligne de journal ET le nouveau texte, ou rien du tout.
     *
     * Les deux écritures sont dans la même transaction, et l'ordre compte :
     * le journal d'abord, la marque ensuite, l'UPDATE en dernier. Si l'UPDATE
     * lève — champ contractuel, version introuvable, ligne verrouillée — la
     * ligne de journal disparaît avec lui. Il n'existe aucun état où le texte
     * a changé sans que le journal le dise.
     */
    private function ouvrirLeCanal(): void
    {
        $textes = "'".implode("', '", self::TEXTES)."'";

        DB::unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION corriger_version_editoriale(
                version uuid,
                champ text,
                nouveau_texte text,
                auteur bigint,
                motif text
            ) RETURNS uuid AS \$\$
            DECLARE
                cible bigint;
                ancien text;
                trace uuid;
            BEGIN
                IF champ NOT IN ({$textes}) THEN
                    RAISE EXCEPTION
                        'Le canal editorial ne corrige que les textes : % est un champ contractuel, il se change par une nouvelle version.',
                        champ;
                END IF;

                IF nouveau_texte IS NULL OR btrim(nouveau_texte) = '' THEN
                    RAISE EXCEPTION 'Une correction editoriale corrige un texte, elle ne le vide pas.';
                END IF;

                IF motif IS NULL OR length(btrim(motif)) < 10 THEN
                    RAISE EXCEPTION
                        'Une correction sans motif ecrit ne se verifie pas : dites ce qui est corrige.';
                END IF;

                /* Le paramètre se qualifie par le nom de la fonction : `version`
                   est AUSSI une colonne de `plan_versions`, et PostgreSQL
                   refuse de deviner. Le nom du paramètre, lui, est celui que
                   la décision d architecture a fixé — on le garde. */
                SELECT id INTO cible
                FROM plan_versions
                WHERE uuid = corriger_version_editoriale.version
                FOR UPDATE;

                IF cible IS NULL THEN
                    RAISE EXCEPTION 'Version d offre introuvable.';
                END IF;

                EXECUTE format('SELECT %I FROM plan_versions WHERE id = \$1', champ)
                    INTO ancien USING cible;

                IF ancien IS NOT DISTINCT FROM nouveau_texte THEN
                    RAISE EXCEPTION 'Le texte propose est deja celui de la version : rien a corriger.';
                END IF;

                INSERT INTO plan_version_editorial_fixes
                    (plan_version_id, field, before_text, after_text, actor_id, reason, occurred_at)
                VALUES (cible, champ, ancien, nouveau_texte, auteur, btrim(motif), now())
                RETURNING uuid INTO trace;

                PERFORM set_config('naja7i.correction_editoriale', cible::text, true);

                EXECUTE format('UPDATE plan_versions SET %I = \$1, updated_at = now() WHERE id = \$2', champ)
                    USING nouveau_texte, cible;

                PERFORM set_config('naja7i.correction_editoriale', '', true);

                RETURN trace;
            END;
            \$\$ LANGUAGE plpgsql;
        SQL);
    }

    private function differencierLImmuabilite(): void
    {
        $textes = "'".implode("', '", self::TEXTES)."'";

        DB::unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION plan_versions_are_immutable() RETURNS trigger AS \$\$
            DECLARE
                corrigibles text[] := ARRAY[{$textes}, 'updated_at'];
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION
                        'Une version d offre ne se supprime jamais : une commande la designe.';
                END IF;

                IF current_setting('naja7i.correction_editoriale', true)
                   IS DISTINCT FROM OLD.id::text THEN
                    RAISE EXCEPTION
                        'Une version d offre est immuable : une coquille passe par corriger_version_editoriale(), le reste par une nouvelle version.';
                END IF;

                IF (to_jsonb(NEW) - corrigibles) IS DISTINCT FROM (to_jsonb(OLD) - corrigibles) THEN
                    RAISE EXCEPTION
                        'Le canal editorial ne corrige que les textes : ce qui a ete vendu ne se reecrit pas.';
                END IF;

                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION plan_versions_are_immutable() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Une version d offre est immuable';
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement('DROP FUNCTION IF EXISTS corriger_version_editoriale(uuid, text, text, bigint, text)');
        DB::unprepared(
            'DROP TRIGGER IF EXISTS plan_version_editorial_fixes_append_only ON plan_version_editorial_fixes'
        );
        DB::statement('DROP FUNCTION IF EXISTS refuse_editorial_fix_mutation()');

        Schema::dropIfExists('plan_version_editorial_fixes');

        DB::statement('DROP FUNCTION IF EXISTS uuid7()');
    }
};
