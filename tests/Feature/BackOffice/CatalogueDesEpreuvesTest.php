<?php

namespace Tests\Feature\BackOffice;

use App\Filament\Resources\ExamFamilies\Pages\ListExamFamilies;
use App\Filament\Resources\Exams\Pages\ListExams;
use App\Filament\Resources\Filieres\Pages\ListFilieres;
use App\Models\Exam;
use App\Models\ExamFamily;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LE CATALOGUE SE GOUVERNE DEPUIS L'ÉCRAN, PLUS PAR MIGRATION.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * CE QUE CE LOT FERME — DET-102
 *
 * Treize ressources Filament existaient ; aucune ne portait le catalogue. Une
 * épreuve ne se créait, ne se renommait et n'ouvrait son diagnostic que par
 * migration. Les onze matières du lycée sont posées en brouillon sous trois
 * familles en liste d'attente : personne dans le produit ne pouvait les
 * ouvrir. Demandé explicitement par le propriétaire le 29 août 2026.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * LE TEST QUI COMPTE EST LE DERNIER
 *
 * Vérifier qu'un formulaire s'enregistre ne prouve rien d'utile : ce qui
 * importe, c'est qu'ouvrir une famille depuis le back-office change
 * RÉELLEMENT ce que le catalogue public sert. Sans ce test, on livrerait un
 * écran qui écrit une colonne sans effet observable.
 */
final class CatalogueDesEpreuvesTest extends TestCase
{
    use RefreshDatabase;

    private User $expert;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set(Tenant::where('kind', 'platform')->firstOrFail());

        $this->expert = $this->membre('catalogue@naja7i.ma', 'expert_pedagogue');
    }

    private function membre(string $email, string $role): User
    {
        $user = User::create([
            'email' => $email,
            'password' => 'une-phrase-de-passe-solide',
            'locale' => 'fr',
            'status' => 'active',
        ]);
        $user->markEmailAsVerified();
        $user->memberships()->create([
            'role_id' => Role::where('code', $role)->whereNull('tenant_id')->value('id'),
        ]);

        return $user->fresh();
    }

    // ── L'accès ─────────────────────────────────────────────────────────

    public function test_l_expert_pedagogue_ouvre_les_deux_ecrans_du_catalogue(): void
    {
        foreach ([ListFilieres::class, ListExamFamilies::class, ListExams::class] as $ecran) {
            $this->actingAs($this->expert)->get($ecran::getUrl())->assertOk();
        }
    }

    /**
     * LIRE N'EST PAS ÉCRIRE. `support` ne porte ni `catalogue.view` ni
     * `catalogue.manage` : il n'a rien à faire dans le catalogue.
     */
    public function test_un_role_sans_permission_de_catalogue_est_refuse(): void
    {
        $this->app['session']->flush();
        $support = $this->membre('support-catalogue@naja7i.ma', 'support');

        foreach ([ListFilieres::class, ListExamFamilies::class, ListExams::class] as $ecran) {
            $this->actingAs($support)->get($ecran::getUrl())->assertForbidden();
        }
    }

    // ── Les gestes ──────────────────────────────────────────────────────

    /**
     * LA SUPPRESSION EST REFUSÉE, TOUJOURS. Une épreuve porte son arbre, ses
     * questions et les tentatives déjà passées ; une famille porte ses
     * épreuves. Effacer rendrait l'historique illisible sans un mot.
     */
    public function test_rien_ne_se_supprime_dans_le_catalogue(): void
    {
        $epreuve = Exam::first();
        $famille = ExamFamily::first();

        $this->assertNotNull($epreuve, 'Le socle ne sème aucune épreuve : le test ne mesure rien.');
        $this->assertFalse($this->expert->can('delete', $epreuve));
        $this->assertFalse($this->expert->can('delete', $famille));
    }

    public function test_l_expert_peut_ecrire_et_le_support_non(): void
    {
        $this->app['session']->flush();
        $support = $this->membre('support-ecriture@naja7i.ma', 'support');
        $famille = ExamFamily::first();

        $this->assertTrue($this->expert->can('update', $famille));
        $this->assertFalse($support->can('update', $famille));
    }

    // ── LE TEST QUI COMPTE ──────────────────────────────────────────────

    /**
     * OUVRIR UNE FAMILLE LA REND VISIBLE AU CANDIDAT, ET C'EST TOUT L'OBJET.
     *
     * On part de l'état réel du lycée — famille en liste d'attente, épreuves
     * en brouillon —, on fait les deux gestes de l'écran, et on relit le
     * catalogue PUBLIC. Un écran qui écrirait la colonne sans que rien ne
     * change serait pire qu'aucun écran : il donnerait le sentiment d'avoir
     * agi.
     */
    public function test_ouvrir_une_famille_la_fait_apparaitre_au_catalogue_public(): void
    {
        $famille = ExamFamily::firstOrFail();
        $famille->forceFill(['status' => 'draft', 'availability' => 'waitlist'])->save();
        $famille->filiere->forceFill(['status' => 'draft'])->save();

        $slugsAvant = collect($this->getJson('/api/v1/catalogue')->json('data'))
            ->flatMap(fn (array $f) => collect($f['families'] ?? [])->pluck('slug'))
            ->all();

        $this->assertNotContains($famille->slug, $slugsAvant, 'La famille est déjà servie : le test ne mesure rien.');

        /*
         * LES TROIS GESTES, DANS L'ORDRE DES TROIS ÉCRANS.
         *
         * Cette version du test a d'abord échoué avec les deux derniers seuls,
         * et c'est ce qui a révélé le troisième verrou : la filière `lycee`
         * est en brouillon, et elle masque tout ce qu'elle contient. Les
         * écrans de la famille et de l'épreuve auraient été livrés sans que
         * l'expert puisse ouvrir quoi que ce soit.
         */
        $famille->filiere->forceFill(['status' => 'published', 'published_at' => now()])->save();
        $famille->forceFill(['status' => 'published', 'availability' => 'open', 'published_at' => now()])->save();

        $servies = collect($this->getJson('/api/v1/catalogue')->json('data'))
            ->flatMap(fn (array $f) => collect($f['families'] ?? [])->map(fn (array $x) => [$x['slug'], $x['availability'] ?? null]))
            ->all();

        $this->assertContains([$famille->slug, 'open'], $servies, 'Ouvrir la famille n’a rien changé au catalogue public.');
    }

    /**
     * ET LE STATUT SEUL NE SUFFIT PAS. Publier l'épreuve sans ouvrir sa
     * famille ne la montre à personne — c'est exactement l'état des onze
     * matières du lycée, et la confusion que l'écran doit empêcher.
     */
    public function test_publier_une_epreuve_sous_une_famille_fermee_ne_la_montre_a_personne(): void
    {
        $famille = ExamFamily::firstOrFail();
        $famille->forceFill(['status' => 'published', 'availability' => 'waitlist', 'published_at' => now()])->save();

        $servie = collect($this->getJson('/api/v1/catalogue')->json('data'))
            ->flatMap(fn (array $f) => collect($f['families'] ?? [])->where('slug', $famille->slug))
            ->first();

        $this->assertNotSame('open', $servie['availability'] ?? null);
    }
}
