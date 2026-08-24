<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Le poste éditorial devient un profil unique : expert_pedagogue.
 *
 * Les anciennes appartenances restent en place pour conserver l'historique,
 * mais leurs rôles ne participent plus à l'autorisation et ne sont plus
 * attribuables. Chaque personne concernée reçoit en parallèle le nouveau rôle,
 * sans doublon même si elle cumulait plusieurs anciens profils.
 */
return new class extends Migration
{
    private const ANCIENS_ROLES = ['auteur', 'reviseur', 'editeur'];

    /**
     * Le poste expert ne reçoit que les capacités de son périmètre. Cette
     * liste volontairement fermée évite qu'un droit commercial ou
     * d'administration attaché autrefois à un rôle éditorial soit hérité par
     * accident lors de la migration.
     */
    private const PERMISSIONS_EXPERT = [
        'questions.view',
        'questions.create',
        'questions.review',
        'questions.validate',
        'questions.publish',
        'questions.retire',
        'questions.difficulty',
        'catalogue.view',
        'catalogue.manage',
        'taxonomy.manage',
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('roles', 'is_active')) {
            Schema::table('roles', function (Blueprint $table) {
                $table->boolean('is_active')->default(true)->after('is_staff');
            });
        }

        $maintenant = now();

        DB::table('roles')->updateOrInsert(
            ['tenant_id' => null, 'code' => 'expert_pedagogue'],
            [
                'uuid' => DB::table('roles')
                    ->whereNull('tenant_id')
                    ->where('code', 'expert_pedagogue')
                    ->value('uuid') ?? (string) Str::uuid7(),
                'label_fr' => 'Expert pédagogue',
                'label_ar' => 'خبير تربوي',
                'is_staff' => true,
                'is_active' => true,
                'created_at' => DB::table('roles')
                    ->whereNull('tenant_id')
                    ->where('code', 'expert_pedagogue')
                    ->value('created_at') ?? $maintenant,
                'updated_at' => $maintenant,
            ],
        );

        $expertId = DB::table('roles')
            ->whereNull('tenant_id')
            ->where('code', 'expert_pedagogue')
            ->value('id');

        $anciensIds = DB::table('roles')
            ->whereNull('tenant_id')
            ->whereIn('code', self::ANCIENS_ROLES)
            ->pluck('id');

        $permissionIds = DB::table('permissions')
            ->whereIn('code', self::PERMISSIONS_EXPERT)
            ->pluck('id');

        foreach ($permissionIds as $permissionId) {
            DB::table('permission_role')->insertOrIgnore([
                'permission_id' => $permissionId,
                'role_id' => $expertId,
                'created_at' => $maintenant,
                'updated_at' => $maintenant,
            ]);
        }

        $adminId = DB::table('roles')
            ->whereNull('tenant_id')
            ->where('code', 'super_admin')
            ->value('id');

        if ($adminId !== null) {
            foreach (DB::table('permissions')->pluck('id') as $permissionId) {
                DB::table('permission_role')->insertOrIgnore([
                    'permission_id' => $permissionId,
                    'role_id' => $adminId,
                    'created_at' => $maintenant,
                    'updated_at' => $maintenant,
                ]);
            }
        }

        if ($expertId !== null) {
            DB::table('memberships')
                ->whereIn('role_id', $anciensIds)
                ->select(['tenant_id', 'user_id'])
                ->distinct()
                ->orderBy('tenant_id')
                ->orderBy('user_id')
                ->get()
                ->each(function (object $membership) use ($expertId, $maintenant): void {
                    DB::table('memberships')->insertOrIgnore([
                        'uuid' => (string) Str::uuid7(),
                        'tenant_id' => $membership->tenant_id,
                        'user_id' => $membership->user_id,
                        'role_id' => $expertId,
                        'created_at' => $maintenant,
                        'updated_at' => $maintenant,
                    ]);
                });
        }

        DB::table('roles')
            ->whereNull('tenant_id')
            ->whereIn('code', self::ANCIENS_ROLES)
            ->update(['is_active' => false, 'updated_at' => $maintenant]);

        $this->configurerGardePublication(separerLesActeurs: false);
        $this->interdireLaSuppressionDesQuestions();
    }

    private function configurerGardePublication(bool $separerLesActeurs): void
    {
        $gardeActeurs = $separerLesActeurs
            ? <<<'SQL'

                IF NEW.validator_id = NEW.author_id THEN
                    RAISE EXCEPTION
                        'Publication refusée : le valideur ne peut pas être l''auteur (METHODE §7.2).';
                END IF;
                SQL
            : '';

        $sql = str_replace('__GARDE_ACTEURS__', $gardeActeurs, <<<'SQL'
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
                __GARDE_ACTEURS__

                PERFORM 1 FROM question_options WHERE question_id = NEW.id FOR UPDATE;
                PERFORM 1 FROM question_sources WHERE question_id = NEW.id FOR UPDATE;

                SELECT count(*),
                       count(*) FILTER (WHERE is_correct),
                       count(*) FILTER (WHERE rationale IS NULL OR length(btrim(rationale)) = 0),
                       count(*) FILTER (WHERE NOT is_correct AND cause IS NULL)
                INTO nb_options, nb_correctes, nb_sans_justif, nb_sans_cause
                FROM question_options WHERE question_id = NEW.id;

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

        DB::unprepared($sql);
    }

    private function interdireLaSuppressionDesQuestions(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION refuse_question_delete()
            RETURNS TRIGGER AS $$
            BEGIN
                RAISE EXCEPTION 'Une question se retire, elle ne se supprime jamais.';
            END;
            $$ LANGUAGE plpgsql;

            DROP TRIGGER IF EXISTS questions_never_deleted ON questions;
            CREATE TRIGGER questions_never_deleted
                BEFORE DELETE ON questions
                FOR EACH ROW EXECUTE FUNCTION refuse_question_delete();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS questions_never_deleted ON questions');
        DB::unprepared('DROP FUNCTION IF EXISTS refuse_question_delete()');

        $this->configurerGardePublication(separerLesActeurs: true);

        $expertId = DB::table('roles')
            ->whereNull('tenant_id')
            ->where('code', 'expert_pedagogue')
            ->value('id');

        if ($expertId !== null) {
            DB::table('memberships')->where('role_id', $expertId)->delete();
            DB::table('permission_role')->where('role_id', $expertId)->delete();
            DB::table('roles')->where('id', $expertId)->delete();
        }

        DB::table('roles')
            ->whereNull('tenant_id')
            ->whereIn('code', self::ANCIENS_ROLES)
            ->update(['is_active' => true, 'updated_at' => now()]);

        if (Schema::hasColumn('roles', 'is_active')) {
            Schema::table('roles', function (Blueprint $table) {
                $table->dropColumn('is_active');
            });
        }
    }
};
