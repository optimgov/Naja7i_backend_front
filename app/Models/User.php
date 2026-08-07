<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Tenancy\TenantContext;
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
class User extends Authenticatable implements MustVerifyEmail
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
