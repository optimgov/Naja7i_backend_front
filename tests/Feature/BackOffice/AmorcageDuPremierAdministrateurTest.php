<?php

namespace Tests\Feature\BackOffice;

use App\Console\Commands\CreerUnAdministrateur;
use App\Models\Role;
use App\Models\StaffInvitation;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PermissionResolver;
use App\Services\StaffInvitationService;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * L'amorçage du premier administrateur — M-018.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * L'ŒUF SANS POULE
 *
 * `canAccessPanel()` exige au moins une permission, donc une adhésion à un
 * rôle. Sur une base neuve, personne n'en a — et les invitations de personnel
 * ne cassent pas le cercle, puisque les émettre demande déjà un compte
 * autorisé. Le propriétaire a ouvert la préproduction et n'a trouvé aucune
 * porte : ce n'était pas un défaut d'écran, c'était un chemin qui n'existait
 * pas.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * CE QUE CE FICHIER GARDE EN PRIORITÉ
 *
 * **Aucun mot de passe ne peut entrer par la ligne de commande.** C'est la
 * règle qui décide de la forme entière : un secret passé en argument survit
 * dans l'historique du shell, dans la table des processus, et dans tout
 * journal qui capture la ligne — longtemps après que le compte en a changé.
 */
class AmorcageDuPremierAdministrateurTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set(Tenant::where('kind', 'platform')->firstOrFail());
    }

    /** @param array<string, mixed> $options */
    private function amorcer(array $options = [])
    {
        return $this->artisan('naja7i:creer-un-administrateur', $options + [
            '--email' => 'admin@naja7i.ma',
            '--role' => 'super_admin',
            '--env' => 'testing',
        ]);
    }

    // ═══ Le cercle est ouvert ══════════════════════════════════════════════

    public function test_la_commande_cree_un_compte_capable_d_entrer_au_back_office(): void
    {
        Notification::fake();

        $this->amorcer()->assertSuccessful();

        $compte = User::where('email', 'admin@naja7i.ma')->sole();

        $this->assertSame('active', $compte->status);
        $this->assertSame(
            ['super_admin'],
            $compte->memberships()->with('role')->get()->map(fn ($m) => $m->role->code)->all(),
        );
        $this->assertNotEmpty(
            app(PermissionResolver::class)->forUser($compte),
            'Sans permission, `canAccessPanel()` referme la porte qu’on vient d’ouvrir.',
        );

        /* LE COURRIEL N'EST PAS LE CHEMIN. Une préproduction fraîche n'a pas
         * nécessairement de SMTP : faire dépendre la sortie du cercle vicieux
         * d'un canal qui n'existe pas remplacerait un blocage par un autre. */
        Notification::assertNothingSent();
    }

    public function test_le_role_inconnu_est_refuse_avec_la_liste_des_roles_valides(): void
    {
        [$code, $sortie] = $this->executer(['--role' => 'grand-chef']);

        $this->assertSame(Command::FAILURE, $code);
        $this->assertStringContainsString('role_inconnu=grand-chef', $sortie);

        /* LA LISTE ENTIÈRE, et pas seulement un exemple : un refus qui ne dit
         * pas ce qui était attendu envoie l'exploitant lire le code. */
        foreach (Role::whereNull('tenant_id')->where('is_staff', true)->where('is_active', true)->pluck('code') as $valide) {
            $this->assertStringContainsString($valide, $sortie);
        }

        foreach (['auteur', 'reviseur', 'editeur'] as $inactif) {
            $this->assertStringNotContainsString($inactif, $sortie);
        }

        $this->assertSame(0, User::where('email', 'admin@naja7i.ma')->count());
    }

    public function test_un_role_de_candidat_est_refuse_comme_un_role_inconnu(): void
    {
        /* Il existe, mais il ne porte aucune permission de back-office : le
         * compte créé ne pourrait pas entrer, c'est-à-dire exactement le
         * problème qu'on résout. */
        $this->amorcer(['--role' => 'candidat'])
            ->expectsOutputToContain('role_inconnu=candidat')
            ->assertFailed();

        $this->assertSame(0, User::where('email', 'admin@naja7i.ma')->count());
    }

    public function test_une_adresse_invalide_est_refusee(): void
    {
        $this->amorcer(['--email' => 'pas-une-adresse'])
            ->expectsOutputToContain('email_invalide=pas-une-adresse')
            ->assertFailed();

        $this->assertSame(0, User::count() - User::whereNot('email', 'pas-une-adresse')->count());
    }

    public function test_l_environnement_doit_etre_nomme(): void
    {
        /* Une commande de base qui choisit son environnement toute seule finit
         * un jour par choisir le mauvais — et celle-ci ouvre un accès
         * d'administration. */
        $this->artisan('naja7i:creer-un-administrateur', [
            '--email' => 'admin@naja7i.ma', '--role' => 'super_admin',
        ])
            ->expectsOutputToContain('env_absent=1')
            ->assertFailed();

        $this->assertSame(0, User::where('email', 'admin@naja7i.ma')->count());
    }

    // ═══ Aucun mot de passe par la ligne de commande ═══════════════════════

    public function test_la_commande_n_accepte_aucun_mot_de_passe_en_argument(): void
    {
        /*
         * LA RÈGLE CENTRALE, gardée sur la DÉFINITION de la commande plutôt que
         * sur son comportement : une option qui n'existe pas ne peut pas être
         * passée, et Symfony refuse l'appel avant toute exécution. Chercher un
         * effet de bord aurait supposé que l'option existe.
         */
        $definition = (new CreerUnAdministrateur)->getDefinition();

        foreach ($definition->getOptions() as $option) {
            $this->assertStringNotContainsString(
                'password', strtolower($option->getName()),
                'Un secret passé en argument survit dans l’historique du shell.',
            );
            $this->assertStringNotContainsString('mot-de-passe', strtolower($option->getName()));
        }

        foreach ($definition->getArguments() as $argument) {
            $this->assertStringNotContainsString('password', strtolower($argument->getName()));
        }

        $this->assertSame(
            ['email', 'role', 'dry-run'],
            array_keys($definition->getOptions()),
            'Trois options, et pas une de plus : chacune qui s’ajoute est une chose '
            .'qui atterrit dans l’historique du shell.',
        );
    }

    public function test_le_compte_cree_ne_porte_aucun_mot_de_passe_utilisable(): void
    {
        Notification::fake();
        $this->amorcer()->assertSuccessful();

        $compte = User::where('email', 'admin@naja7i.ma')->sole();

        /* Le mot de passe posé est aléatoire et n'a été imprimé nulle part :
         * personne ne le connaît, pas même l'appelant. Le seul chemin ouvert
         * est le lien. */
        $this->assertNotNull($compte->password);
        $this->assertFalse(
            password_verify('', $compte->password),
            'Un mot de passe vide serait un compte ouvert le temps qu’on l’oublie.',
        );
    }

    // ═══ Le lien : à usage unique, daté, et il ouvre vraiment ══════════════

    public function test_le_lien_imprime_est_a_usage_unique_et_expire(): void
    {
        Notification::fake();

        [, $sortie] = $this->executer();

        $jeton = $this->jetonDe($sortie);
        $invitation = StaffInvitation::query()->sole();

        $this->assertSame(hash('sha256', $jeton), $invitation->token_hash, 'Le jeton est haché en base.');
        $this->assertNull($invitation->invited_by, 'Aucun humain n’a invité : c’est un amorçage.');
        $this->assertTrue($invitation->expires_at->isFuture());
        $this->assertTrue(
            $invitation->expires_at->lessThanOrEqualTo(
                now()->addHours((int) config('naja7i.staff_invitation.expire_hours'))
            ),
            'Le lien est daté comme toute invitation.',
        );

        /* IL OUVRE VRAIMENT : le même `accept()` que les invitations
         * ordinaires, sans chemin parallèle. */
        $compte = app(StaffInvitationService::class)->accept(
            $jeton, 'une-phrase-de-passe-solide', 'une-phrase-de-passe-solide',
        );

        $this->assertSame('admin@naja7i.ma', $compte->email);
        $this->assertNotNull($invitation->fresh()->consumed_at);

        /* ET UNE SEULE FOIS. */
        $this->expectException(ValidationException::class);
        app(StaffInvitationService::class)->accept(
            $jeton, 'une-autre-phrase-solide', 'une-autre-phrase-solide',
        );
    }

    public function test_le_jeton_n_apparait_dans_aucun_journal(): void
    {
        Notification::fake();

        [, $sortie] = $this->executer();
        $jeton = $this->jetonDe($sortie);

        /* Le jeton est imprimé UNE fois, dans le lien, et nulle part ailleurs :
         * ni dans un envoi, ni dans le journal applicatif. */
        $this->assertSame(
            1,
            substr_count($sortie, $jeton),
            'Le jeton ne doit apparaître qu’une seule fois dans la sortie.',
        );
        Notification::assertNothingSent();
    }

    // ═══ Rejouée, elle ne casse rien ═══════════════════════════════════════

    public function test_rejouee_sur_un_compte_existant_elle_n_ecrase_rien_et_le_dit(): void
    {
        Notification::fake();
        $this->amorcer()->assertSuccessful();

        $avant = User::where('email', 'admin@naja7i.ma')->sole();
        $invitationsAvant = StaffInvitation::count();

        /* Un exploitant qui doute rejoue ; lui répondre en modifiant quelque
         * chose transformerait son doute en incident. */
        $this->amorcer(['--role' => 'finance'])
            ->expectsOutputToContain('compte_existant=admin@naja7i.ma')
            ->expectsOutputToContain('roles=super_admin')
            ->assertSuccessful();

        $apres = User::where('email', 'admin@naja7i.ma')->sole();

        $this->assertSame($avant->uuid, $apres->uuid);
        $this->assertSame($avant->password, $apres->password, 'Le mot de passe n’est pas réémis.');
        $this->assertSame(
            ['super_admin'],
            $apres->memberships()->with('role')->get()->map(fn ($m) => $m->role->code)->all(),
            'Le rôle demandé au second appel n’écrase pas celui du premier.',
        );
        $this->assertSame($invitationsAvant, StaffInvitation::count(), 'Aucun second jeton.');
    }

    public function test_dry_run_n_ecrit_rien(): void
    {
        Notification::fake();

        $this->amorcer(['--dry-run' => true])
            ->expectsOutputToContain('mode=sec')
            ->assertSuccessful();

        $this->assertSame(0, User::where('email', 'admin@naja7i.ma')->count());
        $this->assertSame(0, StaffInvitation::count());
        Notification::assertNothingSent();
    }

    // ═══ La trace ══════════════════════════════════════════════════════════

    public function test_le_geste_laisse_sa_trace(): void
    {
        Notification::fake();
        $this->amorcer(['--role' => 'expert_pedagogue'])->assertSuccessful();

        $compte = User::where('email', 'admin@naja7i.ma')->sole();
        $invitation = StaffInvitation::query()->sole();
        $role = Role::where('code', 'expert_pedagogue')->whereNull('tenant_id')->sole();

        /* Trois lignes durables disent les quatre faits : le compte visé et sa
         * date, le rôle, et une invitation dont `invited_by` NUL signifie
         * « posée par l'amorçage ». On n'ajoute pas de table pour cela — la
         * nullité porte le sens, comme `plan_versions.composed_by`. */
        $this->assertNotNull($compte->created_at);
        $this->assertSame($role->id, $compte->memberships()->sole()->role_id);
        $this->assertSame($compte->id, $invitation->user_id);
        $this->assertNull($invitation->invited_by);
    }

    // ═══ Outils ════════════════════════════════════════════════════════════

    /**
     * Exécute la commande et rend son code de sortie AVEC sa sortie entière.
     *
     * `expectsOutputToContain` consomme les écritures une par une, dans
     * l'ordre : il ne sait pas dire « cette chaîne est quelque part ». Pour
     * inspecter un message composé de plusieurs lignes — la liste des rôles,
     * le lien — on capture tout et on cherche dedans.
     *
     * @param  array<string, mixed>  $options
     * @return array{0: int, 1: string}
     */
    private function executer(array $options = []): array
    {
        $code = Artisan::call('naja7i:creer-un-administrateur', $options + [
            '--email' => 'admin@naja7i.ma',
            '--role' => 'super_admin',
            '--env' => 'testing',
        ]);

        return [$code, Artisan::output()];
    }

    private function jetonDe(string $sortie): string
    {
        $this->assertMatchesRegularExpression('/token=([A-Za-z0-9]{64})/', $sortie);
        preg_match('/token=([A-Za-z0-9]{64})/', $sortie, $m);

        return $m[1];
    }
}
