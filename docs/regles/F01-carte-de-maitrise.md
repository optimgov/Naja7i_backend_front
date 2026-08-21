# F01 — Carte de maîtrise

**Statut :** **brouillon — reconstruite depuis le code livré**, jamais validée
**Version :** 0.1 — 21 août 2026
**Validée par :** — *(en attente d'arbitrage OptimGov)*
**Source de la ligne d'origine :** `NAJAH-INV-001` §8, F01
**Dépend de :** ADR-0017 (maîtrise), ADR-0012 et ADR-0014 (taxonomie), [[F02]]

> **Avertissement de méthode.** Cette fiche est écrite **après** le code, ce que
> `METHODE.md` §3 proscrit. Elle ne décrit donc pas une intention : elle décrit
> ce que le code fait aujourd'hui, pour que ce comportement puisse enfin être
> jugé — et corrigé s'il diverge de l'intention. Tout ce dont je ne suis pas
> certain figure en « À trancher », jamais dissous dans le corps du texte.

---

## Pourquoi cette fonction existe

Un candidat qui révise sans carte révise ce qu'il aime. La carte de maîtrise lui
dit **où il en est, domaine par domaine**, sur la taxonomie officielle de son
épreuve — pas sur une liste inventée par la plateforme.

Elle n'est pas là pour le rassurer ni pour le noter : elle est là pour rendre
visible l'écart entre ce qu'il croit savoir et ce qu'il a démontré.

## Quand elle se déclenche

À la demande, sur une épreuve donnée. Le recalcul, lui, se déclenche **à la
soumission de toute tentative close** — révision, entraînement ou diagnostic.

Le calcul porte sur les nœuds où des questions ont été **servies**, répondues ou
non. Un nœud entre dans la carte dès qu'une tentative close lui a présenté une
question, même si le candidat n'en a répondu aucune.

## Ce qu'elle fait

1. **Elle pondère chaque réponse par la certitude déclarée** — voir [[F02]] :

   | Réponse | Certitude | Poids |
   |---|---|---:|
   | Juste | sûr | 1,00 |
   | Juste | hésitant | 0,85 |
   | Juste | **hasard** | **0,35** |
   | Fausse | quelle qu'elle soit | 0,00 |

2. **Elle calcule un score par nœud feuille**, puis **agrège vers les parents**
   selon la taxonomie de l'épreuve.

3. **Elle refuse d'afficher un score sans évidence suffisante.** Trois niveaux :
   insuffisant (moins de 5 réponses), faible (5 à 9), suffisant (10 ou plus).
   En dessous du seuil, **le score reste nul** et l'interface indique combien de
   réponses manquent. La contrainte est **en base**, pas seulement dans le
   service — un service se contourne, une contrainte non.

4. **Elle rend, pour chaque nœud** : le score s'il est affichable, le niveau
   d'évidence, le nombre de réponses, le nombre de réponses manquantes, le
   nombre de questions **sautées**, les réussites au hasard et les erreurs
   commises avec certitude.

## Ce qu'elle ne fait jamais

- **Aucune prédiction de réussite au concours**, sous aucun nom, même dérivée
  (`METHODE.md` §7.3). Elle mesure ce qui a été observé.
- **Aucun score sans son volume d'évidence.** Les deux sortent ensemble ou ne
  sortent pas — c'est structurel dans `MasteryScore::toPublicArray`, pas une
  convention d'affichage.
- **Elle ne confond pas « n'a pas répondu » et « a répondu faux ».** Les
  questions sautées sont comptées à part, et ce compte est indépendant du
  nombre de réponses manquantes : un candidat peut sauter la moitié d'un domaine
  avec zéro réponse manquante.
- **Elle ne classe pas les candidats entre eux.** Aucun rang, aucun percentile,
  aucune comparaison.

## Cas limites

| Situation | Comportement |
|---|---|
| **Tout premier usage**, aucune tentative | Aucun nœud n'a de score. L'écran porte la porte qui le remplit — un lien vers le diagnostic (règle des portes, clause 1). |
| **Moins de 5 réponses sur un nœud** | Score nul, évidence « insuffisante », et le nombre de réponses manquantes est affiché. |
| **Domaine servi mais entièrement sauté** | Le nœud existe dans la carte, avec son compte de sautées. Il n'est pas traité comme jamais servi — c'est un refus, pas un angle mort. |
| **Question retirée du catalogue** après avoir été répondue | *À trancher — voir §À trancher, point 3.* |
| **Réseau coupé pendant la consultation** | Lecture seule : rien n'est perdu. La donnée disparaît de l'écran, elle ne vaut pas zéro. |

## Ce que voit le candidat

Une carte par épreuve, structurée selon les niveaux de la taxonomie de cette
épreuve — et **les niveaux portent leurs noms officiels**, pas des noms
génériques.

**Formulation exacte :** *à trancher.* Les libellés actuels viennent des
fichiers de traduction du frontend et n'ont jamais été arbitrés comme
formulations produit. Ce qui est **acquis** en revanche :

- aucun code d'énumération brut à l'écran ;
- tout nombre porte l'espace fine insécable, sans quoi il se lit à l'envers en
  arabe ;
- l'absence de score se dit — elle ne s'affiche pas comme un zéro.

## Tests d'acceptation

- [ ] Deux candidats, mêmes réponses justes, certitudes différentes → scores
      différents.
- [ ] Quatre réponses sur un nœud → score nul, évidence « insuffisante », et le
      nombre manquant est exact.
- [ ] Cinquième réponse → le score apparaît.
- [ ] Un nœud servi et entièrement sauté apparaît, avec son compte de sautées.
- [ ] Aucune sortie de l'API ne contient de probabilité, de pronostic ou de
      pourcentage de chances, sous quelque nom que ce soit.
- [ ] **Mutation :** on retire la contrainte de base sur l'évidence → le
      deuxième test rougit, et lui seul.

## À trancher

| # | Question | Options | Conséquence du choix |
|---|---|---|---|
| 1 | **Le seuil d'évidence (5 / 10) est-il un paramètre pédagogique ?** | (a) constante · (b) paramètre borné, réglé par l'admin pédagogique | (b) est cohérent avec A-10, mais un seuil trop bas ferait afficher des scores fondés sur trop peu — la borne basse doit être justifiée pédagogiquement, pas techniquement. |
| 2 | **Le poids 0,35 d'une réussite au hasard** | Valeur d'architecte (DET-19), jamais réétalonnée sur données réelles | Change tous les scores existants le jour où on la modifie. Faut-il recalculer le passé, ou ne l'appliquer qu'en avant ? |
| 3 | **Une question retirée du catalogue reste-t-elle dans le calcul ?** | (a) oui, la réponse a eu lieu · (b) non, le contenu n'est plus valide | (a) préserve l'historique mais peut fonder un score sur une question jugée mauvaise. (b) fait varier un score sans que le candidat n'ait rien fait. |
| 4 | **Formulation exacte des libellés, FR et AR** | — | Décision produit visible du candidat : elle ne se prend pas en session. |

## Dépendances

[[F02]] (la certitude, dont la pondération est le cœur du calcul),
[[F06]] (l'ordonnance consomme cette carte), ADR-0017, ADR-0014.
**Une fiche qui dépend d'une fiche non validée ne peut pas être validée non
plus** — F01 et F02 se valident donc ensemble.
