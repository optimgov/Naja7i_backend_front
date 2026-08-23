<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Lot Q2 — le poste de travail des experts.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * QUATRE OBJETS, ET AUCUN N'EST UNE COMMODITÉ D'ÉCRAN
 *
 * 1. **L'échelle de difficulté devient une DONNÉE** (Q-09). Cinq crans, fermés
 *    en code — la contrainte `prepared_questions_difficulty_range` les tient
 *    déjà entre 1 et 5 — mais leurs LIBELLÉS et leurs ANCRES sont éditables
 *    sans déploiement. C'est exactement la forme de `capability_definitions` :
 *    ce que le code déclare reste fermé, ce que l'humain lit reste ouvert. Une
 *    ancre comportementale mal formulée fait poser des difficultés fausses par
 *    1 413 fois ; la corriger ne doit pas demander un déploiement.
 *
 * 2. **`hors_nomenclature` entre au vocabulaire des causes** (DET-16). Les huit
 *    codes de F03 ne sont pas validés pédagogiquement, et un expert qui ne
 *    trouve pas sa case en choisit une fausse — ce qui est pire que de n'en
 *    choisir aucune. Le neuvième code dit « aucun des huit », et il EXIGE son
 *    texte libre : sans lui, il deviendrait la case fourre-tout où l'on range
 *    ce qu'on n'a pas voulu qualifier, et la nomenclature ne s'améliorerait
 *    jamais. La contrainte est en base, pas dans un formulaire.
 *
 * 3. **La retranscription donne une SORTIE à `ILLEGIBLE`** (correction C-A).
 *    Sans elle, l'état est un cimetière : une question illisible y entre et
 *    n'en ressort jamais, et les 5 illisibles du corpus restent perdues. Le
 *    geste est tracé — auteur, source, date — comme tout geste qui réécrit un
 *    fait de source.
 *
 * 4. **Le signalement éditorial est STRUCTURÉ.** Les experts valident le
 *    contenu en travaillant ; c'est la relecture la moins chère du projet, et
 *    la recueillir en commentaire libre la rend inexploitable. Quatre genres
 *    nommés, et le texte libre EN PLUS — jamais à la place.
 */
return new class extends Migration
{
    /** Les cinq crans de Q-09, avec leurs ancres comportementales. */
    private const CRANS = [
        [1, 'Acquis de base', 'المكتسبات الأساسية',
            'Restitution directe d’une définition ou d’un fait au programme. '
            .'Un candidat qui a lu le cours répond sans réfléchir.',
            'استرجاع مباشر لتعريف أو معطى من المقرر. المترشح الذي قرأ الدرس يجيب دون تفكير.'],
        [2, 'Application directe', 'التطبيق المباشر',
            'Une règle connue s’applique telle quelle, sans choix à faire. '
            .'La difficulté est de connaître la règle, pas de la choisir.',
            'قاعدة معروفة تُطبَّق كما هي، دون اختيار. الصعوبة في معرفة القاعدة لا في اختيارها.'],
        [3, 'Application raisonnée', 'التطبيق المعلَّل',
            'Il faut choisir la bonne règle parmi plusieurs, ou l’adapter au cas. '
            .'C’est le cran de la majorité des questions d’un concours.',
            'يجب اختيار القاعدة المناسبة من بين عدة، أو تكييفها مع الحالة. '
            .'وهو مستوى أغلب أسئلة المباراة.'],
        [4, 'Transfert', 'النقل',
            'La situation n’a pas été vue en cours : le candidat doit transposer '
            .'ce qu’il sait à un contexte nouveau.',
            'وضعية لم تُدرَّس: على المترشح نقل ما يعرفه إلى سياق جديد.'],
        [5, 'Discriminante', 'المميِّزة',
            'Distingue le candidat qui maîtrise de celui qui reconnaît. '
            .'Attendue rare : une épreuve qui en compte trop ne mesure plus, elle trie.',
            'تميّز بين المترشح المتمكّن والمترشح الذي يكتفي بالتعرّف. '
            .'يُنتظر أن تكون نادرة: اختبار يكثر منها لم يعد يقيس بل يفرز.'],
    ];

    public function up(): void
    {
        // ── 1. L'échelle de difficulté, en données ──────────────────────────
        Schema::create('difficulty_levels', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            /* LE CRAN EST FERMÉ EN CODE, son texte ne l'est pas. On ne crée ni
             * ne supprime un cran depuis l'écran : une échelle dont le nombre
             * de crans varie ne se compare plus à elle-même d'une session à
             * l'autre, et les difficultés déjà posées perdraient leur sens. */
            $table->unsignedSmallInteger('level')->unique();

            $table->string('label_fr', 64);
            $table->string('label_ar', 64);

            /* L'ANCRE COMPORTEMENTALE, et c'est elle qui fait le travail. Un
             * libellé seul — « Transfert » — se lit différemment par chaque
             * expert. L'ancre dit ce qu'on observe chez le candidat. */
            $table->text('anchor_fr');
            $table->text('anchor_ar');

            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE difficulty_levels ALTER COLUMN uuid SET DEFAULT uuid7()');
        DB::statement(
            'ALTER TABLE difficulty_levels
             ADD CONSTRAINT difficulty_levels_scale_bounded CHECK (level BETWEEN 1 AND 5)'
        );

        $maintenant = now();

        foreach (self::CRANS as [$niveau, $fr, $ar, $ancreFr, $ancreAr]) {
            DB::table('difficulty_levels')->insert([
                'uuid' => (string) Str::uuid7(),
                'level' => $niveau,
                'label_fr' => $fr,
                'label_ar' => $ar,
                'anchor_fr' => $ancreFr,
                'anchor_ar' => $ancreAr,
                'created_at' => $maintenant,
                'updated_at' => $maintenant,
            ]);
        }

        /* NI CRÉATION NI SUPPRESSION, tenues en base : l'écran ne propose pas
         * les boutons, et un correctif à chaud rencontre le même refus. */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION refuse_difficulty_scale_resize()
            RETURNS TRIGGER AS $$
            BEGIN
                RAISE EXCEPTION 'L''echelle de difficulte compte cinq crans, fermes en code. Leurs libelles et leurs ancres s''editent ; leur nombre non.';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER difficulty_levels_fixed_scale
                BEFORE INSERT OR DELETE ON difficulty_levels
                FOR EACH ROW EXECUTE FUNCTION refuse_difficulty_scale_resize();
        SQL);

        // ── 2. La permission de poser une difficulté — Q-10 ─────────────────
        if (! DB::table('permissions')->where('code', 'questions.difficulty')->exists()) {
            $id = DB::table('permissions')->insertGetId([
                'uuid' => (string) Str::uuid7(),
                'code' => 'questions.difficulty',
                'domain' => 'questions',
                'label_fr' => 'Poser la difficulté déclarée d’une question',
                'label_ar' => 'تحديد الصعوبة المعلنة لسؤال',
                /* Pas réservée à la plateforme : un organisme partenaire pourra
                 * qualifier son propre corpus. Ce qui est réservé, c'est de
                 * MODIFIER l'échelle, et cela relève de `questions.validate`. */
                'platform_only' => false,
                'created_at' => $maintenant,
                'updated_at' => $maintenant,
            ]);

            /* L'expert qualifiant : la difficulté est un jugement pédagogique,
             * pas une saisie de rédaction (Q-10) — `auteur` ne l'a donc pas.
             * `super_admin` la reçoit comme toutes les autres, sans quoi
             * `PermissionsTest` constaterait à raison qu'il n'a plus « toutes »
             * les permissions. */
            foreach (['editeur', 'reviseur', 'super_admin'] as $role) {
                $roleId = DB::table('roles')->where('code', $role)->whereNull('tenant_id')->value('id');

                if ($roleId !== null) {
                    DB::table('permission_role')->insert([
                        'permission_id' => $id, 'role_id' => $roleId,
                        'created_at' => $maintenant, 'updated_at' => $maintenant,
                    ]);
                }
            }
        }

        /* `hors_nomenclature` et son texte obligatoire vivent à la migration
         * SUIVANTE : PostgreSQL refuse d'employer une valeur d'énumération
         * fraîchement ajoutée dans la même transaction, et Laravel enveloppe
         * chaque migration dans une transaction sur PostgreSQL. C'est déjà la
         * raison du couple 000520/000530. */
        DB::statement("ALTER TYPE error_cause ADD VALUE IF NOT EXISTS 'hors_nomenclature'");

        // ── 4. Le signalement éditorial structuré ───────────────────────────
        DB::statement(
            "CREATE TYPE editorial_flag_kind AS ENUM (
                'stem_doubtful', 'options_ambiguous', 'answer_disputed', 'taxonomy_wrong'
            )"
        );

        Schema::create('editorial_flags', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('prepared_question_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();

            /* Le texte libre est un SUPPLÉMENT. Il complète un genre nommé, il
             * ne le remplace jamais : un signalement rangeable est un
             * signalement exploitable, et cinquante commentaires libres ne se
             * dépouillent pas. */
            $table->text('note')->nullable();

            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['prepared_question_id', 'occurred_at'], 'editorial_flags_par_ligne_idx');
        });

        DB::statement('ALTER TABLE editorial_flags ALTER COLUMN uuid SET DEFAULT uuid7()');
        DB::statement('ALTER TABLE editorial_flags ADD COLUMN kind editorial_flag_kind NOT NULL');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION refuse_editorial_flag_mutation()
            RETURNS TRIGGER AS $$
            BEGIN
                RAISE EXCEPTION 'Le journal des signalements editoriaux est en ajout seul.';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER editorial_flags_append_only
                BEFORE UPDATE OR DELETE ON editorial_flags
                FOR EACH ROW EXECUTE FUNCTION refuse_editorial_flag_mutation();
        SQL);

        // ── 5. La retranscription, et son genre d'événement ─────────────────
        DB::statement(
            "ALTER TYPE question_preparation_event_type ADD VALUE IF NOT EXISTS 'retranscribed'"
        );
        DB::statement(
            "ALTER TYPE question_preparation_event_type ADD VALUE IF NOT EXISTS 'editorially_flagged'"
        );
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS editorial_flags_append_only ON editorial_flags');
        DB::statement('DROP FUNCTION IF EXISTS refuse_editorial_flag_mutation()');
        Schema::dropIfExists('editorial_flags');
        DB::statement('DROP TYPE IF EXISTS editorial_flag_kind');

        DB::unprepared('DROP TRIGGER IF EXISTS difficulty_levels_fixed_scale ON difficulty_levels');
        DB::statement('DROP FUNCTION IF EXISTS refuse_difficulty_scale_resize()');
        Schema::dropIfExists('difficulty_levels');

        DB::table('permissions')->where('code', 'questions.difficulty')->delete();

        /* `error_cause` et `question_preparation_event_type` conservent leurs
         * valeurs ajoutées : PostgreSQL ne retire pas une valeur d'énumération,
         * et reconstruire le type détruirait les lignes qui la portent. */
    }
};
