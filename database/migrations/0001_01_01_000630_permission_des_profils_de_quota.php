<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * `quotas.manage` — la permission de l'admin PÉDAGOGIQUE, et de lui seul.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * POURQUOI PAS `orders.validate`
 *
 * Tout l'objet du partage de la spécification d'administration commerciale est
 * que le nombre ne soit pas décidé par celle qui vend : « L'admin commerciale
 * ne peut pas fixer un quota de 3 pour améliorer la conversion : la borne
 * basse le refuse. » Ranger les profils sous une permission de finance
 * détruirait la séparation en une ligne — la même personne poserait la borne
 * et la sélection.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * POURQUOI PAS `questions.validate` NON PLUS
 *
 * Valider pédagogiquement une question, c'est se prononcer sur un contenu.
 * Fixer le quota de découverte, c'est fixer un paramètre du produit qui
 * décide de ce qu'un candidat obtient sans payer. Un relecteur de contenu
 * n'a pas à hériter du second en obtenant le premier — c'est exactement le
 * raisonnement qui a valu une permission dédiée à `orders.validate`.
 *
 * Attribuée à `editeur`, qui porte déjà l'autorité pédagogique de la
 * plateforme (`questions.validate`, `taxonomy.manage`), et à `super_admin`
 * par sa règle générale, attachée explicitement comme partout ailleurs.
 *
 * RÉSERVÉE À LA PLATEFORME : un profil de quota est un objet de catalogue
 * global, comme les offres. Un organisme ne fixe pas le quota de découverte
 * des candidats de la plateforme.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('permissions')->where('code', 'quotas.manage')->exists()) {
            return;
        }

        $id = DB::table('permissions')->insertGetId([
            'uuid' => (string) Str::uuid7(),
            'code' => 'quotas.manage',
            'domain' => 'catalogue',
            'label_fr' => 'Définir les profils de quota',
            'label_ar' => 'تحديد أنماط الحصص',
            'description_fr' => 'Définir les profils de quota pédagogiques, leur valeur et leurs bornes justifiées.',
            'description_ar' => 'تحديد أنماط الحصص التربوية وقيمها وحدودها المبررة.',
            'platform_only' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (['editeur', 'super_admin'] as $code) {
            $roleId = DB::table('roles')->whereNull('tenant_id')->where('code', $code)->value('id');

            if ($roleId === null) {
                continue;
            }

            if (DB::table('permission_role')
                ->where('permission_id', $id)->where('role_id', $roleId)->exists()) {
                continue;
            }

            DB::table('permission_role')->insert([
                'permission_id' => $id,
                'role_id' => $roleId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        $id = DB::table('permissions')->where('code', 'quotas.manage')->value('id');

        if ($id !== null) {
            DB::table('permission_role')->where('permission_id', $id)->delete();
            DB::table('permissions')->where('id', $id)->delete();
        }
    }
};
