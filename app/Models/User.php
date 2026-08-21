<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Notifications\ResetPasswordNotification;
use App\Services\EmailVerificationService;
use App\Services\PermissionResolver;
use App\Tenancy\TenantContext;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use RuntimeException;

/**
 * Compte GLOBAL : pas de tenant_id. Le rattachement passe par memberships.
 *
 * PAS-2 : `MustVerifyEmail` déclenche l'envoi du lien de vérification sur
 * l'événement Registered. Le blocage effectif, lui, est piloté par le
 * middleware EnsureEmailIsVerified et le réglage naja7i.email_verification_gate
 * — l'interface ne fait que rendre le compte « vérifiable ».
 */
class User extends Authenticatable implements FilamentUser, HasName, MustVerifyEmail
{
    use HasPublicUuid, Notifiable;

    protected $fillable = ['email', 'phone', 'password', 'locale', 'status'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /**
     * Ce que le candidat DÉCLARE préparer — DET-42.
     *
     * `hasOne` et non `hasMany` : l'unicité est tenue en base par
     * `candidate_profiles_unique` sur (tenant, candidat). La relation est
     * elle-même filtrée par le scope de tenant du modèle lié — un compte peut
     * donc préparer une épreuve en B2C et une autre dans un centre partenaire
     * sans que les deux se voient.
     */
    public function candidateProfile(): HasOne
    {
        return $this->hasOne(CandidateProfile::class);
    }

    /** Méthodes de connexion du compte : mot de passe, Google, Facebook (PAS-2). */
    public function identities(): HasMany
    {
        return $this->hasMany(Identity::class);
    }

    /** Actes juridiques accomplis par le candidat. Historique, jamais modifié. */
    public function legalEvents(): HasMany
    {
        return $this->hasMany(LegalEvent::class);
    }

    /** Jetons opaques de vérification d'e-mail, actifs ou consommés (PAS-3). */
    public function verificationTokens(): HasMany
    {
        return $this->hasMany(VerificationToken::class);
    }

    /**
     * PAS-3 — On remplace la notification de Laravel, qui bâtit une URL signée
     * vers l'API. Le lien doit mener au FRONTEND et porter un jeton opaque
     * (ADR-0008 §4). C'est ce que fait le service, qui émet aussi le jeton et
     * invalide le précédent.
     *
     * Cette méthode est le point d'entrée de l'événement `Registered` : c'est
     * par elle que l'inscription du PAS-2 déclenche un envoi réel.
     */
    public function sendEmailVerificationNotification(): void
    {
        app(EmailVerificationService::class)->send($this);
    }

    /** Idem pour le mot de passe oublié : lien vers le frontend, pas vers l'API. */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    /** Vérifie un rôle dans le tenant COURANT — jamais globalement. */
    public function hasRole(string $roleCode): bool
    {
        return $this->memberships()
            ->whereHas('role', fn ($q) => $q->where('code', $roleCode))
            ->exists();
    }

    public function isStaff(): bool
    {
        return $this->memberships()
            ->whereHas('role', fn ($q) => $q->where('is_staff', true))
            ->exists();
    }

    /**
     * Inscrit ce user comme candidat B2C sur le tenant plateforme.
     *
     * PAS-1.1 (BLOC-1) — La version précédente était contradictoire : elle
     * fournissait tenant_id = 1 en dur alors que le scope global filtrait sur
     * le tenant courant. Sous un tenant centre, la recherche ne trouvait rien
     * (tenant_id = centre ET tenant_id = 1), puis la création insérait quand
     * même une appartenance plateforme invisible au contexte — avec, à la
     * seconde tentative, une violation d'unicité incompréhensible.
     *
     * Désormais : la méthode EXIGE que le contexte courant soit la plateforme.
     * Elle ne fournit plus de tenant_id : le trait le pose.
     */
    /**
     * L'entrée du back-office éditorial — lot A4.
     *
     * LE PANNEAU N'EST PAS UNE PERMISSION DE PLUS. Il n'ouvre rien par
     * lui-même : chaque ressource est gardée par sa policy, et chaque écriture
     * par les services. Ce contrôle-ci ne fait qu'éviter d'ouvrir à un candidat
     * une porte où il ne verrait que des refus — `questions.view` est le plus
     * petit droit qui rende le panneau utile.
     *
     * Filament exige cette méthode hors environnement local, et c'est heureux :
     * sans elle, tout compte authentifié entrerait.
     */
    /**
     * LE PANNEAU S'OUVRE AUX MEMBRES DU PERSONNEL, PAS AUX SEULS RÉDACTEURS.
     *
     * Première écriture : `in_array('questions.view', …)`. Elle prenait UNE
     * permission éditoriale pour la définition de « fait partie de l'équipe ».
     * Le rôle `finance` porte `orders.view`, `orders.validate` et
     * `refunds.issue` — pas `questions.view`. L'opérateur prévu pour valider les
     * commandes était donc renvoyé à la page de connexion par le panneau qui
     * héberge la file des commandes. Mesuré en recette humaine le 17 août.
     *
     * La bonne question n'est pas « sait-il rédiger », c'est « a-t-il un rôle de
     * personnel ». Un candidat n'a aucune permission ; toute personne qui en
     * porte au moins une a une raison d'entrer, et ce sont les policies de
     * chaque ressource qui décident ensuite de ce qu'elle voit.
     *
     * On garde une garde grossière ici et des gardes fines en dessous : c'est
     * l'inverse qui produit des trous — une garde fine à l'entrée décide pour
     * des surfaces qu'elle ne connaît pas.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->status === 'active'
            && app(PermissionResolver::class)->forUser($this) !== [];
    }

    /**
     * UN COMPTE NAJA7I N'A PAS DE NOM, et c'est une décision du PAS-2 : il
     * s'identifie par son e-mail, jamais par un état civil qu'on n'a pas
     * demandé. Filament, lui, affiche un nom dans son menu de compte et exige
     * une chaîne — sans cette méthode, `getUserName()` reçoit null et TOUTE
     * page du panneau échoue au rendu.
     *
     * Le défaut n'apparaissait pas dans les tests de composants Livewire, qui
     * ne rendent pas la mise en page du panneau. Il a fallu une requête HTTP
     * complète sur `/admin` pour le voir — d'où le test qui la fait.
     */
    public function getFilamentName(): string
    {
        return $this->email;
    }

    public function grantCandidateRole(): Membership
    {
        $context = app(TenantContext::class);

        if (! $context->isPlatform()) {
            throw new RuntimeException(
                'grantCandidateRole() ne peut être appelée que sous le tenant plateforme. '
                .'Contexte courant : tenant #'.$context->id().'. '
                .'Pour rattacher un candidat à un centre partenaire, utilisez le service d\'adhésion B2B.'
            );
        }

        return $this->memberships()->firstOrCreate([
            'role_id' => Role::where('code', 'candidat')->value('id'),
        ]);
    }
}
