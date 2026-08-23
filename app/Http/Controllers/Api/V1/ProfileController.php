<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CandidateProfileResource;
use App\Models\CandidateProfile;
use App\Models\Exam;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Le profil candidat : ce qu'il DÉCLARE préparer — DET-42.
 *
 * IL REMPLACE LA DÉDUCTION, IL NE S'AJOUTE PAS À CÔTÉ. L'épreuve « suivie »
 * était jusqu'ici devinée à partir de la tentative la plus récente, et la
 * devinette se trompe dans un cas ordinaire : préparer le CRMEF français, ouvrir
 * un diagnostic de curiosité sur une autre épreuve, et voir son tableau de bord
 * changer d'allégeance. Deux réponses à « quelle épreuve je prépare » se
 * contrediraient un jour, dans des conditions d'autant plus difficiles à
 * reproduire qu'elles dépendraient de l'ordre des tentatives.
 *
 * CE QUE LE FRONTEND DOIT EN FAIRE, ET C'EST LE CONTRAT DE CE PAS. Quand
 * `exam_code` est nul, il peut PROPOSER la dernière épreuve travaillée —
 * `GET me/attempts` la rend — comme une suggestion à confirmer. Proposer n'est
 * pas décider : le candidat tranche d'un clic, et ce clic écrit ici. Une
 * déduction qui s'appliquerait sans confirmation reste, elle, une seconde
 * vérité, et c'est celle que ce pas supprime.
 *
 * UN PROFIL ABSENT N'EST PAS UNE ERREUR. `GET` rend les mêmes clés à `null`,
 * jamais une 404 : un compte qui vient de se créer n'a rien choisi, et
 * répondre « introuvable » à qui demande son propre profil serait un mensonge
 * doublé d'un piège pour l'appelant.
 */
class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        /* Un modèle NON ENREGISTRÉ quand rien n'existe : la ressource sérialise
         * alors ses champs nuls, et la forme de la réponse reste unique. */
        /* La chaîne de déduction de la catégorie est chargée d'un coup : sans
         * elle, `audience` coûterait trois requêtes de plus par lecture de
         * profil, sur une route que le tableau de bord appelle à chaque
         * ouverture. */
        $profil = $request->user()->candidateProfile()
            ->with('exam.track.family.audience')->first()
            ?? new CandidateProfile;

        return response()->json(['data' => CandidateProfileResource::make($profil)]);
    }

    /**
     * Déclarer, ou redéclarer. REJOUABLE SANS EFFET DE BORD.
     *
     * `PUT` remplace : les trois champs sont écrits à chaque appel, et un champ
     * de plan absent de la charge utile est remis à null. C'est la sémantique du
     * verbe, et elle évite la question sans réponse d'un `PATCH` — « comment
     * j'efface mon objectif ? ». Le `GET` rendant les trois champs, un client
     * qui veut n'en changer qu'un les renvoie tous sans rien deviner.
     *
     * Rien d'autre ne se produit : ni tentative créée, ni maîtrise recalculée,
     * ni rendez-vous replanifié. Changer d'épreuve déclarée ne touche pas au
     * travail déjà fait sur l'ancienne — il reste lisible par les routes qui
     * prennent l'épreuve en chemin.
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            /*
             * L'ÉPREUVE DOIT ÊTRE PUBLIÉE, et le refus emprunte le message
             * volontairement ambigu du PAS-4 : « n'existe pas ou n'est pas
             * encore publiée ». Distinguer les deux laisserait deviner le
             * catalogue à venir, ce qu'aucune route publique ne fait.
             *
             * Refus en 422 et non en 404 : `exam_code` est une VALEUR de la
             * charge utile, pas une ressource demandée en chemin. La règle
             * « 404, jamais 403 » vise l'énumération des ressources d'autrui ;
             * elle ne s'applique pas à un champ de formulaire, que le frontend
             * doit pouvoir signaler à côté de sa saisie.
             */
            'exam_code' => ['required', 'string', 'max:64', function (string $attribut, mixed $valeur, callable $refuser) {
                if (! Exam::published()->where('code', $valeur)->exists()) {
                    $refuser(__('errors.not_found'));
                }
            }],

            'objective' => ['sometimes', 'nullable', 'string', 'max:280'],

            /* Même borne que le CHECK `candidate_profiles_target_plausible` :
             * la base refuserait en 500 ce que la validation doit refuser en
             * 422, avec le nom du champ. */
            'target_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:2020-01-01'],
        ]);

        /* Par la RELATION, jamais par `CandidateProfile::updateOrCreate()`.
         * Deux raisons, et la seconde est un piège : la relation borne la
         * recherche au candidat authentifié — un profil d'autrui est
         * introuvable par construction, pas par un `where` qu'on pourrait
         * oublier — et elle pose `user_id` sur la nouvelle instance, ce qu'un
         * `firstOrNew(['user_id' => …])` ne ferait PAS, la colonne étant hors
         * de `$fillable` : la ligne partirait en base avec un `user_id` nul. */
        $profil = $request->user()->candidateProfile()->firstOrNew();

        $profil->fill([
            'exam_id' => Exam::published()->where('code', $validated['exam_code'])->value('id'),
            'objective' => $validated['objective'] ?? null,
            'target_date' => $validated['target_date'] ?? null,
        ])->save();

        return response()->json([
            'data' => CandidateProfileResource::make(
                $profil->fresh()->load('exam.track.family.audience')
            ),
        ]);
    }
}
