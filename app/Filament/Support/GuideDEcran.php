<?php

namespace App\Filament\Support;

/**
 * CE QU'UN ÉCRAN DOIT SAVOIR DIRE DE LUI-MÊME.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * LE DÉFAUT QUE CET OBJET EXISTE POUR FERMER
 *
 * Le back-office explique ses ÉTATS et jamais ses MISSIONS. `Couverture` en est
 * l'exemple net : son état vide est soigné — il distingue « Aucun trou » de
 * « Rien à mesurer sur cette épreuve », deux phrases qui se ressemblent et ne
 * disent pas la même chose — mais son sous-titre annonce « Couples
 * (compétence, cause) attendus par des candidats et servis par moins de deux
 * questions ». C'est une DÉFINITION, écrite pour qui connaît déjà le modèle.
 *
 * Un expert qui arrive lit donc parfaitement pourquoi la page est vide, sans
 * jamais apprendre à quoi elle sert. C'est DET-76 sous une autre forme : un
 * instrument montré à quelqu'un venu agir.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * QUATRE QUESTIONS, ET PAS UNE DE PLUS
 *
 * Un guide qui déborde ne se lit pas. Chaque écran répond à quatre questions,
 * dans cet ordre — c'est celui dans lequel elles se posent :
 *
 *   0. COMMENT S'APPELLE CE QU'ON FAIT ICI — le déclencheur porte le nom de
 *      l'écran, jamais un « Aide » générique : on ne clique pas sur « Aide »,
 *      on clique sur une question qu'on se pose. Le premier jet promettait
 *      cela en commentaire et rendait toujours la même phrase ; le commentaire
 *      mentait, un audit du 28 août l'a relevé.
 *   1. À QUOI SERT CET ÉCRAN, en une phrase et sans vocabulaire de modèle.
 *   2. QUE FAIT-ON ICI, en gestes concrets.
 *   3. QUAND C'EST VIDE, ce que ça veut dire — souvent ambigu, et l'ambiguïté
 *      est justement ce qu'il faut lever.
 *   4. OÙ ALLER ENSUITE.
 *
 * Le point 3 est le plus utile et le plus oublié. Une liste vide a presque
 * toujours DEUX causes opposées — « tout est couvert » et « personne n'a
 * encore rien fait » — et les confondre conduit à ne rien faire quand il
 * fallait agir, ou l'inverse.
 *
 * Ces cas sont une LISTE et non un paragraphe : les fondre en une phrase
 * longue oblige le lecteur à démêler lui-même deux situations opposées, ce
 * qui est précisément le travail que le guide devait lui épargner.
 */
final readonly class GuideDEcran
{
    /**
     * @param  string  $titre  Le nom de l'écran, porté par le déclencheur replié.
     * @param  string  $role  À quoi sert l'écran, en une phrase.
     * @param  list<string>  $gestes  Ce qu'on y fait, un geste par ligne.
     * @param  list<string>  $quandCEstVide  Les cas d'une liste vide, un par ligne.
     * @param  list<array{libelle: string, url: string}>  $ensuite  Les portes de sortie.
     */
    public function __construct(
        public string $titre,
        public string $role,
        public array $gestes = [],
        public array $quandCEstVide = [],
        public array $ensuite = [],
    ) {}
}
