<?php

namespace App\Services;

use App\Models\CompetencyNode;
use App\Models\MasteryScore;
use App\Models\Question;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Déplacer un nœud de compétence — lot TAXO, et DET-88 close.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * LE SEUL GESTE DANGEREUX DE L'ÉCRAN
 *
 * Créer et renommer ne touchent qu'une ligne. DÉPLACER change le `path` de
 * tout un sous-arbre, et le `path` est ce par quoi la maîtrise s'agrège et
 * les séries se composent. Un déplacement à moitié écrit laisse un arbre où
 * un chapitre pend sous un domaine qu'il a quitté — et rien ne le signale,
 * parce qu'un `path` incohérent ne lève aucune erreur : il rend simplement
 * des sous-arbres faux.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * IL EST GARDÉ, JAMAIS INTERDIT
 *
 * DET-88 demandait une garde, pas une porte close : un arbre qu'on ne peut
 * pas corriger est un arbre qu'on abandonne. Ce qui est exigé, c'est que
 * l'impact soit ANNONCÉ AVANT — combien de descendants, combien de questions,
 * combien de scores suivront — et que le geste soit ATOMIQUE. On ne demande
 * pas à l'admin pédagogique de deviner ce qu'elle déplace.
 *
 * Les questions et les scores ne se réécrivent pas : ils pointent vers le
 * NŒUD, pas vers le chemin. Ils suivent donc sans qu'on y touche — c'est tout
 * l'intérêt du chemin matérialisé, et c'est pourquoi l'annonce les compte
 * plutôt que de les modifier.
 *
 * `CompetencyNode::assertNoCycle()` refuse déjà qu'un nœud devienne son propre
 * ancêtre. On ne le réécrit pas : on l'appelle, et un test le prouve.
 */
final class TaxonomieService
{
    /**
     * CE QUE LE DÉPLACEMENT VA TOUCHER — les trois nombres de DET-88.
     *
     * @return array{descendants: int, questions: int, scores: int, profondeur_apres: int}
     */
    public function impactDuDeplacement(CompetencyNode $noeud, ?CompetencyNode $nouveauParent): array
    {
        $sousArbre = $this->sousArbre($noeud);
        $identifiants = $sousArbre->pluck('id')->all();

        return [
            'descendants' => max(0, count($identifiants) - 1),
            'questions' => Question::whereIn('competency_node_id', $identifiants)->count(),
            'scores' => MasteryScore::whereIn('competency_node_id', $identifiants)->count(),
            'profondeur_apres' => ($nouveauParent?->depth ?? -1) + 1,
        ];
    }

    /**
     * Déplace un nœud et RÉÉCRIT tout son sous-arbre, dans une transaction.
     *
     * L'ordre importe : le nœud d'abord — c'est lui qui porte la garde de
     * cycle — puis ses descendants, du plus proche au plus lointain, chacun
     * recalculé depuis son parent DÉJÀ réécrit. Recalculer dans le désordre
     * ferait dériver le chemin d'un petit-enfant depuis un parent périmé.
     *
     * @return array{descendants: int, questions: int, scores: int, profondeur_apres: int}
     */
    public function deplacer(CompetencyNode $noeud, ?CompetencyNode $nouveauParent): array
    {
        $this->assertDeplacable($noeud, $nouveauParent);

        $impact = $this->impactDuDeplacement($noeud, $nouveauParent);

        DB::transaction(function () use ($noeud, $nouveauParent): void {
            /* Le sous-arbre est lu AVANT le déplacement : après, les chemins
             * du nœud ne désignent plus ses descendants. */
            $descendants = $this->sousArbre($noeud)->where('id', '!=', $noeud->id);

            $noeud->parent_id = $nouveauParent?->id;
            $noeud->save();          // `saving` recalcule depth et path du nœud

            /* Le nœud racine n'a son chemin qu'après insertion ; ici il existe
             * déjà, donc `refreshHierarchy` a posé le chemin du PARENT. On le
             * complète comme le fait la création. */
            $noeud->path = $nouveauParent === null
                ? (string) $noeud->id
                : $noeud->path.'.'.$noeud->id;
            $noeud->saveQuietly();

            foreach ($descendants->sortBy('depth') as $enfant) {
                $parent = CompetencyNode::findOrFail($enfant->parent_id);

                $enfant->depth = $parent->depth + 1;
                $enfant->path = $parent->path.'.'.$enfant->id;
                $enfant->saveQuietly();
            }
        });

        return $impact;
    }

    /**
     * Les poids d'une fratrie, et leur somme — pas 3.
     *
     * ELLE N'EST PAS FORCÉE À 100, et c'est une décision. Un arbre en cours de
     * construction a le droit d'être incomplet : forcer la somme obligerait à
     * inventer un poids pour enregistrer un nœud dont on ne connaît pas encore
     * la part. ADR-0032 range donc l'écart du côté de l'AVERTISSEMENT.
     *
     * Mais l'écart est DIT. Un total de 85 % qui ne se voit nulle part devient
     * un arbre faux que personne ne rattrape.
     *
     * @return array{total: float, ecart: float, complete: bool, sans_poids: int}
     */
    public function sommeDeLaFratrie(CompetencyNode $noeud): array
    {
        $fratrie = CompetencyNode::query()
            ->where('exam_id', $noeud->exam_id)
            ->where(fn ($q) => $noeud->parent_id === null
                ? $q->whereNull('parent_id')
                : $q->where('parent_id', $noeud->parent_id))
            ->get();

        $total = round((float) $fratrie->sum('weight_percent'), 2);

        return [
            'total' => $total,
            'ecart' => round($total - 100, 2),
            'complete' => abs($total - 100) < 0.01,
            'sans_poids' => $fratrie->whereNull('weight_percent')->count(),
        ];
    }

    /**
     * Les refus qui précèdent le geste, dits en clair.
     *
     * Le cycle est laissé au modèle : `assertNoCycle` le tient déjà pour tous
     * les chemins d'écriture, et le doubler ici créerait une seconde règle qui
     * dériverait de la première. On refuse en revanche ce que le modèle ne
     * regarde pas — un parent d'une AUTRE épreuve, qui produirait un nœud
     * rattaché à deux matrices à la fois.
     */
    private function assertDeplacable(CompetencyNode $noeud, ?CompetencyNode $nouveauParent): void
    {
        if ($nouveauParent === null) {
            return;
        }

        if ($nouveauParent->id === $noeud->id) {
            throw ValidationException::withMessages([
                'parent_id' => 'Un nœud ne devient pas son propre parent.',
            ]);
        }

        if ($nouveauParent->exam_id !== $noeud->exam_id) {
            throw ValidationException::withMessages([
                'parent_id' => 'Le nœud d’accueil appartient à une autre épreuve. '
                    .'Chaque épreuve a sa matrice (ADR-0014) : un nœud n’en sert jamais deux.',
            ]);
        }
    }

    /**
     * Le nœud et tous ses descendants, en une requête.
     *
     * @return Collection<int, CompetencyNode>
     */
    private function sousArbre(CompetencyNode $noeud)
    {
        return CompetencyNode::query()
            ->where(fn ($q) => $q->whereKey($noeud->getKey())
                ->orWhere(fn ($sous) => $sous->descendantsOf($noeud)))
            ->orderBy('depth')
            ->get();
    }
}
