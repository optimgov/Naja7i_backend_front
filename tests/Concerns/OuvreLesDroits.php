<?php

namespace Tests\Concerns;

use App\Models\AccessGrantRecord;
use App\Models\User;

/**
 * Ouvrir à un compte de test les capacités que le lot 3A.9 vend.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * POURQUOI CE TRAIT EXISTE DEPUIS LES MURS, ET PAS AVANT
 *
 * Jusqu'à ce lot, aucune route du parcours n'était fermée : les fixtures
 * créaient un candidat et travaillaient. Les murs changent cela — l'examen
 * blanc, la série ciblée et la séance mémoire demandent désormais un droit.
 *
 * Les tests qui portent sur la PÉDAGOGIE — ce qu'une séance sert, comment une
 * série se compose, ce qu'un rapport contient — n'ont pas à éprouver le mur en
 * plus : ils ouvrent le droit et vont à leur sujet. Ceux qui portent sur le
 * MUR lui-même ne l'ouvrent pas, et c'est ce contraste qui les rend lisibles.
 *
 * L'origine est `purchase` : c'est ce qu'un candidat obtient en payant, et
 * c'est la seule origine qui rend un compte `actif` au sens de l'ADR-0033.
 */
trait OuvreLesDroits
{
    /**
     * Ouvre des capacités sans terme, à portée nulle — la plateforme entière.
     *
     * Portée nulle, parce qu'aucune offre du catalogue CRMEF n'en porte
     * (vérifié) : fabriquer ici une portée fine ferait tester la RÉSOLUTION
     * des portées, qui a ses propres tests depuis le lot 3A.2, plutôt que le
     * mur.
     */
    protected function ouvrirLesDroits(User $compte, string ...$capacites): void
    {
        foreach ($capacites as $capacite) {
            AccessGrantRecord::create([
                'user_id' => $compte->id,
                'capability' => $capacite,
                'starts_at' => now()->subDay(),
                'origin' => 'purchase',
            ]);
        }
    }
}
