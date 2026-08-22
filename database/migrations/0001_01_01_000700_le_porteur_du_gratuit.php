<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lot 3A.7, pas 1 — Le gratuit a un porteur, et il n'y en a qu'un.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * CE QUE L'ADR-0025 EXIGE
 *
 * « Le gratuit n'est ni l'absence d'offre, ni un réglage global, ni un paiement
 * nul. Il est porté par une offre gratuite VERSIONNÉE et AUTO-ATTRIBUÉE. » La
 * conséquence est qu'il n'y a rien de spécial à modéliser : l'offre gratuite est
 * un `Plan` comme les autres, avec sa version, son instantané de quota et sa
 * chaîne d'octroi. Ce qui la distingue tient en un drapeau.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * UN DRAPEAU DE CATALOGUE, PAS UN RETRAIT DE LA VENTE
 *
 * L'offre gratuite ne doit pas apparaître au catalogue candidat — « on ne
 * souscrit pas au gratuit, on le reçoit ». Deux mécaniques existaient :
 * `active = false`, ou un drapeau dédié.
 *
 * `active = false` aurait été FAUX. Cette colonne dit « retirée de la vente »,
 * et `CouponGateway` la lit pour refuser une souscription : l'offre gratuite
 * n'est pas retirée, elle est distribuée. Un jour où un rapport comptera les
 * offres actives, elle doit y être. Le drapeau `auto_granted` dit la seule
 * chose vraie : cette offre se reçoit à l'inscription au lieu de s'acheter.
 *
 * IL N'EST PAS CONTRACTUEL — il ne vit donc pas sur `plan_versions`. Le passer
 * de faux à vrai ne change ni ce qu'un candidat obtient ni ce qu'il paie : cela
 * change par quel chemin il l'obtient. Il ne versionne pas.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * UN SEUL PORTEUR, TENU PAR UN INDEX
 *
 * Deux offres auto-attribuées, et l'inscription devrait choisir — donc
 * quelqu'un choisirait pour elle, en silence, à l'ordre d'insertion près. Un
 * index unique partiel rend la question impossible plutôt qu'arbitraire.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * L'ORIGINE `rattrapage`
 *
 * L'inscription pose ses octrois sous `account_level`, une valeur qui existe
 * depuis le PAS-8 et qui dit exactement ce dont il s'agit : un droit qui vient
 * du niveau du compte, pas d'un achat. Le RATTRAPAGE des comptes antérieurs,
 * lui, mérite sa propre valeur : ces droits n'ont pas été posés à l'inscription
 * mais des mois après, par une commande d'administration, et un audit doit
 * pouvoir les distinguer sans deviner. `purchase` reste interdit aux deux —
 * aucun agrégat de vente ne doit compter un droit que personne n'a acheté
 * (ADR-0028, C-05).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->boolean('auto_granted')->default(false)->after('active');
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX plans_un_seul_porteur_du_gratuit
            ON plans ((true)) WHERE auto_granted
            SQL);

        DB::statement("ALTER TYPE grant_origin ADD VALUE IF NOT EXISTS 'rattrapage'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS plans_un_seul_porteur_du_gratuit');

        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('auto_granted');
        });

        /* `grant_origin` conserve `rattrapage` : PostgreSQL ne retire pas une
         * valeur d'énumération, et la reconstruire détruirait les octrois qui
         * la portent. Une valeur inutilisée ne coûte rien ; des droits perdus,
         * si. */
    }
};
