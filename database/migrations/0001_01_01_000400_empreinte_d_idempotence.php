<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Une clé d'idempotence identifie une OPÉRATION, pas un utilisateur.
 *
 * L'unicité ne portait que sur `(tenant_id, user_id, idempotency_key)`, et les
 * trois chemins d'ouverture cherchaient la clé sans regarder ni le genre, ni
 * l'épreuve, ni les paramètres. Réutiliser la clé d'un diagnostic pour ouvrir
 * un entraînement rendait donc le diagnostic — d'un autre concours au besoin.
 *
 * Ce n'est pas qu'une confusion d'écran : les gardes qui refusent une ouverture
 * se contournaient par restitution silencieuse. « Rien à réviser aujourd'hui »
 * et « périmètre trop étroit pour une session utile » ne sont jamais levées si
 * le service rend une tentative préexistante avant de les atteindre.
 *
 * L'empreinte est donc STOCKÉE et COMPARÉE. Rejeu strictement identique : la
 * même ressource, comme avant. Requête différente sous la même clé : un refus
 * explicite, jamais une autre tentative.
 *
 * Nullable, et ce n'est pas de la prudence mal placée : les tentatives
 * antérieures à cette migration n'en ont pas, et leur en inventer une
 * supposerait de deviner les paramètres d'appels déjà servis. Une empreinte
 * absente ne compare rien — le comportement d'avant, pour les lignes d'avant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attempts', function (Blueprint $table) {
            /* SHA-256 tronquée à 64 caractères hexadécimaux. Pas de contrainte
             * d'unicité : deux opérations identiques sous deux clés
             * différentes sont parfaitement licites. */
            $table->string('idempotency_fingerprint', 64)->nullable()->after('idempotency_key');
        });

        /* Quotes SIMPLES : le dollar-quoting `$$` de PostgreSQL et
         * l'interpolation PHP se disputent le même caractère, et « $$Empreinte »
         * se lisait comme une variable. */
        DB::statement(
            'COMMENT ON COLUMN attempts.idempotency_fingerprint IS
             $$Empreinte de la requete qui a ouvert cette tentative (genre, epreuve, parametres). Comparee au rejeu : une requete differente sous la meme cle est refusee, jamais servie par une autre tentative.$$'
        );
    }

    public function down(): void
    {
        Schema::table('attempts', function (Blueprint $table) {
            $table->dropColumn('idempotency_fingerprint');
        });
    }
};
