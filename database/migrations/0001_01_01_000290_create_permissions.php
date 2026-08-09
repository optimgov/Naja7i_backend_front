<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * PAS-9 — Permissions fines (ADR-0009), décidées le 8 août et restées
 * inappliquées jusqu'ici. C'est l'écart G10 le plus visible du dépôt : une
 * règle annoncée dans un ADR et non exécutée par le code.
 *
 * Trois apports :
 *
 * 1. Les permissions deviennent des DONNÉES. Ajouter une capacité ne demande
 *    plus de migration, et un administrateur peut ajuster un rôle sans
 *    développeur.
 *
 * 2. `roles.tenant_id` devient nullable. Un organisme pourra définir ses
 *    propres rôles — un « coordonnateur » qui n'existe nulle part ailleurs.
 *    Poser cette colonne maintenant coûte une ligne ; la poser après coup
 *    coûterait une migration sur des données vivantes.
 *
 * 3. Une permission RÉSERVÉE à la plateforme ne peut jamais être attachée à
 *    un rôle de tenant. La garde est en base, pas dans un formulaire : un
 *    administrateur d'organisme ne doit pas pouvoir s'octroyer un pouvoir de
 *    plateforme, même par un chemin détourné.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            /* Code stable et lisible : questions.publish, imports.run…
             * Nommage distinct des CAPACITÉS produit (corrections.cause) pour
             * qu'aucune confusion ne s'installe — ADR-0009 §3. */
            $table->string('code', 64)->unique();
            $table->string('domain', 32);              // questions, imports, finance…

            /* Libellés destinés à l'écran d'attribution. Une permission qu'on
             * ne sait pas décrire en une phrase ne doit pas exister. */
            $table->string('label_fr');
            $table->string('label_ar');
            $table->text('description_fr')->nullable();
            $table->text('description_ar')->nullable();

            /* Réservée à la plateforme : jamais attachable à un rôle d'organisme. */
            $table->boolean('platform_only')->default(false);

            $table->timestampsTz();

            $table->index('domain');
        });

        Schema::create('permission_role', function (Blueprint $table) {
            $table->id();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->timestampsTz();

            $table->unique(['permission_id', 'role_id']);
        });

        // --- Les rôles peuvent appartenir à un organisme --------------------
        Schema::table('roles', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable()->after('id')
                ->constrained()->cascadeOnDelete();

            /* Identifiant public (ADR-0002), qui clôt DET-01 : `Role` était le
             * dernier modèle exposé sans UUID, et l'écran d'attribution des
             * rôles va le désigner depuis le front. `code` reste lisible mais
             * cesse d'être l'identifiant de référence — il n'est plus unique
             * globalement depuis que les organismes définissent leurs rôles. */
            $table->uuid('uuid')->nullable()->after('id');
        });

        /* Les sept rôles de plateforme existent depuis le PAS-1 : ils sont
         * complétés avant que la colonne devienne obligatoire. */
        foreach (DB::table('roles')->whereNull('uuid')->pluck('id') as $id) {
            DB::table('roles')->where('id', $id)
                ->update(['uuid' => (string) Str::uuid7()]);
        }

        DB::statement('ALTER TABLE roles ALTER COLUMN uuid SET NOT NULL');
        DB::statement('CREATE UNIQUE INDEX roles_uuid_unique ON roles (uuid)');

        // Un code de rôle est unique par portée : « coordonnateur » peut
        // exister chez deux organismes différents, mais pas deux fois chez le
        // même. L'unicité globale précédente devient partielle.
        DB::statement('ALTER TABLE roles DROP CONSTRAINT IF EXISTS roles_code_unique');
        DB::statement('DROP INDEX IF EXISTS roles_code_unique');
        DB::statement('CREATE UNIQUE INDEX roles_code_platform_unique ON roles (code) WHERE tenant_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX roles_code_tenant_unique ON roles (tenant_id, code) WHERE tenant_id IS NOT NULL');

        $this->gardePermissionsReservees();
        $this->seedPermissions();
    }

    /**
     * Empêche l'attachement d'une permission de plateforme à un rôle
     * d'organisme. En base : un formulaire se contourne, une contrainte non.
     */
    private function gardePermissionsReservees(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION assert_permission_scope()
            RETURNS TRIGGER AS $$
            DECLARE
                reservee boolean;
                role_tenant bigint;
                code_permission text;
            BEGIN
                SELECT platform_only, code INTO reservee, code_permission
                FROM permissions WHERE id = NEW.permission_id;

                SELECT tenant_id INTO role_tenant FROM roles WHERE id = NEW.role_id;

                IF reservee AND role_tenant IS NOT NULL THEN
                    RAISE EXCEPTION
                        'La permission « % » est réservée à la plateforme : elle ne peut pas être attachée à un rôle d''organisme.',
                        code_permission;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER permission_role_scope_guard
                BEFORE INSERT OR UPDATE ON permission_role
                FOR EACH ROW EXECUTE FUNCTION assert_permission_scope();
        SQL);
    }

    /**
     * Permissions initiales. Règle de l'ADR-0009 : une permission n'est créée
     * que lorsqu'une policy l'utilise réellement. Cette liste correspond aux
     * actions déjà existantes ou immédiatement nécessaires — pas à un
     * catalogue spéculatif.
     */
    private function seedPermissions(): void
    {
        $now = now();

        $permissions = [
            // domaine, code, fr, ar, réservée plateforme
            ['questions', 'questions.view',      'Consulter la banque de questions', 'الاطلاع على بنك الأسئلة', false],
            ['questions', 'questions.create',    'Rédiger une question',             'تحرير سؤال',              false],
            ['questions', 'questions.review',    'Réviser une question',             'مراجعة سؤال',             false],
            ['questions', 'questions.validate',  'Valider pédagogiquement',          'المصادقة البيداغوجية',     false],
            ['questions', 'questions.publish',   'Publier une question',             'نشر سؤال',                false],
            ['questions', 'questions.retire',    'Retirer une question publiée',     'سحب سؤال منشور',          false],

            ['catalogue', 'catalogue.view',      'Consulter le catalogue',           'الاطلاع على الفهرس',       false],
            ['catalogue', 'catalogue.manage',    'Créer et modifier un concours',    'إنشاء وتعديل مباراة',      true],
            ['catalogue', 'taxonomy.manage',     'Définir la taxonomie d\'une épreuve', 'تحديد تصنيف اختبار',    true],

            ['members',   'members.view',        'Consulter les membres',            'الاطلاع على الأعضاء',      false],
            ['members',   'members.invite',      'Inviter un membre',                'دعوة عضو',                false],
            ['members',   'roles.assign',        'Attribuer un rôle',                'إسناد دور',               false],
            ['members',   'roles.manage',        'Créer et modifier les rôles',      'إنشاء وتعديل الأدوار',     false],

            ['support',   'users.support',       'Assister un candidat',             'مساعدة مترشح',            true],
            ['support',   'grants.manage',       'Accorder ou révoquer un accès',    'منح أو سحب حق الولوج',     true],

            ['finance',   'orders.view',         'Consulter les commandes',          'الاطلاع على الطلبات',      true],
            ['finance',   'refunds.issue',       'Émettre un remboursement',         'إصدار استرجاع',           true],

            ['platform',  'tenants.manage',      'Gérer les organismes',             'تدبير المؤسسات',          true],
            ['platform',  'permissions.manage',  'Gérer le référentiel de permissions', 'تدبير مرجع الأذونات',  true],
        ];

        foreach ($permissions as [$domaine, $code, $fr, $ar, $reservee]) {
            DB::table('permissions')->insert([
                'uuid' => (string) Str::uuid7(),
                'code' => $code,
                'domain' => $domaine,
                'label_fr' => $fr,
                'label_ar' => $ar,
                'platform_only' => $reservee,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $this->attacherAuxRoles();
    }

    /** Attribution initiale, reprenant les sept rôles du PAS-1. */
    private function attacherAuxRoles(): void
    {
        $attributions = [
            'candidat' => [],
            'auteur' => ['questions.view', 'questions.create', 'catalogue.view'],
            'reviseur' => ['questions.view', 'questions.review', 'catalogue.view'],
            'editeur' => ['questions.view', 'questions.create', 'questions.review',
                'questions.validate', 'questions.publish', 'questions.retire',
                'catalogue.view', 'catalogue.manage', 'taxonomy.manage'],
            'support' => ['questions.view', 'catalogue.view', 'members.view',
                'users.support', 'grants.manage'],
            'finance' => ['orders.view', 'refunds.issue'],
            'super_admin' => null,   // toutes
        ];

        $toutes = DB::table('permissions')->pluck('id', 'code');

        foreach ($attributions as $roleCode => $codes) {
            $roleId = DB::table('roles')->where('code', $roleCode)->whereNull('tenant_id')->value('id');

            if ($roleId === null) {
                continue;
            }

            $liste = $codes === null ? $toutes->keys()->all() : $codes;

            foreach ($liste as $code) {
                if (! isset($toutes[$code])) {
                    continue;
                }

                DB::table('permission_role')->insert([
                    'permission_id' => $toutes[$code],
                    'role_id' => $roleId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS permission_role_scope_guard ON permission_role');
        DB::unprepared('DROP FUNCTION IF EXISTS assert_permission_scope()');

        DB::statement('DROP INDEX IF EXISTS roles_code_tenant_unique');
        DB::statement('DROP INDEX IF EXISTS roles_code_platform_unique');
        DB::statement('CREATE UNIQUE INDEX roles_code_unique ON roles (code)');

        DB::statement('DROP INDEX IF EXISTS roles_uuid_unique');

        Schema::table('roles', function (Blueprint $t) {
            $t->dropConstrainedForeignId('tenant_id');
            $t->dropColumn('uuid');
        });

        Schema::dropIfExists('permission_role');
        Schema::dropIfExists('permissions');
    }
};
