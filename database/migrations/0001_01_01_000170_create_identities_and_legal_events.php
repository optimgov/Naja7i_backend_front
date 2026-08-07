<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * PAS-2 — Identités de connexion et événements juridiques.
 *
 * CORRECTION BLOC-4 de la revue externe. La conception initiale parlait de
 * « consentements » pour trois choses de nature juridique différente :
 *
 *  - Les CGU sont une ACCEPTATION CONTRACTUELLE. On ne « consent » pas à
 *    l'exécution d'un contrat, on l'accepte.
 *  - La politique de confidentialité est une PRISE DE CONNAISSANCE. Le
 *    traitement nécessaire au service repose sur l'exécution du contrat, pas
 *    sur le consentement — et si le candidat ne peut pas refuser, ce n'est
 *    par définition pas un consentement.
 *  - Le marketing est un VRAI CONSENTEMENT : libre, révocable à tout moment.
 *
 * D'où deux tables :
 *  - `legal_documents` : le document publié, versionné, avec son empreinte.
 *  - `legal_events` : ce que l'utilisateur a fait, référençant le DOCUMENT
 *    EXACT. L'état courant se calcule par rapport au document actuellement
 *    publié — pas par « dernière ligne par type », qui laissait une
 *    acceptation de la v1 satisfaire indûment la v2.
 *
 * Tables GLOBALES, sans tenant_id (exception assumée à la règle « activité =
 * tenant_id ») : c'est le DOCUMENT qui porte le responsable de traitement.
 * Dupliquer tenant_id sur l'événement créerait deux sources de vérité.
 * Voir ADR-0005.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE TYPE identity_provider AS ENUM ('password', 'google', 'facebook')");
        DB::statement("CREATE TYPE legal_document_kind AS ENUM ('terms', 'privacy_notice', 'marketing')");
        DB::statement(
            "CREATE TYPE legal_event_action AS ENUM (
                'terms_accepted',
                'privacy_notice_acknowledged',
                'marketing_granted',
                'marketing_withdrawn'
            )"
        );

        // --- Identités de connexion --------------------------------------
        Schema::create('identities', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider_user_id')->nullable();   // sub OIDC, null pour password
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE identities ADD COLUMN provider identity_provider NOT NULL');
        DB::statement('CREATE UNIQUE INDEX identities_user_provider_unique ON identities (user_id, provider)');
        DB::statement(
            'CREATE UNIQUE INDEX identities_provider_subject_unique ON identities (provider, provider_user_id)
             WHERE provider_user_id IS NOT NULL'
        );

        // --- Documents juridiques publiés ---------------------------------
        Schema::create('legal_documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('version');                  // ex. "2026-08-01"
            $table->string('locale', 2);                // fr | ar : chaque langue a son texte
            $table->string('title');
            $table->text('summary');
            $table->string('document_url')->nullable();
            $table->string('checksum', 64);             // SHA-256 du texte publié
            $table->timestampTz('published_at');
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE legal_documents ADD COLUMN kind legal_document_kind NOT NULL');
        DB::statement(
            'CREATE UNIQUE INDEX legal_documents_kind_version_locale_unique
             ON legal_documents (kind, version, locale)'
        );

        // --- Événements juridiques de l'utilisateur ------------------------
        Schema::create('legal_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('legal_document_id')->constrained()->restrictOnDelete();
            $table->string('channel', 32)->default('web');       // web | app | support
            $table->string('ip_prefix', 45)->nullable();         // IP TRONQUÉE (minimisation)
            $table->string('user_agent_hmac', 64)->nullable();   // HMAC, jamais un hash nu
            $table->string('request_id', 64)->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampsTz();

            $table->index(['user_id', 'legal_document_id']);
        });

        DB::statement('ALTER TABLE legal_events ADD COLUMN action legal_event_action NOT NULL');
        DB::statement('CREATE INDEX legal_events_user_action_idx ON legal_events (user_id, action, occurred_at DESC)');

        $this->seedProvisionalDocuments();
    }

    /**
     * Documents PROVISOIRES, explicitement marqués comme tels.
     * Le développement peut commencer ; la mise en ligne est bloquée tant que
     * les textes FR et AR validés juridiquement ne sont pas fournis (avec leur
     * version et leur empreinte). Voir docs/DETTE.md — DET-07.
     */
    private function seedProvisionalDocuments(): void
    {
        $now = now();
        $rows = [
            ['terms', 'fr', 'Conditions générales d\'utilisation',
                '[PROVISOIRE — texte juridique non validé] Règles d\'utilisation de la plateforme Naja7i.ma.'],
            ['terms', 'ar', 'الشروط العامة للاستخدام',
                '[مؤقت — نص قانوني غير مصادق عليه] قواعد استخدام منصة نجاحي.'],
            ['privacy_notice', 'fr', 'Politique de confidentialité',
                '[PROVISOIRE — texte juridique non validé] Données traitées pour fournir le service : compte, progression, résultats.'],
            ['privacy_notice', 'ar', 'سياسة الخصوصية',
                '[مؤقت — نص قانوني غير مصادق عليه] البيانات المعالجة لتقديم الخدمة: الحساب، التقدم، النتائج.'],
            ['marketing', 'fr', 'Messages de rappel et de révision',
                '[PROVISOIRE] Recevoir rappels et conseils par WhatsApp et e-mail. Révocable à tout moment.'],
            ['marketing', 'ar', 'رسائل التذكير والمراجعة',
                '[مؤقت] تلقي التذكيرات والنصائح عبر واتساب والبريد الإلكتروني. يمكن الإلغاء في أي وقت.'],
        ];

        foreach ($rows as [$kind, $locale, $title, $summary]) {
            DB::table('legal_documents')->insert([
                'uuid' => (string) Str::uuid7(),
                'kind' => $kind,
                'version' => '0.1-provisoire',
                'locale' => $locale,
                'title' => $title,
                'summary' => $summary,
                'checksum' => hash('sha256', $summary),
                'published_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_events');
        Schema::dropIfExists('legal_documents');
        Schema::dropIfExists('identities');
        DB::statement('DROP TYPE IF EXISTS legal_event_action');
        DB::statement('DROP TYPE IF EXISTS legal_document_kind');
        DB::statement('DROP TYPE IF EXISTS identity_provider');
    }
};
