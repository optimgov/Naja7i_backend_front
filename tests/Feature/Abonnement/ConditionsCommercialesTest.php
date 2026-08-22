<?php

namespace Tests\Feature\Abonnement;

use App\Contracts\AccessGrant;
use App\Exceptions\PaiementRefuse;
use App\Models\AccessGrantRecord;
use App\Models\Audience;
use App\Models\Coupon;
use App\Models\Exam;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AbonnementService;
use App\Services\Paiement\CouponGateway;
use App\Services\PlanVersionService;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Les conditions commerciales — ce qui versionne, ce qui refuse.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * LA RÈGLE ÉPROUVÉE ICI
 *
 * « Un champ versionne s'il change ce qu'un candidat obtient ou ce qu'il paie.
 * Il ne versionne pas s'il change seulement où et quand l'offre se voit. »
 *
 * Le calendrier est le cas limite : il dit quand l'offre se voit, mais il
 * décide aussi SI la souscription est possible. La matrice §5 le fait
 * versionner, et le refus qui va avec est un refus SERVEUR — hors période,
 * l'offre quitte le catalogue et la souscription lève. Jamais un bouton grisé.
 */
class ConditionsCommercialesTest extends TestCase
{
    use RefreshDatabase;

    private Plan $offre;

    private User $candidat;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set(Tenant::where('kind', 'platform')->firstOrFail());

        $this->candidat = User::create([
            'email' => 'conditions@naja7i.ma',
            'password' => 'une-phrase-de-passe-solide',
            'locale' => 'fr',
        ]);
        $this->candidat->markEmailAsVerified();
        $this->candidat->grantCandidateRole();

        $this->offre = Plan::create([
            'code' => 'conditions',
            'audience_id' => Audience::where('code', 'crmef')->value('id'),
            'name_fr' => 'Conditions', 'name_ar' => 'شروط',
            'price_cents' => 20000, 'currency' => 'MAD', 'duration_days' => 30,
            'capabilities' => [AccessGrant::QUESTIONS_ANSWER],
            'active' => true, 'position' => 1,
        ]);
    }

    // ═══ Ce qui versionne, et ce qui ne versionne pas ══════════════════════

    public function test_le_calendrier_de_commercialisation_versionne(): void
    {
        $premiere = $this->offre->currentVersion()->firstOrFail();

        $this->offre->update(['sale_closes_at' => now()->addMonth()]);

        $seconde = $this->offre->fresh()->currentVersion()->firstOrFail();
        $this->assertNotSame($premiere->id, $seconde->id);
        $this->assertNull($premiere->fresh()->sale_closes_at);
        $this->assertNotNull($seconde->sale_closes_at);
    }

    public function test_la_portee_versionne(): void
    {
        $premiere = $this->offre->currentVersion()->firstOrFail();
        $epreuve = Exam::query()->firstOrFail();

        $this->offre->update([
            'scope_type' => AccessGrantRecord::SCOPE_EXAM,
            'scope_uuid' => $epreuve->uuid,
        ]);

        $seconde = $this->offre->fresh()->currentVersion()->firstOrFail();
        $this->assertNotSame($premiere->id, $seconde->id);
        $this->assertSame($epreuve->uuid, $seconde->scope_uuid);
        $this->assertNull($premiere->fresh()->scope_type, 'La version vendue garde la portée vendue.');
    }

    public function test_la_note_de_catalogue_et_le_rang_ne_versionnent_pas(): void
    {
        $versionId = $this->offre->currentVersion()->firstOrFail()->id;

        $this->offre->update([
            'internal_note' => 'Renégocier le tarif avec la direction en janvier.',
            'position' => 9,
            'active' => false,
        ]);

        $this->assertSame($versionId, $this->offre->fresh()->current_version_id);
        $this->assertSame(1, $this->offre->versions()->count());
    }

    public function test_une_relecture_du_catalogue_ne_compose_aucune_version(): void
    {
        $this->offre->update(['sale_opens_at' => now()->subDay(), 'sale_closes_at' => now()->addMonth()]);
        $apresCalendrier = $this->offre->fresh()->versions()->count();

        /* Deux lectures de suite : si une date se comparait par identité
         * d'objet, chacune composerait une version — et le candidat qui a
         * l'écran ouvert se verrait refuser sa commande pour version périmée. */
        $this->getJson('/api/v1/plans')->assertOk();
        app(PlanVersionService::class)->current($this->offre->fresh());

        $this->assertSame($apresCalendrier, $this->offre->fresh()->versions()->count());
    }

    // ═══ Le calendrier refuse côté serveur, il ne grise rien ═══════════════

    public function test_hors_periode_l_offre_n_apparait_pas_au_catalogue(): void
    {
        $codes = collect($this->getJson('/api/v1/plans')->assertOk()->json('data'))->pluck('code');
        $this->assertContains('conditions', $codes->all());

        $this->offre->update(['sale_closes_at' => now()->subMinute()]);

        $codes = collect($this->getJson('/api/v1/plans')->assertOk()->json('data'))->pluck('code');
        $this->assertNotContains('conditions', $codes->all(), 'Hors période, l’offre quitte le rendu.');
    }

    public function test_avant_l_ouverture_l_offre_n_apparait_pas_non_plus(): void
    {
        $this->offre->update(['sale_opens_at' => now()->addWeek()]);

        $codes = collect($this->getJson('/api/v1/plans')->assertOk()->json('data'))->pluck('code');
        $this->assertNotContains('conditions', $codes->all());
    }

    public function test_hors_periode_la_souscription_est_refusee_sans_consommer_le_coupon(): void
    {
        $coupon = Coupon::create([
            'code' => Coupon::engendrer(),
            'plan_id' => $this->offre->id,
            'valid_from' => now()->subDay(),
            'valid_until' => now()->addMonth(),
            'max_uses' => 1, 'used_count' => 0, 'status' => 'actif',
        ]);
        $this->offre->update(['sale_closes_at' => now()->subMinute()]);

        try {
            app(CouponGateway::class)->ouvrir(
                $this->candidat, ['code' => $coupon->code], (string) Str::uuid7(),
            );
            $this->fail('Une offre hors calendrier ne se souscrit pas.');
        } catch (PaiementRefuse $exception) {
            $this->assertSame('hors_periode', $exception->motif);
        }

        $this->assertSame(0, $coupon->fresh()->used_count);
        $this->assertNotSame(
            'abonnement.coupon_hors_periode',
            __('abonnement.coupon_hors_periode'),
            'Le motif porte un message traduit, sinon le candidat lit une clé.',
        );
    }

    public function test_retirer_l_offre_n_invalide_aucun_droit_et_laisse_les_commandes_honorables(): void
    {
        $coupon = Coupon::create([
            'code' => Coupon::engendrer(),
            'plan_id' => $this->offre->id,
            'valid_from' => now()->subDay(),
            'valid_until' => now()->addMonth(),
            'max_uses' => 1, 'used_count' => 0, 'status' => 'actif',
        ]);
        $commande = app(CouponGateway::class)->ouvrir(
            $this->candidat, ['code' => $coupon->code], (string) Str::uuid7(),
        );

        $this->offre->update(['active' => false]);
        $honoree = app(AbonnementService::class)->honorer($commande);

        $this->assertSame('honoree', $honoree->status);
        $this->assertSame(
            [AccessGrant::QUESTIONS_ANSWER],
            AccessGrantRecord::where('user_id', $this->candidat->id)->pluck('capability')->all(),
        );
    }

    // ═══ Les refus de composition ══════════════════════════════════════════

    public function test_la_base_refuse_une_fenetre_fermee_avant_d_etre_ouverte(): void
    {
        $this->expectException(QueryException::class);

        DB::table('plans')->where('id', $this->offre->id)->update([
            'sale_opens_at' => now()->addMonth(),
            'sale_closes_at' => now()->addDay(),
        ]);
    }

    public function test_la_base_refuse_un_couple_de_portee_mi_nul(): void
    {
        $this->expectException(QueryException::class);

        DB::table('plans')->where('id', $this->offre->id)->update([
            'scope_type' => AccessGrantRecord::SCOPE_EXAM,
            'scope_uuid' => null,
        ]);
    }

    public function test_une_portee_qui_ne_designe_rien_est_refusee(): void
    {
        try {
            $this->offre->update([
                'scope_type' => AccessGrantRecord::SCOPE_EXAM,
                'scope_uuid' => (string) Str::uuid7(),
            ]);
            $this->fail('Une portée qui ne désigne rien ouvrirait un droit que rien ne résout.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('retiré', $exception->validator->errors()->first('scope_uuid'));
        }
    }

    public function test_une_portee_sur_un_objet_retire_est_refusee(): void
    {
        $epreuve = Exam::query()->firstOrFail();
        DB::table('exams')->where('id', $epreuve->id)->update(['status' => 'archived']);

        $this->expectException(ValidationException::class);

        $this->offre->update([
            'scope_type' => AccessGrantRecord::SCOPE_EXAM,
            'scope_uuid' => $epreuve->uuid,
        ]);
    }

    public function test_un_type_de_portee_hors_enumeration_est_refuse(): void
    {
        try {
            $this->offre->update([
                'scope_type' => 'specialty',
                'scope_uuid' => (string) Str::uuid7(),
            ]);
            $this->fail('La liste des types de portée est fermée en code et en base.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('specialty', $exception->validator->errors()->first('scope_type'));
        }
    }

    public function test_une_categorie_retiree_ne_se_selectionne_plus(): void
    {
        $retiree = Audience::create([
            'code' => 'retiree', 'name_fr' => 'Retirée', 'name_ar' => 'مسحوبة', 'active' => false,
        ]);

        $this->expectException(ValidationException::class);

        $this->offre->update(['audience_id' => $retiree->id]);
    }

    public function test_une_devise_hors_liste_est_refusee(): void
    {
        try {
            $this->offre->update(['currency' => 'EUR']);
            $this->fail('Une devise sans canal de paiement est une promesse invendable.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('EUR', $exception->validator->errors()->first('currency'));
        }
    }
}
