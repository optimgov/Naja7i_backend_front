<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PermissionResolver;
use App\Services\StaffInvitationService;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * `php artisan naja7i:creer-un-administrateur` — casser l'œuf sans poule.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * LE CERCLE QU'ELLE OUVRE
 *
 * `canAccessPanel()` exige au moins une permission, donc une adhésion à un
 * rôle. Sur une base neuve, personne n'en a — et les invitations de personnel
 * ne résolvent rien, puisque les émettre demande déjà un compte autorisé.
 * Aucun chemin n'existait pour entrer la première fois : c'est cette commande,
 * et elle seule, qui en ouvre un.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * AUCUN MOT DE PASSE EN ARGUMENT — LA RÈGLE QUI GOUVERNE SA FORME
 *
 * Un mot de passe passé en argument atterrit dans l'historique du shell, dans
 * la table des processus le temps de l'exécution, et dans tout journal qui
 * capture la ligne de commande. Il y reste après que le compte a changé de
 * mot de passe.
 *
 * La commande crée donc le compte SANS mot de passe utilisable et imprime un
 * LIEN à usage unique, daté, dont le jeton est haché en base. C'est
 * exactement le mécanisme d'invitation du personnel (PAS-2A), emprunté plutôt
 * que recopié — un second chemin pour poser un mot de passe finirait par être
 * le plus faible des deux.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * ELLE NE DEVINE RIEN
 *
 * Le rôle est nommé et refusé s'il n'existe pas, avec la liste des rôles
 * valides — deviner « super_admin » parce que c'est le plus courant donnerait
 * tous les pouvoirs à qui n'en demandait qu'une part. `--env` est exigé pour
 * la même raison que partout ailleurs dans ce dépôt : une commande de base qui
 * choisit son environnement toute seule finit un jour par choisir le mauvais.
 */
class CreerUnAdministrateur extends Command
{
    protected $signature = 'naja7i:creer-un-administrateur
                            {--email= : Adresse du compte à créer}
                            {--role= : Code du rôle de personnel à lui donner}
                            {--dry-run : Annoncer ce qui serait fait, sans rien écrire}';

    protected $description = 'Crée le premier compte capable d’entrer au back-office (M-018)';

    public function handle(StaffInvitationService $invitations, TenantContext $contexte): int
    {
        if (! $this->assertEnvironnementNomme()) {
            return self::FAILURE;
        }

        $contexte->set(Tenant::where('kind', 'platform')->firstOrFail());

        $email = $this->emailValide();
        $role = $this->roleDePersonnel();

        if ($email === null || $role === null) {
            return self::FAILURE;
        }

        $existant = User::where('email', $email)->first();

        if ($existant !== null) {
            return $this->direCeQuiExiste($existant);
        }

        $this->line('email='.$email);
        $this->line('role='.$role->code);
        $this->line('environnement='.app()->environment());

        if ($this->option('dry-run')) {
            $this->line('mode=sec');
            $this->line('Aucun compte créé. Retirez --dry-run pour agir.');

            return self::SUCCESS;
        }

        [$compte, $jeton] = DB::transaction(function () use ($email, $role, $invitations): array {
            /*
             * SANS MOT DE PASSE UTILISABLE. La colonne est obligatoire ; on y
             * met une valeur aléatoire que PERSONNE ne connaît — ni l'appelant,
             * ni les journaux — et que le lien d'invitation remplacera. Laisser
             * un mot de passe deviné « à changer plus tard » serait un compte
             * ouvert le temps qu'on l'oublie.
             */
            $compte = User::create([
                'email' => $email,
                'password' => bin2hex(random_bytes(32)),
                'locale' => 'fr',
                'status' => 'active',
            ]);

            /* L'ADRESSE EST TENUE POUR VÉRIFIÉE, et c'est le propriétaire qui
             * l'atteste en la tapant : il n'y a pas de boîte à consulter sur
             * une machine dont le courriel n'est pas encore branché, et le lien
             * ne passe pas par elle. */
            $compte->markEmailAsVerified();

            $compte->memberships()->create(['role_id' => $role->id]);

            [, $jeton] = $invitations->issueForBootstrap($compte->fresh());

            return [$compte->fresh(), $jeton];
        });

        $permissions = app(PermissionResolver::class)->forUser($compte);

        /*
         * LA TRACE. Le geste laisse trois lignes durables — le compte, son
         * adhésion, et une invitation dont `invited_by` NUL signifie « posée
         * par l'amorçage, aucun humain n'a invité ». On n'ajoute pas de table
         * pour cela : la nullité porte déjà le sens (voir la migration).
         *
         * Le journal applicatif reçoit en plus les quatre faits, pour que
         * l'exploitant les retrouve sans requête SQL. LE JETON N'Y EST PAS.
         */
        Log::info('Amorçage : premier administrateur créé', [
            'email' => $compte->email,
            'role' => $role->code,
            'environnement' => app()->environment(),
            'permissions' => count($permissions),
        ]);

        $this->newLine();
        $this->info('Compte créé.');
        $this->line('uuid='.$compte->uuid);
        $this->line('permissions='.count($permissions));
        $this->line('expire_dans_heures='.config('naja7i.staff_invitation.expire_hours'));
        $this->newLine();
        $this->line('Lien de définition du mot de passe — à USAGE UNIQUE :');
        $this->line($this->lien($jeton));
        $this->newLine();
        $this->warn('Ce lien ne sera pas réaffiché. Il n’a été ni envoyé par courriel, ni journalisé.');

        return self::SUCCESS;
    }

    /**
     * `--env` EXPLICITE, SANS EXCEPTION.
     *
     * Laravel le définit globalement : il est donc toujours lisible, et son
     * absence est un choix de l'appelant, pas un oubli de cette commande. Une
     * commande de base qui choisit son environnement toute seule finit un jour
     * par choisir le mauvais — et celle-ci crée un accès d'administration.
     */
    private function assertEnvironnementNomme(): bool
    {
        if (filled($this->option('env'))) {
            return true;
        }

        $this->error('env_absent=1');
        $this->line(
            'Nommez l’environnement : --env=local, --env=staging, --env=production. '
            .'Cette commande ouvre un accès d’administration ; elle ne devine pas où.'
        );

        return false;
    }

    private function emailValide(): ?string
    {
        $email = trim((string) $this->option('email'));

        $validateur = Validator::make(['email' => $email], [
            'email' => ['required', 'email:rfc', 'max:254'],
        ]);

        if ($validateur->fails()) {
            $this->error('email_invalide='.($email === '' ? '(vide)' : $email));
            $this->line($validateur->errors()->first('email'));

            return null;
        }

        return mb_strtolower($email);
    }

    /**
     * Le rôle, NOMMÉ et vérifié — et le refus donne la liste.
     *
     * Un refus qui ne dit pas ce qui était attendu envoie l'exploitant lire le
     * code. Les rôles de CANDIDAT sont exclus : ils ne portent aucune
     * permission de back-office, et en créer un ici produirait un compte qui ne
     * peut pas entrer — c'est-à-dire exactement le problème qu'on résout.
     */
    private function roleDePersonnel(): ?Role
    {
        $code = trim((string) $this->option('role'));
        $disponibles = Role::query()->whereNull('tenant_id')->where('is_staff', true)->where('is_active', true)
            ->orderBy('code')->pluck('code');

        if ($code === '') {
            $this->error('role_absent=1');
            $this->line('Nommez le rôle : --role='.$disponibles->implode('|'));

            return null;
        }

        $role = Role::query()->whereNull('tenant_id')->where('code', $code)->where('is_active', true)->first();

        if ($role === null || ! $role->is_staff) {
            $this->error('role_inconnu='.$code);
            $this->line('Rôles de personnel disponibles : '.$disponibles->implode(', ').'.');

            return null;
        }

        return $role;
    }

    /**
     * Le compte existe : on DIT ce qui est, et on s'arrête.
     *
     * On n'écrase aucune adhésion et on ne réémet aucun jeton. Rejouer une
     * commande d'amorçage sur une machine déjà amorcée est le geste d'un
     * exploitant qui doute ; lui répondre en modifiant quelque chose
     * transformerait son doute en incident.
     */
    private function direCeQuiExiste(User $compte): int
    {
        $roles = $compte->memberships()->with('role')->get()
            ->map(fn ($m) => $m->role?->code)->filter()->unique()->sort()->values();

        $this->warn('compte_existant='.$compte->email);
        $this->line('uuid='.$compte->uuid);
        $this->line('statut='.$compte->status);
        $this->line('roles='.($roles->isEmpty() ? '(aucun)' : $roles->implode(',')));
        $this->line('permissions='.count(app(PermissionResolver::class)->forUser($compte)));
        $this->newLine();
        $this->line('Rien n’a été modifié : ni rôle, ni mot de passe, ni invitation.');

        if ($roles->isEmpty()) {
            $this->line(
                'Ce compte ne porte aucun rôle et ne peut donc pas entrer au back-office. '
                .'Cette commande ne lui en ajoute pas — elle amorce, elle ne répare pas.'
            );
        }

        return self::SUCCESS;
    }

    /** Le lien que le frontend sait consommer — le même que l'invitation. */
    private function lien(string $jeton): string
    {
        return rtrim((string) config('app.frontend_url'), '/')."/fr/invitation-personnel?token={$jeton}";
    }
}
