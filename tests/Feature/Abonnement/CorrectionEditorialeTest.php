<?php

namespace Tests\Feature\Abonnement;

use App\Contracts\AccessGrant;
use App\Models\Plan;
use App\Models\PlanVersion;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PlanVersionService;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P-E — La coquille se corrige, la promesse ne se réécrit pas.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * CE QUE CE FICHIER SURVEILLE
 *
 * L'immuabilité différenciée est une porte percée dans un mur. Une porte se
 * juge sur ce qu'elle REFUSE : un prix réécrit par le canal, un texte réécrit
 * hors canal, un journal amendé après coup, une suppression. Les quatre sont
 * ici, et chacun échoue en base — pas dans un service qu'un autre chemin
 * contournerait.
 */
class CorrectionEditorialeTest extends TestCase
{
    use RefreshDatabase;

    private Plan $plan;

    private PlanVersion $version;

    private User $editeur;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set(Tenant::where('kind', 'platform')->firstOrFail());

        $this->editeur = $this->membre('editeur-coquille@naja7i.ma', 'super_admin');

        $this->plan = Plan::create([
            'code' => 'coquille',
            'name_fr' => 'Pack Dècouverte',
            'name_ar' => 'باقة الاكتشاف',
            'description_fr' => 'Une description avec une coquille.',
            'description_ar' => 'وصف يحتوي على خطأ مطبعي.',
            'price_cents' => 60000,
            'currency' => 'MAD',
            'duration_days' => 30,
            'capabilities' => [AccessGrant::QUESTIONS_ANSWER],
            'active' => true,
            'position' => 1,
        ]);
        $this->version = $this->plan->currentVersion()->firstOrFail();
    }

    private function membre(string $email, ?string $role): User
    {
        $user = User::create([
            'email' => $email, 'password' => 'une-phrase-de-passe-solide',
            'locale' => 'fr', 'status' => 'active',
        ]);
        $user->markEmailAsVerified();

        if ($role !== null) {
            $user->memberships()->create([
                'role_id' => Role::where('code', $role)->whereNull('tenant_id')->value('id'),
            ]);
        }

        return $user->fresh();
    }

    /** Le refus attendu, capté sans emporter la transaction du test. */
    private function refusDeLaBase(callable $geste): QueryException
    {
        try {
            DB::transaction($geste);
        } catch (QueryException $exception) {
            return $exception;
        }

        $this->fail('La base devait refuser ce geste.');
    }

    // ═══ Ce que le canal permet ════════════════════════════════════════════

    public function test_une_correction_amende_le_texte_journalise_et_ne_versionne_pas(): void
    {
        $corrigee = app(PlanVersionService::class)->corrigerLeTexte(
            $this->version,
            'name_fr',
            'Pack Découverte',
            $this->editeur,
            'Accent grave au lieu d’un accent aigu dans le nom commercial.',
        );

        $trace = $corrigee->editorialFixes()->sole();

        $this->assertSame('Pack Découverte', $corrigee->name_fr);
        $this->assertSame(1, $this->plan->fresh()->versions()->count());
        $this->assertSame($this->version->id, $this->plan->fresh()->current_version_id);
        $this->assertSame('name_fr', $trace->field);
        $this->assertSame('Pack Dècouverte', $trace->before_text);
        $this->assertSame('Pack Découverte', $trace->after_text);
        $this->assertSame($this->editeur->id, $trace->actor_id);
        $this->assertStringContainsString('accent', $trace->reason);
        $this->assertSame(7, (int) $trace->uuid[14], 'Le journal porte un UUIDv7, comme tout identifiant public.');
    }

    public function test_la_correction_ne_touche_a_rien_d_autre_que_son_champ(): void
    {
        app(PlanVersionService::class)->corrigerLeTexte(
            $this->version, 'description_ar', 'وصف مصحح.', $this->editeur,
            'Faute de frappe signalée par la relecture arabe.',
        );

        $relue = $this->version->fresh();
        $this->assertSame('وصف مصحح.', $relue->description_ar);
        $this->assertSame($this->version->name_fr, $relue->name_fr);
        $this->assertSame(60000, $relue->price_cents);
        $this->assertSame([AccessGrant::QUESTIONS_ANSWER], $relue->capabilities);
    }

    // ═══ Ce que le canal refuse ════════════════════════════════════════════

    public function test_un_champ_contractuel_ne_passe_pas_par_le_canal(): void
    {
        $refus = $this->refusDeLaBase(fn () => DB::statement(
            'SELECT corriger_version_editoriale(?, ?, ?, ?, ?)',
            [$this->version->uuid, 'price_cents', '1', $this->editeur->id, 'Baisse commerciale de dernière minute.'],
        ));

        $this->assertStringContainsString('champ contractuel', $refus->getMessage());
        $this->assertSame(60000, $this->version->fresh()->price_cents);
        $this->assertSame(0, $this->version->editorialFixes()->count());
    }

    public function test_une_correction_sans_motif_ecrit_est_refusee(): void
    {
        $refus = $this->refusDeLaBase(fn () => DB::statement(
            'SELECT corriger_version_editoriale(?, ?, ?, ?, ?)',
            [$this->version->uuid, 'name_fr', 'Pack Découverte', $this->editeur->id, 'coquille'],
        ));

        $this->assertStringContainsString('motif', $refus->getMessage());
        $this->assertSame('Pack Dècouverte', $this->version->fresh()->name_fr);
    }

    // ═══ Ce qui reste absolument fermé ═════════════════════════════════════

    public function test_un_update_direct_du_meme_champ_hors_canal_leve(): void
    {
        $refus = $this->refusDeLaBase(fn () => DB::table('plan_versions')
            ->where('id', $this->version->id)
            ->update(['name_fr' => 'Pack Découverte']));

        $this->assertStringContainsString('corriger_version_editoriale', $refus->getMessage());
        $this->assertSame('Pack Dècouverte', $this->version->fresh()->name_fr);
    }

    public function test_un_update_du_prix_leve_par_n_importe_quel_chemin(): void
    {
        $direct = $this->refusDeLaBase(fn () => DB::table('plan_versions')
            ->where('id', $this->version->id)
            ->update(['price_cents' => 1]));

        $this->assertStringContainsString('immuable', $direct->getMessage());

        /* Et sous la marque du canal — le cas qui compte, car c'est celui
         * qu'un contournement essaierait : ouvrir la porte pour un texte, et
         * pousser un prix par la même ouverture. */
        $sousLaMarque = $this->refusDeLaBase(function () {
            DB::statement("SELECT set_config('naja7i.correction_editoriale', ?, true)", [(string) $this->version->id]);
            DB::table('plan_versions')->where('id', $this->version->id)->update(['price_cents' => 1]);
        });

        $this->assertStringContainsString('ne corrige que les textes', $sousLaMarque->getMessage());
        $this->assertSame(60000, $this->version->fresh()->price_cents);
    }

    public function test_la_marque_du_canal_ne_survit_pas_a_la_correction(): void
    {
        app(PlanVersionService::class)->corrigerLeTexte(
            $this->version, 'name_fr', 'Pack Découverte', $this->editeur,
            'Accent grave au lieu d’un accent aigu dans le nom commercial.',
        );

        $refus = $this->refusDeLaBase(fn () => DB::table('plan_versions')
            ->where('id', $this->version->id)
            ->update(['name_ar' => 'اسم آخر']));

        $this->assertStringContainsString('corriger_version_editoriale', $refus->getMessage());
    }

    public function test_le_journal_des_corrections_est_en_ajout_seul(): void
    {
        app(PlanVersionService::class)->corrigerLeTexte(
            $this->version, 'name_fr', 'Pack Découverte', $this->editeur,
            'Accent grave au lieu d’un accent aigu dans le nom commercial.',
        );
        $trace = $this->version->editorialFixes()->sole();

        $modification = $this->refusDeLaBase(fn () => DB::table('plan_version_editorial_fixes')
            ->where('id', $trace->id)->update(['reason' => 'Autre motif']));
        $suppression = $this->refusDeLaBase(fn () => DB::table('plan_version_editorial_fixes')
            ->where('id', $trace->id)->delete());

        $this->assertStringContainsString('ajout seul', $modification->getMessage());
        $this->assertStringContainsString('ajout seul', $suppression->getMessage());
    }

    public function test_une_version_ne_se_supprime_toujours_pas(): void
    {
        $refus = $this->refusDeLaBase(fn () => DB::table('plan_versions')
            ->where('id', $this->version->id)->delete());

        $this->assertStringContainsString('ne se supprime jamais', $refus->getMessage());
    }

    // ═══ L'autorisation, elle, est en PHP ══════════════════════════════════

    public function test_sans_la_permission_dediee_la_correction_est_refusee(): void
    {
        $commerciale = $this->membre('commerciale-coquille@naja7i.ma', 'finance');

        $this->expectException(AuthorizationException::class);

        app(PlanVersionService::class)->corrigerLeTexte(
            $this->version, 'name_fr', 'Pack Découverte', $commerciale,
            'Accent grave au lieu d’un accent aigu dans le nom commercial.',
        );
    }
}
