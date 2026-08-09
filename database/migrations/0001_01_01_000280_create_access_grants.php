<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PAS-8 — Droits d'accès (ADR-0010).
 *
 * Posé AVANT le paiement, comme l'ADR l'exigeait : le jour où CMI arrivera,
 * seule une source d'octroi s'ajoutera. Aucun contrôleur ne bougera.
 *
 * Table GLOBALE, sans `tenant_id` : le droit suit le COMPTE, pas le tenant.
 * Un candidat dont l'organisme a payé conserve son compte et sa progression
 * s'il en part — c'est la position retenue (DET-24), et elle se traduit ici.
 * L'organisme émetteur est conservé dans `origin_tenant_id` à titre de trace,
 * jamais comme condition de validité.
 *
 * Un octroi ne se modifie jamais : une prolongation crée une nouvelle ligne,
 * une révocation pose `ends_at`. On doit pouvoir répondre à « de quoi
 * disposait ce candidat le 14 mars ».
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            "CREATE TYPE grant_origin AS ENUM ('account_level', 'purchase', 'promotion', 'organisme', 'support')"
        );

        Schema::create('access_grants', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            /* Capacité produit : corrections.cause, series.targeted,
             * simulator.full, certification.take… Nommage volontairement
             * distinct des permissions RBAC (questions.publish) pour qu'aucune
             * confusion ne s'installe (ADR-0009 §3). */
            $table->string('capability', 64);

            /* Portée facultative : uuid d'épreuve ou de spécialité.
             * Nul = la capacité vaut partout. */
            $table->uuid('scope_uuid')->nullable();

            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at')->nullable();     // nul = sans terme

            $table->foreignId('origin_tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->string('origin_reference')->nullable(); // commande, code promo, dossier support
            $table->text('note')->nullable();

            $table->timestampsTz();

            $table->index(['user_id', 'capability', 'starts_at']);
        });

        DB::statement('ALTER TABLE access_grants ADD COLUMN origin grant_origin NOT NULL');
        DB::statement(
            'ALTER TABLE access_grants ADD CONSTRAINT access_grants_period_coherent
             CHECK (ends_at IS NULL OR ends_at > starts_at)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('access_grants');
        DB::statement('DROP TYPE IF EXISTS grant_origin');
    }
};
