<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * `orders.validate` — une permission DÉDIÉE, et c'est délibéré.
 *
 * Le domaine `finance` portait déjà `orders.view` et `refunds.issue`. Valider
 * un coupon n'est ni l'un ni l'autre : **c'est ouvrir un droit qui vaut de
 * l'argent**, sur un compte nommé, sans qu'aucun paiement n'ait transité par un
 * prestataire qui en garderait la trace.
 *
 * La faire tomber sous `orders.view` donnerait le pouvoir d'accorder à qui n'a
 * qu'à consulter ; la faire tomber sous `grants.manage` la noierait dans le
 * support, où elle serait exercée par réflexe. Un geste qui coûte de l'argent
 * mérite d'être refusable indépendamment — c'est tout l'objet du référentiel
 * fin du PAS-9.
 *
 * Attribuée à `finance` seulement. `super_admin` l'obtient par sa règle
 * générale, comme toutes les autres.
 */
return new class extends Migration
{
    public function up(): void
    {
        $existe = DB::table('permissions')->where('code', 'orders.validate')->exists();

        if ($existe) {
            return;
        }

        $id = DB::table('permissions')->insertGetId([
            'uuid' => (string) Str::uuid7(),
            'code' => 'orders.validate',
            'domain' => 'finance',
            'label_fr' => 'Valider une commande en attente',
            'label_ar' => 'المصادقة على طلب في انتظار المعالجة',
            /* Réservée à la plateforme : un organisme ne valide pas les
             * commandes des candidats de la plateforme. */
            'platform_only' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $finance = DB::table('roles')->whereNull('tenant_id')->where('code', 'finance')->value('id');

        if ($finance !== null) {
            DB::table('permission_role')->insert([
                'permission_id' => $id,
                'role_id' => $finance,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        /* `super_admin` détient tout : on l'attache explicitement plutôt que
         * de supposer une règle implicite au moment de l'autorisation. */
        $admin = DB::table('roles')->whereNull('tenant_id')->where('code', 'super_admin')->value('id');

        if ($admin !== null && ! DB::table('permission_role')
            ->where('permission_id', $id)->where('role_id', $admin)->exists()) {
            DB::table('permission_role')->insert([
                'permission_id' => $id,
                'role_id' => $admin,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        $id = DB::table('permissions')->where('code', 'orders.validate')->value('id');

        if ($id !== null) {
            DB::table('permission_role')->where('permission_id', $id)->delete();
            DB::table('permissions')->where('id', $id)->delete();
        }
    }
};
