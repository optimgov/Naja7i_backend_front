<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PAS-1.1 (P2) — Tables Laravel supprimées par erreur au PAS-1.
 *
 * En faisant supprimer `0001_01_01_000000_create_users_table.php` pour le
 * remplacer par notre migration users, on a aussi supprimé les DEUX autres
 * tables qu'il contenait : `password_reset_tokens` et `sessions`.
 *
 * Conséquences si on ne les restaure pas :
 *  - « mot de passe oublié » lève une erreur SQL sur table inexistante ;
 *  - un basculement de SESSION_DRIVER vers `database` casse l'application.
 *
 * L'e-mail est en citext ici aussi, pour rester cohérent avec `users` :
 * sinon une demande de réinitialisation sur « Candidat@... » ne retrouverait
 * pas le jeton créé pour « candidat@... ».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('token');
            $table->timestampTz('created_at')->nullable();
        });

        DB::statement('ALTER TABLE password_reset_tokens ADD COLUMN email citext NOT NULL');
        DB::statement('ALTER TABLE password_reset_tokens ADD PRIMARY KEY (email)');

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
    }
};
