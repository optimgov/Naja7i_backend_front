<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * `plans.editorial_fix` — corriger une coquille sur une version déjà vendue.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * POURQUOI UNE PERMISSION À ELLE SEULE
 *
 * Le geste est minuscule et la porte est grande : il écrit dans une ligne que
 * la base déclare immuable, sous une commande déjà honorée. Le ranger sous
 * `orders.validate` donnerait ce droit à qui valide les paiements ; le ranger
 * sous une permission de catalogue le donnerait à qui compose les offres, qui
 * n'a précisément aucun besoin de réécrire ce qui est vendu — elle publie une
 * version nouvelle. Une permission propre est la seule façon de répondre « qui
 * a corrigé ce texte, et pourquoi lui » sans lire le code.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * PORTÉE PAR `editeur`, COMME `quotas.manage`
 *
 * Le geste est éditorial : c'est une faute de langue dans un texte bilingue,
 * pas une décision commerciale. `editeur` porte déjà l'autorité éditoriale de
 * la plateforme, et `super_admin` par sa règle générale — attachée
 * explicitement, comme partout ailleurs.
 *
 * RÉSERVÉE À LA PLATEFORME : une version d'offre est un objet de catalogue
 * global. Un organisme ne corrige pas le nom d'un pack de la plateforme.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('permissions')->where('code', 'plans.editorial_fix')->exists()) {
            return;
        }

        $id = DB::table('permissions')->insertGetId([
            'uuid' => (string) Str::uuid7(),
            'code' => 'plans.editorial_fix',
            'domain' => 'catalogue',
            'label_fr' => 'Corriger une coquille sur une version d’offre',
            'label_ar' => 'تصحيح خطأ مطبعي في نسخة عرض',
            'description_fr' => 'Corriger le nom ou la description d’une version d’offre déjà vendue, '
                .'avec motif écrit et journal, sans créer de version nouvelle.',
            'description_ar' => 'تصحيح اسم أو وصف نسخة عرض مبيعة، بمبرر مكتوب وسجل، دون إنشاء نسخة جديدة.',
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
        $id = DB::table('permissions')->where('code', 'plans.editorial_fix')->value('id');

        if ($id !== null) {
            DB::table('permission_role')->where('permission_id', $id)->delete();
            DB::table('permissions')->where('id', $id)->delete();
        }
    }
};
