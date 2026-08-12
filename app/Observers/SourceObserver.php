<?php

namespace App\Observers;

use App\Models\Source;

/**
 * Relit l'état de vérification que la BASE vient peut-être d'annuler.
 *
 * LE PROBLÈME QUE CET OBSERVATEUR RÉSOUT, ET SA NATURE. Depuis le PAS-29, deux
 * déclencheurs annulent `verified_at` et `verified_by` quand une colonne
 * porteuse de sens change. Le trigger est un `BEFORE UPDATE` : il modifie la
 * ligne écrite, pas l'instance PHP qui vient de l'écrire. Après un
 * `$source->update(['title_fr' => …])`, le modèle en mémoire croit donc encore
 * la source vérifiée — et un écran qui l'affiche montre l'inverse de la vérité,
 * jusqu'à ce que quelqu'un recharge.
 *
 * C'est exactement ce que le lot A4 doit éviter : un rédacteur qui corrige un
 * titre doit voir IMMÉDIATEMENT que la re-vérification est requise. Montrer cet
 * état, pas le subir.
 *
 * CE N'EST PAS UNE RÈGLE MÉTIER, et c'est pourquoi cet observateur a le droit
 * d'exister dans un lot qui interdit d'en écrire. Il ne décide rien, n'annule
 * rien, n'autorise rien : il va relire ce que la base a déjà décidé seule. La
 * garantie reste entière si on le supprime — seul l'affichage ment à nouveau.
 *
 * Deux colonnes relues, pas la ligne entière : recharger tout écraserait des
 * attributs que l'appelant vient de poser et qu'il s'apprête peut-être à lire.
 */
class SourceObserver
{
    public function updated(Source $source): void
    {
        if ($source->verified_at === null) {
            return;   // rien à annuler : la base n'a rien pu changer
        }

        $enBase = Source::query()
            ->whereKey($source->getKey())
            ->first(['id', 'verified_at', 'verified_by']);

        if ($enBase === null || $enBase->verified_at !== null) {
            return;
        }

        /* `syncOriginal` derrière : sans lui, le modèle se croirait « sale » sur
         * ces deux colonnes et un `save()` ultérieur tenterait de réécrire ce
         * que le trigger vient d'annuler. */
        $source->setAttribute('verified_at', null);
        $source->setAttribute('verified_by', null);
        $source->syncOriginal();
    }
}
