<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Le chemin de revenu — offre, commande, coupon.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * CE QUI NE CHANGE PAS, ET C'EST L'ESSENTIEL
 *
 * `access_grants` n'est pas touché. Le mur payant continue de lire
 * `AccessGrant`, et il ne saura jamais qu'un abonnement existe : une commande
 * honorée POSE des octrois, et c'est tout ce que le reste du produit en voit.
 * `grant_origin` porte déjà `purchase` et `origin_reference` était déjà
 * annotée « commande, code promo, dossier support » — les fondations
 * attendaient ce lot.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * LE PRIX EST EN CENTIMES ENTIERS, ET LA DEVISE EST STOCKÉE
 *
 * Jamais de flottant sur de la monnaie : `19900` centimes, pas `199.00`. Un
 * `numeric` conviendrait aussi, mais l'entier interdit à la question de se
 * poser à chaque écriture.
 *
 * La devise est là dès maintenant alors qu'il n'y en a qu'une. L'ajouter plus
 * tard demanderait de décider ce que valent les lignes existantes — et la
 * réponse « MAD, sûrement » est exactement le genre de supposition qui coûte
 * cher sur de la comptabilité.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE TYPE order_status AS ENUM ('en_attente', 'honoree', 'annulee', 'expiree')");
        DB::statement("CREATE TYPE order_method AS ENUM ('coupon', 'simule')");
        DB::statement("CREATE TYPE coupon_status AS ENUM ('actif', 'epuise', 'expire', 'revoque')");

        /* ─────────────────────────────────────────────────────── plans ── */
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('code', 64)->unique();

            $table->string('name_fr');
            $table->string('name_ar');
            $table->text('description_fr')->nullable();
            $table->text('description_ar')->nullable();

            $table->unsignedInteger('price_cents');
            $table->char('currency', 3)->default('MAD');

            /* Nul = SANS TERME. C'est la même convention que
             * `access_grants.ends_at`, et ce n'est pas un hasard : la durée du
             * plan devient l'échéance de l'octroi. */
            $table->unsignedSmallInteger('duration_days')->nullable();

            /* LE LIEN AVEC `AccessGrant`, et il est explicite. Un plan n'est
             * rien d'autre qu'une liste de capacités et une durée : c'est ce
             * qui permet d'en ajouter un sans toucher au code. */
            $table->jsonb('capabilities');

            /* DÉSACTIVATION, JAMAIS SUPPRESSION : des commandes pointent ici,
             * et une commande dont le plan a disparu ne se relit plus. */
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('position')->default(0);

            $table->timestampsTz();
        });

        /* ───────────────────────────────────────────────────── coupons ── */
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            /* Lisible à l'oral et non devinable — voir `Coupon::engendrer()`.
             * Insensible à la casse en LECTURE, stocké en majuscules. */
            $table->string('code', 32)->unique();

            $table->foreignId('plan_id')->constrained()->restrictOnDelete();

            $table->timestampTz('valid_from');
            $table->timestampTz('valid_until')->nullable();

            /* « Un lot de 50 pour un partenaire » doit être possible. Le
             * compteur est incrémenté à la SAISIE, pas à la validation :
             * réserver l'usage dès la saisie empêche cinquante et une
             * personnes de saisir un coupon de cinquante. */
            $table->unsignedInteger('max_uses')->default(1);
            $table->unsignedInteger('used_count')->default(0);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            /* « virement du 14/08 », « partenariat AREF Oriental ». Interne. */
            $table->text('note')->nullable();

            $table->timestampsTz();
            $table->index(['plan_id', 'valid_until']);
        });

        DB::statement("ALTER TABLE coupons ADD COLUMN status coupon_status NOT NULL DEFAULT 'actif'");
        DB::statement(
            'ALTER TABLE coupons ADD CONSTRAINT coupons_uses_coherent
             CHECK (used_count <= max_uses AND max_uses >= 1)'
        );
        DB::statement(
            'ALTER TABLE coupons ADD CONSTRAINT coupons_validity_coherent
             CHECK (valid_until IS NULL OR valid_until > valid_from)'
        );

        /* ────────────────────────────────────────────────────── orders ── */
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            /* Table d'ACTIVITÉ, donc isolée par tenant (ADR-0002). */
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->foreignId('coupon_id')->nullable()->constrained()->nullOnDelete();

            /*
             * LE MONTANT EST FIGÉ À LA COMMANDE, et c'est une règle de fond.
             *
             * Le prix d'un plan change ; une commande passée ne change pas.
             * Lire le prix depuis `plans` au moment d'afficher un historique
             * réécrirait le passé — et sur de la monnaie, réécrire le passé
             * n'est pas une imprécision, c'est une faute.
             */
            $table->unsignedInteger('amount_cents');
            $table->char('currency', 3);

            $table->string('external_reference')->nullable();

            /* Le patron des cinq bloquants : clé d'idempotence et empreinte,
             * comme sur `attempts` depuis le PAS-6. */
            $table->string('idempotency_key', 64)->nullable();
            $table->string('idempotency_fingerprint', 64)->nullable();

            $table->timestampTz('honored_at')->nullable();

            /*
             * QUI A VALIDÉ, ET QUAND — sur la COMMANDE, pas sur le coupon.
             *
             * Un coupon de cinquante usages est validé cinquante fois, une par
             * candidat : porter `validated_by` sur le coupon n'aurait gardé que
             * la dernière validation. La piste d'audit financière veut savoir
             * qui a ouvert CE droit-là, à CE candidat-là.
             */
            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('validated_at')->nullable();

            /* MOTIF INTERNE, jamais servi au candidat — même règle que DET-50 :
             * un refus motivé se lit en back-office, pas dans une API publique. */
            $table->text('refusal_reason')->nullable();

            $table->timestampsTz();
            $table->index(['user_id', 'created_at']);
        });

        DB::statement("ALTER TABLE orders ADD COLUMN status order_status NOT NULL DEFAULT 'en_attente'");
        DB::statement('ALTER TABLE orders ADD COLUMN method order_method NOT NULL');

        /* Une clé d'idempotence ne vaut que pour un candidat, et une seule fois. */
        DB::statement(
            'CREATE UNIQUE INDEX orders_tenant_user_idempotency_unique
             ON orders (tenant_id, user_id, idempotency_key)
             WHERE idempotency_key IS NOT NULL'
        );

        DB::statement(
            "ALTER TABLE orders ADD CONSTRAINT orders_honored_has_date
             CHECK ((status = 'honoree') = (honored_at IS NOT NULL))"
        );

        /*
         * REJOUER N'OUVRE PAS UN SECOND DROIT — la garantie est EN BASE.
         *
         * `honorer()` est déjà idempotent et transactionnel, mais une garantie
         * applicative ne survit pas à un second chemin d'écriture : un import,
         * une reprise, un correctif. L'index dit la règle là où elle ne peut
         * pas être contournée — une commande n'ouvre qu'un octroi par capacité.
         */
        DB::statement(
            'CREATE UNIQUE INDEX access_grants_origin_capability_unique
             ON access_grants (user_id, capability, origin_reference)
             WHERE origin_reference IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS access_grants_origin_capability_unique');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('coupons');
        Schema::dropIfExists('plans');

        foreach (['order_status', 'order_method', 'coupon_status'] as $type) {
            DB::statement("DROP TYPE IF EXISTS {$type}");
        }
    }
};
