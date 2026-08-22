<?php

namespace Tests\Feature\Abonnement;

use App\Contracts\AccessGrant;
use App\Enums\QuotaUnit;
use App\Models\AccessGrantRecord;
use App\Models\Order;
use App\Models\Plan;
use App\Models\QuotaProfile;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AbonnementService;
use App\Services\OffreGratuiteService;
use App\Services\QuotaProfileService;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * S-01, partie gratuite — ce qu'un compte neuf reçoit sans rien demander.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * TROIS CHOSES QUE CE FICHIER REFUSE DE LAISSER PASSER
 *
 * 1. QUE LE GRATUIT RESSEMBLE À UNE VENTE. Aucune commande, aucun agrégat
 *    commercial, et une origine d'octroi qui n'est pas `purchase` (ADR-0028).
 * 2. QU'UN COMPTE REÇOIVE DEUX FOIS. Rejouer l'attribution ne crée rien.
 * 3. QUE LE PROFIL COURANT SOIT RELU. Un compte inscrit hier garde l'enveloppe
 *    de SA version, quoi qu'il arrive au registre pédagogique ensuite.
 */
class AutoAttributionDuGratuitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set(Tenant::where('kind', 'platform')->firstOrFail());
    }

    /**
     * Une inscription réelle, par la route publique.
     *
     * La session est fermée avant : la route est derrière `guest`, et
     * s'inscrire ouvre une session — deux inscriptions de suite dans le même
     * test se heurteraient à leur propre succès.
     */
    private function inscrire(string $email): User
    {
        auth('web')->logout();
        $this->flushSession();

        $this->postJson('/api/v1/auth/register', [
            'email' => $email,
            'password' => 'une-phrase-de-passe-solide',
            'password_confirmation' => 'une-phrase-de-passe-solide',
            'locale' => 'fr',
            'terms_accepted' => true,
            'privacy_notice_acknowledged' => true,
            'marketing_granted' => false,
        ])->assertCreated();

        return User::where('email', $email)->sole();
    }

    private function droitsDe(User $user)
    {
        return AccessGrantRecord::where('user_id', $user->id)->get();
    }

    // ═══ S-01 : le compte neuf ═════════════════════════════════════════════

    public function test_un_compte_neuf_recoit_le_droit_gratuit_sans_terme_avec_son_enveloppe(): void
    {
        $candidat = $this->inscrire('neuf@naja7i.ma');
        $droits = $this->droitsDe($candidat);

        $this->assertCount(1, $droits);

        $droit = $droits->first();
        $this->assertSame(AccessGrant::QUESTIONS_ANSWER, $droit->capability);
        $this->assertNull($droit->ends_at, 'Le droit gratuit est sans terme (Q-18).');
        $this->assertSame(40, $droit->quota_value);
        $this->assertSame(QuotaUnit::QUESTIONS, $droit->quota_unit);
        $this->assertSame(OffreGratuiteService::ORIGINE_INSCRIPTION, $droit->origin);
    }

    public function test_le_gratuit_ne_ressemble_a_aucune_vente(): void
    {
        $candidat = $this->inscrire('sans-vente@naja7i.ma');

        $this->assertSame(0, Order::where('user_id', $candidat->id)->count());
        $this->assertSame(0, Order::query()->count(), 'Aucun agrégat commercial ne bouge.');
        $this->assertNotSame(
            'purchase', $this->droitsDe($candidat)->first()->origin,
            'Un droit que personne n’a acheté ne se compte pas dans les ventes (ADR-0028, C-05).',
        );
    }

    public function test_le_droit_gratuit_reference_la_version_et_compte_dans_ses_dependances(): void
    {
        $candidat = $this->inscrire('dependance@naja7i.ma');
        $version = Plan::autoGranted()->sole()->currentVersion()->firstOrFail();

        $this->assertSame($version->uuid, $this->droitsDe($candidat)->first()->origin_reference);
        $this->assertSame(1, $version->droitsIssus()->count());
        $this->assertSame(1, $version->droitsIssus()->active()->count());
    }

    // ═══ L'idempotence ═════════════════════════════════════════════════════

    public function test_rejouer_l_attribution_ne_cree_pas_un_second_droit(): void
    {
        $candidat = $this->inscrire('rejeu@naja7i.ma');
        $service = app(OffreGratuiteService::class);

        $this->assertFalse($service->attribuer($candidat), 'Le compte le porte déjà.');
        $this->assertFalse($service->attribuer($candidat, OffreGratuiteService::ORIGINE_RATTRAPAGE));

        $this->assertCount(1, $this->droitsDe($candidat));
    }

    // ═══ Le grandfathering — l'instantané, pas le registre ═════════════════

    public function test_un_compte_inscrit_avant_garde_son_enveloppe_quand_l_offre_change(): void
    {
        $ancien = $this->inscrire('ancien@naja7i.ma');
        $offre = Plan::autoGranted()->sole();

        /* L'admin pédagogique définit un profil plus large, l'admin commerciale
         * le sélectionne : l'offre gratuite passe en version 2. */
        $pedagogue = User::create([
            'email' => 'pedagogue-attribution@naja7i.ma',
            'password' => 'une-phrase-de-passe-solide', 'locale' => 'fr', 'status' => 'active',
        ]);
        $large = app(QuotaProfileService::class)->definir($pedagogue, [
            'code' => 'decouverte-large', 'name_fr' => 'Découverte élargie', 'name_ar' => 'اكتشاف موسع',
            'unit' => 'questions', 'periodicity' => 'cumulative_grant',
            'value' => 60, 'min_value' => 40, 'max_value' => 150,
            'min_justification' => 'Sous quarante questions, la carte de maîtrise reste vide sur les épreuves longues.',
            'max_justification' => 'Au-delà de cent cinquante, la découverte cesse d’être un aperçu du produit.',
        ]);
        $offre->update(['quota_profile_id' => $large->id]);

        $nouveau = $this->inscrire('nouveau@naja7i.ma');

        $this->assertSame(2, $offre->fresh()->versions()->count());
        $this->assertSame(
            40, $this->droitsDe($ancien)->first()->quota_value,
            'Le compte déjà inscrit garde l’enveloppe de SA version.',
        );
        $this->assertSame(
            60, $this->droitsDe($nouveau)->first()->quota_value,
            'Le compte inscrit après reçoit la version en vigueur.',
        );
        $this->assertSame(
            60, QuotaProfile::where('code', 'decouverte-large')->value('value'),
            'Le registre, lui, porte bien la nouvelle valeur.',
        );
    }

    /**
     * LE CAS QUI DISCRIMINE VRAIMENT — et que le précédent ne voit pas.
     *
     * Dans le test ci-dessus, l'ancien compte a reçu son droit AVANT que le
     * registre ne bouge : un code qui relirait le profil courant lui aurait
     * quand même donné 40, et le test resterait vert. Ce qu'il faut éprouver,
     * c'est un octroi posé APRÈS le déplacement du registre, sur une version
     * ANCIENNE — le geste exact qu'un rattrapage ou une réparation de support
     * produirait. La v1 doit toujours ouvrir 40 quand le profil en vaut 100.
     */
    public function test_une_version_ancienne_ouvre_son_instantane_meme_apres_amendement_du_registre(): void
    {
        $offre = Plan::autoGranted()->sole();
        $v1 = $offre->currentVersion()->firstOrFail();
        $pedagogue = User::create([
            'email' => 'pedagogue-instantane@naja7i.ma',
            'password' => 'une-phrase-de-passe-solide', 'locale' => 'fr', 'status' => 'active',
        ]);

        /* Le registre bouge SANS que l'offre ne recompose : amender un profil
         * ne versionne pas (décision de reprise, M-003). */
        app(QuotaProfileService::class)->amender(
            QuotaProfile::where('code', 'decouverte')->sole(),
            $pedagogue,
            [
                'value' => 100, 'min_value' => 90, 'max_value' => 200,
                'min_justification' => 'La banque a doublé : sous quatre-vingt-dix questions la carte reste vide.',
                'max_justification' => 'Deux cents questions restent un aperçu sur une banque de cette taille.',
            ],
        );

        $tardif = User::create([
            'email' => 'tardif@naja7i.ma', 'password' => 'une-phrase-de-passe-solide', 'locale' => 'fr',
        ]);
        app(AbonnementService::class)->octroyerLesDroits(
            $tardif->id, $v1->fresh(), OffreGratuiteService::ORIGINE_RATTRAPAGE, $v1->uuid, 'Rattrapage v1',
        );

        $this->assertSame(
            40, $this->droitsDe($tardif)->first()->quota_value,
            'La version 1 ouvre ce qu’elle a figé, pas ce que le registre vaut aujourd’hui.',
        );
        $this->assertSame(100, QuotaProfile::where('code', 'decouverte')->value('value'));
    }

    public function test_sans_porteur_du_gratuit_l_inscription_aboutit_quand_meme(): void
    {
        Plan::autoGranted()->sole()->update(['auto_granted' => false]);

        $candidat = $this->inscrire('sans-porteur@naja7i.ma');

        $this->assertCount(0, $this->droitsDe($candidat));
        $this->assertSame('active', $candidat->status, 'Une plateforme qui ne distribue rien inscrit quand même.');
    }
}
