<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * V1.1 — étape A compatible de la messagerie interne.
 *
 * Le domaine est ajouté sans retirer les pouvoirs historiques du support.
 * Cette réduction appartient à une migration ultérieure, après recette croisée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('complaint_threads', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('candidate_id')->constrained('users')->restrictOnDelete();
            $table->string('category', 32);
            $table->string('subject', 160);
            $table->string('status', 32);
            $table->timestampTz('last_message_at');
            $table->timestampsTz();

            $table->index(['tenant_id', 'candidate_id', 'last_message_at'], 'complaint_threads_candidate_idx');
            $table->index(['tenant_id', 'status', 'last_message_at'], 'complaint_threads_staff_idx');
            $table->unique(['tenant_id', 'id'], 'complaint_threads_tenant_id_unique');
        });

        Schema::create('complaint_messages', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('complaint_thread_id');
            $table->foreignId('sender_id')->constrained('users')->restrictOnDelete();
            $table->string('sender_type', 16);
            $table->text('body');
            $table->string('idempotency_key', 64);
            $table->string('idempotency_fingerprint', 64);
            $table->string('operation', 32);
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(
                ['tenant_id', 'sender_id', 'idempotency_key'],
                'complaint_messages_tenant_sender_idempotency_unique',
            );
            $table->index(['tenant_id', 'complaint_thread_id', 'id'], 'complaint_messages_thread_idx');
        });

        DB::statement(
            'ALTER TABLE complaint_messages
             ADD CONSTRAINT complaint_messages_thread_tenant_fk
             FOREIGN KEY (tenant_id, complaint_thread_id)
             REFERENCES complaint_threads (tenant_id, id)
             ON DELETE RESTRICT'
        );

        DB::statement(
            "ALTER TABLE complaint_threads
             ADD CONSTRAINT complaint_threads_category_valid
             CHECK (category IN ('technical', 'pedagogical', 'account', 'payment', 'other'))"
        );
        DB::statement(
            "ALTER TABLE complaint_threads
             ADD CONSTRAINT complaint_threads_status_valid
             CHECK (status IN ('waiting_staff', 'waiting_candidate'))"
        );
        DB::statement(
            "ALTER TABLE complaint_messages
             ADD CONSTRAINT complaint_messages_sender_type_valid
             CHECK (sender_type IN ('candidate', 'staff'))"
        );
        DB::statement('ALTER TABLE complaint_messages ADD CONSTRAINT complaint_messages_body_not_blank CHECK (length(btrim(body)) > 0)');
        DB::statement('ALTER TABLE complaint_threads ADD CONSTRAINT complaint_threads_subject_not_blank CHECK (length(btrim(subject)) > 0)');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION guard_complaint_thread_mutation()
            RETURNS TRIGGER AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Une reclamation ne se supprime pas.';
                END IF;

                IF NEW.uuid IS DISTINCT FROM OLD.uuid
                   OR NEW.tenant_id IS DISTINCT FROM OLD.tenant_id
                   OR NEW.candidate_id IS DISTINCT FROM OLD.candidate_id
                   OR NEW.category IS DISTINCT FROM OLD.category
                   OR NEW.subject IS DISTINCT FROM OLD.subject
                   OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                    RAISE EXCEPTION 'L''identite d''une reclamation est immuable.';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER complaint_threads_guard
                BEFORE UPDATE OR DELETE ON complaint_threads
                FOR EACH ROW EXECUTE FUNCTION guard_complaint_thread_mutation();

            CREATE OR REPLACE FUNCTION guard_complaint_message_append_only()
            RETURNS TRIGGER AS $$
            BEGIN
                RAISE EXCEPTION 'Les messages de reclamation sont en ajout seul.';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER complaint_messages_append_only
                BEFORE UPDATE OR DELETE ON complaint_messages
                FOR EACH ROW EXECUTE FUNCTION guard_complaint_message_append_only();

            CREATE OR REPLACE FUNCTION assert_complaint_message_sender()
            RETURNS TRIGGER AS $$
            DECLARE
                thread_candidate bigint;
            BEGIN
                SELECT candidate_id INTO thread_candidate
                FROM complaint_threads
                WHERE tenant_id = NEW.tenant_id AND id = NEW.complaint_thread_id;

                IF NEW.sender_type = 'candidate' AND NEW.sender_id <> thread_candidate THEN
                    RAISE EXCEPTION 'Un candidat ne peut ecrire que dans sa propre reclamation.';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER complaint_messages_sender_guard
                BEFORE INSERT ON complaint_messages
                FOR EACH ROW EXECUTE FUNCTION assert_complaint_message_sender();
        SQL);

        $maintenant = now();
        $permissions = [
            'complaints.view' => [
                'Consulter les réclamations internes',
                'الاطلاع على الشكايات الداخلية',
            ],
            'complaints.reply' => [
                'Répondre aux réclamations internes',
                'الرد على الشكايات الداخلية',
            ],
        ];

        foreach ($permissions as $code => [$fr, $ar]) {
            DB::table('permissions')->updateOrInsert(
                ['code' => $code],
                [
                    'uuid' => DB::table('permissions')->where('code', $code)->value('uuid') ?? (string) Str::uuid7(),
                    'domain' => 'complaints',
                    'label_fr' => $fr,
                    'label_ar' => $ar,
                    'description_fr' => null,
                    'description_ar' => null,
                    'platform_only' => true,
                    'created_at' => DB::table('permissions')->where('code', $code)->value('created_at') ?? $maintenant,
                    'updated_at' => $maintenant,
                ],
            );
        }

        $roleIds = DB::table('roles')
            ->whereNull('tenant_id')
            ->whereIn('code', ['expert_pedagogue', 'support', 'super_admin'])
            ->pluck('id');
        $permissionIds = DB::table('permissions')->whereIn('code', array_keys($permissions))->pluck('id');

        foreach ($roleIds as $roleId) {
            foreach ($permissionIds as $permissionId) {
                DB::table('permission_role')->insertOrIgnore([
                    'permission_id' => $permissionId,
                    'role_id' => $roleId,
                    'created_at' => $maintenant,
                    'updated_at' => $maintenant,
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS complaint_messages_sender_guard ON complaint_messages');
        DB::unprepared('DROP TRIGGER IF EXISTS complaint_messages_append_only ON complaint_messages');
        DB::unprepared('DROP TRIGGER IF EXISTS complaint_threads_guard ON complaint_threads');

        Schema::dropIfExists('complaint_messages');
        Schema::dropIfExists('complaint_threads');

        DB::unprepared('DROP FUNCTION IF EXISTS assert_complaint_message_sender()');
        DB::unprepared('DROP FUNCTION IF EXISTS guard_complaint_message_append_only()');
        DB::unprepared('DROP FUNCTION IF EXISTS guard_complaint_thread_mutation()');

        DB::table('permissions')->whereIn('code', ['complaints.view', 'complaints.reply'])->delete();
    }
};
