<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PAS-3 — Jetons de vérification d'e-mail.
 *
 * Pourquoi une table plutôt que les URL signées de Laravel (DET-11) :
 *
 * Une URL signée est calculée sur l'URL COMPLÈTE. Or notre topologie est BFF :
 * le lien reçu par le candidat pointe vers www.naja7i.ma (Nuxt), pas vers
 * l'API. Nitro relaie ensuite en interne, avec un hôte et un schéma différents.
 * La signature calculée à l'émission ne correspond alors plus à l'URL vue à la
 * validation, et le lien est rejeté — un bug pénible à diagnostiquer, qui
 * n'apparaît qu'en environnement proxifié, donc jamais en test local.
 *
 * On émet donc un JETON OPAQUE. Le lien mène au frontend, qui poste le jeton à
 * l'API. Aucune dépendance à l'URL, aucune surprise derrière le proxy.
 *
 * Le jeton n'est stocké que HACHÉ : une fuite de la base ne permet pas de
 * valider les comptes en attente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verification_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();   // SHA-256 du jeton en clair
            $table->string('purpose', 32)->default('email_verification');
            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampsTz();

            $table->index(['user_id', 'purpose']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_tokens');
    }
};
