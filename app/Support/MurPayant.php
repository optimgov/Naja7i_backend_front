<?php

namespace App\Support;

use App\Contracts\AccessGrant;
use App\Models\AccessGrantRecord;
use App\Models\Exam;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * Le mur payant — lot 3A.9.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * LE MUR EST UN CHAMP, PAS UNE ROUTE
 *
 * C'est la règle qui gouverne tout le lot, et elle se lit d'abord dans ce qui
 * n'est PAS ici : aucun middleware. Une capacité manquante ne ferme pas une
 * adresse, elle retire un champ du rendu — la carte de maîtrise se rend au
 * niveau racine, l'ordonnance et les rendez-vous mémoire disparaissent de la
 * réponse. Jamais un bouton grisé, jamais un champ vide « à débloquer »,
 * jamais un compteur factice : à qui l'on annonce « 42 dus » sans pouvoir
 * ouvrir de séance, on a construit un cul-de-sac.
 *
 * Le précédent est `CorrectionResource`, qui rend la justification et ferme la
 * cause avec `cause_locked`. Une route entière réservée serait invendable sous
 * la règle 404 du dépôt.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * CE QUI RESTE À REFUSER, ET POURQUOI EN 403 EXPLICITE
 *
 * Un champ se retire d'une lecture ; une ACTION doit se refuser. Ouvrir une
 * série ciblée, un examen blanc ou une séance mémoire écrit — le client ne
 * propose plus le geste, mais le serveur ne peut pas se contenter de compter
 * là-dessus.
 *
 * Ce refus est un 403 NOMMÉ, et pas un 404. La règle « 404, jamais 403 » du
 * dépôt vise ce qui appartient à AUTRUI, parce qu'un 403 y confirmerait une
 * existence : c'est une garde contre l'ÉNUMÉRATION. Ici il n'y a rien à
 * énumérer — la fonction est au catalogue, son prix est public, et le candidat
 * sait déjà qu'elle existe. Lui répondre « introuvable » masquerait la raison
 * sans rien protéger. C'est le raisonnement du 403 explicite de
 * `RequirePermission` (PAS-9), appliqué au candidat.
 *
 * Et le refus NOMME ce qui l'ouvrirait, avec le libellé du registre : c'est la
 * règle des portes — un écran qui se ferme dit quelle clé demander.
 */
final class MurPayant
{
    /**
     * Ce candidat a-t-il cette capacité, POUR CETTE ÉPREUVE ?
     *
     * ═══════════════════════════════════════════════════════════════════════
     * LA PORTÉE EST PASSÉE, MÊME QUAND AUCUNE OFFRE N'EN PORTE
     *
     * Le catalogue CRMEF travaille aujourd'hui à portée nulle — vérifié — et
     * l'on pourrait donc interroger le droit sans portée. Ce serait un piège :
     * `DatabaseAccessGrant` ne considère un droit `(audience, lycee)` que si on
     * lui donne un point de départ à remonter. Interroger sans portée ferait
     * échouer, en silence, tout droit vendu sur une portée fine — et le lot
     * 3A.6 en a livré un (S-11).
     *
     * On donne donc l'épreuve, et la chaîne d'ascendance fait le reste :
     * épreuve → famille → filière → catégorie de public → plateforme entière.
     * Un droit sans portée reste dans cette chaîne : il autorise comme avant.
     */
    public static function ouvre(AccessGrant $droits, User $candidat, string $capacite, Exam $epreuve): bool
    {
        return $droits->allows(
            $candidat,
            $capacite,
            AccessGrantRecord::SCOPE_EXAM,
            $epreuve->uuid,
        );
    }

    /**
     * Le refus d'une ACTION que le palier courant n'ouvre pas.
     *
     * Le code est le même partout — un client qui distingue « fermé faute de
     * droit » de « fermé faute de banque » peut proposer la bonne suite, et
     * c'est tout ce qu'on lui demande. La capacité voyage dans les détails
     * pour que l'écran sache quoi mettre en avant ; son libellé voyage dans le
     * message, parce qu'un code d'énumération n'a jamais rien dit à personne.
     */
    public static function refus(string $capacite): JsonResponse
    {
        $presentation = app(CapabilityRegistry::class)->publicPresentation([$capacite]);

        return ApiError::make(
            'CAPABILITY_REQUIRED',
            __('parcours.capacite_requise', ['capacite' => $presentation[0]['label']]),
            403,
            ['capability' => $capacite],
        );
    }
}
