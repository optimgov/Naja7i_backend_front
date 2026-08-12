<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Notifications\ResetPasswordNotification;
use App\Services\EmailVerificationService;
use App\Services\PermissionResolver;
use App\Tenancy\TenantContext;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
class User extends Authenticatable implements FilamentUser, MustVerifyEmail
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
    public function canAccessPanel(Panel $panel): bool
    {
        return in_array('questions.view', app(PermissionResolver::class)->forUser($this), true);
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
