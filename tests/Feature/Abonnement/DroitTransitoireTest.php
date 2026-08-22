<?php

namespace Tests\Feature\Abonnement;

use App\Contracts\AccessGrant;
use App\Models\AccessGrantRecord;
use App\Models\Audience;
use App\Models\Exam;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TransitionBatch;
use App\Models\User;
use App\Services\DroitTransitoireService;
use App\Services\OffreGratuiteService;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Q-17 — le droit transitoire des comptes existants.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * CE QUE CE LOT DISTRIBUE, ET POURQUOI IL SE MÉFIE DE LUI-MÊME
 *
 * C'est le premier geste du produit qui écrit sur TOUS les comptes à la fois.
 * Les gardes tenues ici ne protègent pas d'une erreur de logique mais d'une
 * erreur d'exploitation : un geste rejoué, un geste sans auteur, un geste dont
 * le nombre annoncé n'était pas celui posé, un geste qui aurait ouvert une
 * capacité que le produit ne vend pas.
 */
class DroitTransitoireTest extends TestCase
{
    use RefreshDatabase;

    private User $commerciale;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set(Tenant::where('kind', 'platform')->firstOrFail());

        $this->commerciale = $this->membre('commerciale-transition@naja7i.ma', 'finance');
    }

    private function membre(string $email, string $role): User
    {
        $user = User::create([
            'email' => $email, 'password' => 'une-phrase-de-passe-solide',
            'locale' => 'fr', 'status' => 'active',
        ]);
        $user->markEmailAsVerified();
        $user->memberships()->create([
            'role_id' => Role::where('code', $role)->whereNull('tenant_id')->value('id'),
        ]);

        return $user->fresh();
    }

    private function candidat(string $email, ?Exam $epreuve = null): User
    {
        $user = User::create([
            'email' => $email, 'password' => 'une-phrase-de-passe-solide', 'locale' => 'fr',
        ]);
        $user->markEmailAsVerified();
        $user->grantCandidateRole();
        $user = $user->fresh();

        app(OffreGratuiteService::class)->attribuer($user);

        if ($epreuve !== null) {
            $user->candidateProfile()->create(['exam_id' => $epreuve->id]);
        }

        return $user->fresh();
    }

    private function service(): DroitTransitoireService
    {
        return app(DroitTransitoireService::class);
    }

    private function droitsTransitoires(User $compte)
    {
        return AccessGrantRecord::where('user_id', $compte->id)->where('origin', 'transition')->get();
    }

    // ═══ La prévisualisation, exigée par Q-17 ══════════════════════════════

    public function test_la_previsualisation_annonce_sans_rien_ecrire(): void
    {
        $this->candidat('vise-1@naja7i.ma');
        $this->candidat('vise-2@naja7i.ma');

        $apercu = $this->service()->previsualiser(['motif' => 'Allumage du mur payant.']);

        $this->assertSame(2, $apercu['comptes_vises']);
        $this->assertSame(0, $apercu['deja_porteurs']);
        $this->assertSame(2, $apercu['a_poser']);
        $this->assertSame(60, $apercu['duree_jours']);
        $this->assertSame(0, AccessGrantRecord::where('origin', 'transition')->count());
        $this->assertSame(0, TransitionBatch::query()->count());
    }

    public function test_la_commande_en_mode_sec_annonce_et_n_ecrit_rien(): void
    {
        $this->candidat('sec-transition@naja7i.ma');

        $this->artisan('naja7i:poser-le-droit-transitoire', ['--dry-run' => true])
            ->expectsOutput('comptes_vises=1')
            ->expectsOutput('a_poser=1')
            ->expectsOutput('mode=sec')
            ->assertSuccessful();

        $this->assertSame(0, AccessGrantRecord::where('origin', 'transition')->count());
    }

    public function test_la_commande_refuse_de_poser_sans_auteur(): void
    {
        $this->candidat('sans-auteur@naja7i.ma');

        $this->artisan('naja7i:poser-le-droit-transitoire', ['--motif' => 'Allumage du mur payant.'])
            ->expectsOutput('auteur_absent=1')
            ->assertFailed();

        $this->assertSame(0, AccessGrantRecord::where('origin', 'transition')->count());
    }

    // ═══ La pose ═══════════════════════════════════════════════════════════

    public function test_la_pose_ouvre_les_capacites_du_palier_de_reference(): void
    {
        $compte = $this->candidat('pose@naja7i.ma');
        $reference = $this->service()->offreDeReference();

        $trace = $this->service()->poser($this->commerciale, [
            'motif' => 'Allumage du mur payant, sevrage de soixante jours.',
        ]);

        $droits = $this->droitsTransitoires($compte);

        $this->assertSame($reference->capabilities, $droits->pluck('capability')->all());
        $this->assertNotContains(AccessGrant::CERTIFICATION, $droits->pluck('capability')->all());
        $this->assertTrue($droits->every(fn ($d) => $d->ends_at !== null));
        $this->assertSame(
            60,
            (int) $droits->first()->starts_at->diffInDays($droits->first()->ends_at),
        );
        $this->assertSame(1, $trace->accounts_granted);
    }

    public function test_le_geste_laisse_sa_trace_complete(): void
    {
        $this->candidat('trace@naja7i.ma');

        $trace = $this->service()->poser($this->commerciale, [
            'motif' => 'Allumage du mur payant, sevrage de soixante jours.',
            'duree' => 90,
        ]);

        $this->assertSame($this->commerciale->id, $trace->actor_id);
        $this->assertSame(90, $trace->duration_days);
        $this->assertStringContainsString('sevrage', $trace->reason);
        $this->assertSame(1, $trace->accounts_targeted);
        $this->assertSame(1, $trace->accounts_granted);
        $this->assertSame(0, $trace->accounts_skipped);
        $this->assertNotNull($trace->plan_version_id, 'On doit pouvoir relire « équivalent à quoi ».');
    }

    public function test_le_journal_des_poses_est_en_ajout_seul(): void
    {
        $this->candidat('journal@naja7i.ma');
        $trace = $this->service()->poser($this->commerciale, ['motif' => 'Allumage du mur payant.']);

        $this->expectException(QueryException::class);

        DB::table('transition_batches')->where('id', $trace->id)->update(['reason' => 'Autre motif']);
    }

    public function test_le_gratuit_sans_terme_reste_intact_dessous(): void
    {
        $compte = $this->candidat('coexistence@naja7i.ma');
        $gratuit = AccessGrantRecord::where('user_id', $compte->id)
            ->where('origin', OffreGratuiteService::ORIGINE_INSCRIPTION)->sole();

        $this->service()->poser($this->commerciale, ['motif' => 'Allumage du mur payant.']);

        $relu = $gratuit->fresh();
        $this->assertNull($relu->ends_at, 'AR-2 : le sans-terme n’est ni bloqué ni court-circuité.');
        $this->assertSame(40, $relu->quota_value, 'Son enveloppe est intacte.');
    }

    public function test_aucun_agregat_de_vente_ne_bouge(): void
    {
        $this->candidat('agregat@naja7i.ma');

        $this->service()->poser($this->commerciale, ['motif' => 'Allumage du mur payant.']);

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(
            0,
            AccessGrantRecord::where('origin', 'purchase')->count(),
            'Un droit que personne n’a acheté ne se compte pas dans les ventes.',
        );
    }

    public function test_rejouer_la_pose_ne_double_rien(): void
    {
        $compte = $this->candidat('rejeu-transition@naja7i.ma');

        $premier = $this->service()->poser($this->commerciale, ['motif' => 'Allumage du mur payant.']);
        $second = $this->service()->poser($this->commerciale, ['motif' => 'Reprise après interruption.']);

        $this->assertSame(1, $premier->accounts_granted);
        $this->assertSame(0, $second->accounts_granted);
        $this->assertSame(1, $second->accounts_skipped);
        $this->assertCount(
            count($this->service()->offreDeReference()->capabilities),
            $this->droitsTransitoires($compte),
        );
    }

    // ═══ Les refus ═════════════════════════════════════════════════════════

    public function test_une_composition_alteree_qui_porte_certification_est_refusee_en_la_nommant(): void
    {
        $reference = $this->service()->offreDeReference();

        /* Le modèle refuserait cette composition : on force donc en base, ce
         * qu'un correctif à chaud ferait. La garde du geste doit tenir seule. */
        DB::table('plans')->where('id', $reference->id)->update([
            'capabilities' => json_encode([AccessGrant::QUESTIONS_ANSWER, AccessGrant::CERTIFICATION]),
        ]);

        try {
            $this->service()->capacitesDe($reference->fresh());
            $this->fail('Un droit transitoire n’ouvre jamais une capacité non commercialisable.');
        } catch (ValidationException $exception) {
            $message = $exception->validator->errors()->first('capabilities');
            $this->assertStringContainsString(AccessGrant::CERTIFICATION, $message);
            $this->assertStringContainsString('commercialisable', $message);
        }
    }

    public function test_une_duree_hors_bornes_est_refusee(): void
    {
        foreach ([3, 400] as $duree) {
            try {
                $this->service()->previsualiser(['duree' => $duree]);
                $this->fail("La durée {$duree} devait être refusée.");
            } catch (ValidationException $exception) {
                $this->assertStringContainsString('jours', $exception->validator->errors()->first('duree'));
            }
        }
    }

    public function test_la_base_refuse_une_duree_hors_bornes_meme_forgee(): void
    {
        $this->expectException(QueryException::class);

        DB::table('transition_batches')->insert([
            'actor_id' => $this->commerciale->id,
            'plan_id' => Plan::query()->first()->id,
            'plan_version_id' => Plan::query()->first()->currentVersion()->firstOrFail()->id,
            'duration_days' => 400,
            'starts_at' => now(),
            'reason' => 'Contournement du service.',
            'accounts_targeted' => 0, 'accounts_granted' => 0, 'accounts_skipped' => 0,
            'occurred_at' => now(),
        ]);
    }

    public function test_un_motif_absent_est_refuse(): void
    {
        $this->candidat('sans-motif@naja7i.ma');

        $this->expectException(ValidationException::class);

        $this->service()->poser($this->commerciale, ['motif' => 'court']);
    }

    public function test_une_pose_datee_dans_le_passe_est_refusee(): void
    {
        $this->expectException(ValidationException::class);

        $this->service()->previsualiser(['pose_le' => now()->subWeek()->toDateString()]);
    }

    // ═══ Le public visé ════════════════════════════════════════════════════

    public function test_un_geste_cible_ne_touche_que_le_public_vise(): void
    {
        $lycee = Audience::create([
            'code' => 'lycee', 'name_fr' => 'Lycée', 'name_ar' => 'الثانوي', 'position' => 20,
        ]);
        $epreuveCrmef = Exam::query()->whereHas('track.family', fn ($q) => $q
            ->whereNotNull('audience_id'))->firstOrFail();

        $crmef = $this->candidat('crmef-cible@naja7i.ma', $epreuveCrmef);
        $sansProfil = $this->candidat('sans-profil@naja7i.ma');

        $apercu = $this->service()->previsualiser(['public' => 'crmef']);
        $this->assertSame(1, $apercu['comptes_vises'], 'Seul le compte dont l’épreuve relève du public.');

        $this->service()->poser($this->commerciale, [
            'public' => 'crmef', 'motif' => 'Allumage limité au public CRMEF.',
        ]);

        $this->assertNotEmpty($this->droitsTransitoires($crmef));
        $this->assertEmpty(
            $this->droitsTransitoires($sansProfil),
            'Un compte sans épreuve déclarée n’a pas de public connu : on ne lui en suppose pas un.',
        );
        $this->assertSame(0, $this->service()->previsualiser(['public' => $lycee->code])['comptes_vises']);
    }

    public function test_le_personnel_n_est_jamais_vise(): void
    {
        $apercu = $this->service()->previsualiser([]);

        $this->assertSame(0, $apercu['comptes_vises'], 'Seuls les comptes candidats sont concernés.');
    }
}
