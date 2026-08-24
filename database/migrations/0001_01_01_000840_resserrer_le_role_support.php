<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Étape B de la messagerie v1.1 : le support ne porte plus que les réclamations.
 *
 * Les anciennes permissions restent dans le référentiel pour l'histoire et
 * pour le super-administrateur ; seule leur attribution au rôle support cesse.
 */
return new class extends Migration
{
    private const CIBLE = ['complaints.view', 'complaints.reply'];

    private const HISTORIQUES = [
        'questions.view',
        'catalogue.view',
        'members.view',
        'users.support',
        'grants.manage',
    ];

    public function up(): void
    {
        $expertId = DB::table('roles')
            ->whereNull('tenant_id')
            ->where('code', 'expert_pedagogue')
            ->value('id');
        $permissionsReclamation = DB::table('permissions')->whereIn('code', self::CIBLE)->pluck('id');

        if ($expertId !== null) {
            DB::table('permission_role')
                ->where('role_id', $expertId)
                ->whereIn('permission_id', $permissionsReclamation)
                ->delete();
        }

        $supportId = DB::table('roles')
            ->whereNull('tenant_id')
            ->where('code', 'support')
            ->value('id');

        if ($supportId === null) {
            return;
        }

        DB::table('permission_role')
            ->where('role_id', $supportId)
            ->whereIn('permission_id', DB::table('permissions')->whereNotIn('code', self::CIBLE)->select('id'))
            ->delete();

        $maintenant = now();

        foreach ($permissionsReclamation as $permissionId) {
            DB::table('permission_role')->insertOrIgnore([
                'permission_id' => $permissionId,
                'role_id' => $supportId,
                'created_at' => $maintenant,
                'updated_at' => $maintenant,
            ]);
        }
    }

    public function down(): void
    {
        $maintenant = now();
        $expertId = DB::table('roles')
            ->whereNull('tenant_id')
            ->where('code', 'expert_pedagogue')
            ->value('id');

        if ($expertId !== null) {
            foreach (DB::table('permissions')->whereIn('code', self::CIBLE)->pluck('id') as $permissionId) {
                DB::table('permission_role')->insertOrIgnore([
                    'permission_id' => $permissionId,
                    'role_id' => $expertId,
                    'created_at' => $maintenant,
                    'updated_at' => $maintenant,
                ]);
            }
        }

        $supportId = DB::table('roles')
            ->whereNull('tenant_id')
            ->where('code', 'support')
            ->value('id');

        if ($supportId === null) {
            return;
        }

        foreach (DB::table('permissions')->whereIn('code', self::HISTORIQUES)->pluck('id') as $permissionId) {
            DB::table('permission_role')->insertOrIgnore([
                'permission_id' => $permissionId,
                'role_id' => $supportId,
                'created_at' => $maintenant,
                'updated_at' => $maintenant,
            ]);
        }
    }
};
