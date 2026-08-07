<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PAS-1 — RBAC : rôles globaux, appartenances par tenant.
 *
 * Règles (NAJAH-BACK-001 v1.3 §1.4, cadrage §10.2) :
 *  - roles est un RÉFÉRENTIEL GLOBAL (pas un enum : un référentiel peut
 *    recevoir des permissions fines plus tard sans migration de type).
 *  - memberships est la SEULE table qui relie user × tenant × rôle.
 *    UNIQUE (tenant_id, user_id, role_id). Un même utilisateur peut être
 *    candidat sur la plateforme et formateur dans un centre partenaire.
 *  - is_staff distingue les rôles back-office (MFA obligatoire, §8.4)
 *    des rôles candidats.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('label_fr');
            $table->string('label_ar');
            $table->boolean('is_staff')->default(false);
            $table->timestampsTz();
        });

        DB::table('roles')->insert([
            ['code' => 'candidat',    'label_fr' => 'Candidat',            'label_ar' => 'مترشح',          'is_staff' => false, 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'auteur',      'label_fr' => 'Auteur',              'label_ar' => 'محرر الأسئلة',   'is_staff' => true,  'created_at' => now(), 'updated_at' => now()],
            ['code' => 'reviseur',    'label_fr' => 'Réviseur',            'label_ar' => 'مراجع',          'is_staff' => true,  'created_at' => now(), 'updated_at' => now()],
            ['code' => 'editeur',     'label_fr' => 'Éditeur',             'label_ar' => 'ناشر',           'is_staff' => true,  'created_at' => now(), 'updated_at' => now()],
            ['code' => 'support',     'label_fr' => 'Support',             'label_ar' => 'الدعم',          'is_staff' => true,  'created_at' => now(), 'updated_at' => now()],
            ['code' => 'finance',     'label_fr' => 'Finance',             'label_ar' => 'المالية',        'is_staff' => true,  'created_at' => now(), 'updated_at' => now()],
            ['code' => 'super_admin', 'label_fr' => 'Super administrateur', 'label_ar' => 'مدير عام',       'is_staff' => true,  'created_at' => now(), 'updated_at' => now()],
        ]);

        Schema::create('memberships', function (Blueprint $table) {
            $table->id();
            // Identifiant public : l'id bigint interne ne sort jamais (§ « Règles »).
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->restrictOnDelete();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'user_id', 'role_id']);
            // tenant_id en tête d'index : règle d'isolation §1.3.
            $table->index(['tenant_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('memberships');
        Schema::dropIfExists('roles');
    }
};
