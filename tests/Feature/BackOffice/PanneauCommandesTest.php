<?php

namespace Tests\Feature\BackOffice;

use App\Contracts\AccessGrant;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AbonnementService;
use App\Services\Paiement\CouponGateway;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * La file des commandes — qui peut valider, et ce que la validation trace.
 *
 * Ce qui est éprouvé ici n'est pas l'interface : c'est que le POUVOIR
 * d'ouvrir un droit qui vaut de l'argent soit refusable indépendamment de
 * celui de consulter.
 */
class PanneauCommandesTest extends TestCase
{
    use RefreshDatabase;

    private User $candidat;

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set(Tenant::where('kind', 'platform')->firstOrFail());

        $this->candidat = $this->membre('candidat@naja7i.ma', null);

        $this->plan = Plan::create([
            'code' => 'bo-30j', 'name_fr' => 'Test back-office', 'name_ar' => 'اختبار',
            'price_cents' => 19900, 'currency' => 'MAD', 'duration_days' => 30,
            'capabilities' => [AccessGrant::CAUSE_REVEAL], 'active' => true,
        ]);
    }

    private function membre(string $email, ?string $role): User
    {
        $user = User::create([
            'email' => $email, 'password' => 'une-phrase-de-passe-solide', 'locale' => 'fr',
        ]);
        $user->markEmailAsVerified();

        if ($role === null) {
            $user->grantCandidateRole();

            return $user;
        }

        $roleId = Role::where('code', $role)->whereNull('tenant_id')->value('id');
        $user->memberships()->create(['role_id' => $roleId]);

        return $user;
    }

    private function commandeEnAttente(): Order
    {
        $coupon = Coupon::create([
            'code' => Coupon::engendrer(),
            'plan_id' => $this->plan->id,
            'valid_from' => now()->subDay(),
            'max_uses' => 1, 'used_count' => 0, 'status' => 'actif',
        ]);

        return app(CouponGateway::class)
            ->ouvrir($this->candidat, ['code' => $coupon->code], (string) Str::uuid7());
    }

    // ═══════════════════════ La permission dédiée est le cœur du lot

    public function test_consulter_n_est_pas_valider(): void
    {
        /*
         * `orders.view` et `orders.validate` sont DEUX permissions, et c'est la
         * décision structurante de ce lot. Les confondre donnerait le pouvoir
         * d'accorder à qui n'a qu'à lire.
         *
         * Le rôle `support` porte `grants.manage` — il peut accorder un accès à
         * la main — mais PAS `orders.validate` : valider une commande engage
         * une contrepartie financière, ce que « dépanner un candidat » n'est
         * pas.
         */
        $support = $this->membre('support@naja7i.ma', 'support');
        $commande = $this->commandeEnAttente();

        $this->assertFalse(
            $support->can('validate', $commande),
            'Le support dépanne ; il n\'ouvre pas un droit payant.'
        );
    }

    public function test_un_role_qui_consulte_sans_valider_ne_valide_pas(): void
    {
        /*
         * LE TEST QUI SÉPARE VRAIMENT LES DEUX PERMISSIONS.
         *
         * Le précédent ne le faisait pas : `support` ne porte NI `orders.view`
         * NI `orders.validate`, donc remplacer l'une par l'autre dans la
         * policy ne changeait rien — la mutation restait verte, et je l'ai
         * vérifié avant de l'écrire.
         *
         * Aucun rôle semé ne porte l'une sans l'autre : `finance` a les deux.
         * On en fabrique donc un — c'est exactement ce qu'un administrateur
         * fera le jour où il voudra un comptable qui LIT les commandes sans
         * pouvoir en ouvrir, et c'est le cas d'usage qui justifie la
         * séparation.
         */
        $lecteur = User::create([
            'email' => 'comptable@naja7i.ma', 'password' => 'une-phrase-de-passe-solide', 'locale' => 'fr',
        ]);
        $lecteur->markEmailAsVerified();

        $role = Role::create([
            'code' => 'comptable', 'tenant_id' => null,
            'label_fr' => 'Comptable', 'label_ar' => 'محاسب',
        ]);

        $vue = \DB::table('permissions')->where('code', 'orders.view')->value('id');
        \DB::table('permission_role')->insert([
            'permission_id' => $vue, 'role_id' => $role->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $lecteur->memberships()->create(['role_id' => $role->id]);

        $commande = $this->commandeEnAttente();

        $this->assertTrue($lecteur->can('view', $commande), 'Il consulte…');
        $this->assertFalse($lecteur->can('validate', $commande), '…et il n\'ouvre rien.');
        $this->assertFalse($lecteur->can('create', Coupon::class), 'Ni n\'émet de titre.');
    }

    public function test_le_role_finance_valide(): void
    {
        $finance = $this->membre('finance@naja7i.ma', 'finance');
        $commande = $this->commandeEnAttente();

        $this->assertTrue($finance->can('view', $commande));
        $this->assertTrue($finance->can('validate', $commande));
    }

    public function test_un_candidat_ne_voit_pas_la_file(): void
    {
        $commande = $this->commandeEnAttente();

        $this->assertFalse($this->candidat->can('viewAny', Order::class));
        $this->assertFalse($this->candidat->can('validate', $commande));
    }

    public function test_une_commande_ne_se_modifie_ni_ne_s_efface(): void
    {
        /* Elle est honorée ou refusée, et les deux gestes passent par le
         * service, qui laisse une trace datée et nominative. */
        $finance = $this->membre('finance2@naja7i.ma', 'finance');
        $commande = $this->commandeEnAttente();

        $this->assertFalse($finance->can('update', $commande));
        $this->assertFalse($finance->can('delete', $commande));
    }

    // ═══════════════════════════════ La validation laisse sa trace

    public function test_la_validation_trace_qui_et_quand(): void
    {
        $finance = $this->membre('finance3@naja7i.ma', 'finance');
        $commande = $this->commandeEnAttente();

        app(AbonnementService::class)->honorer($commande, $finance);

        $fraiche = $commande->fresh();

        $this->assertSame($finance->id, $fraiche->validated_by);
        $this->assertNotNull($fraiche->validated_at);
        $this->assertNotNull($fraiche->honored_at);
    }

    public function test_le_refus_trace_son_auteur_et_son_motif(): void
    {
        $finance = $this->membre('finance4@naja7i.ma', 'finance');
        $commande = $this->commandeEnAttente();

        app(AbonnementService::class)
            ->refuser($commande, $finance, 'virement jamais reçu');

        $fraiche = $commande->fresh();

        $this->assertSame('annulee', $fraiche->status);
        $this->assertSame($finance->id, $fraiche->validated_by);
        $this->assertSame('virement jamais reçu', $fraiche->refusal_reason);
    }

    // ═══════════════════════════════════ Le pouvoir d'émettre

    public function test_engendrer_un_coupon_demande_la_meme_permission_que_valider(): void
    {
        /* Un lot de cinquante coupons est cinquante abonnements en puissance :
         * c'est le même pouvoir, exercé plus tôt. */
        $support = $this->membre('support2@naja7i.ma', 'support');
        $finance = $this->membre('finance5@naja7i.ma', 'finance');

        $this->assertFalse($support->can('create', Coupon::class));
        $this->assertTrue($finance->can('create', Coupon::class));
    }

    public function test_un_plan_ne_se_supprime_jamais(): void
    {
        $finance = $this->membre('finance6@naja7i.ma', 'finance');

        $this->assertTrue($finance->can('update', $this->plan));
        $this->assertFalse(
            $finance->can('delete', $this->plan),
            'Des commandes pointent sur un plan : l\'effacer les rendrait illisibles.'
        );
    }

    public function test_la_pastille_compte_les_commandes_en_attente(): void
    {
        $this->assertNull(OrderResource::getNavigationBadge());

        $this->commandeEnAttente();

        $this->assertSame('1', OrderResource::getNavigationBadge());
    }
}
