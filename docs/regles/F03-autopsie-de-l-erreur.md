# F03 — Autopsie de l'erreur

**Statut :** brouillon — *ne pas implémenter en l'état*
**Version :** 0.1 — 8 août 2026
**Validée par :** —
**Source de la ligne d'origine :** `NAJAH-INV-001` §8, F03 — « Identifie la
cause probable parmi 8 codes, présentée comme hypothèse »

> **Cette fiche est un exemple de la méthode**, produite pour montrer la forme
> attendue. Son contenu est un brouillon d'architecte, pas une décision produit.
> Les huit codes de cause proposés ci-dessous sont une hypothèse de travail :
> ils doivent être validés ou réécrits par un responsable pédagogique.

---

## Pourquoi cette fonction existe

Un candidat qui se trompe sait qu'il s'est trompé ; il ne sait presque jamais
**pourquoi**. Sans cause identifiée, il refait la même erreur sous un autre
habillage. Cette fonction propose une hypothèse sur la nature de l'erreur, ce
qui rend la remédiation ciblée au lieu d'être générique.

C'est la fonction qui distingue « vous avez eu faux » de « vous avez confondu
deux notions voisines ».

## Quand elle se déclenche

Après la validation d'une réponse fausse, à l'affichage de la correction.

Condition nécessaire : le distracteur choisi porte une **cause étiquetée** dans
la banque de questions. Sans étiquette, la fonction ne s'affiche pas — elle ne
devine pas.

## Ce qu'elle fait

1. Lit la cause associée au distracteur choisi, telle qu'étiquetée par l'auteur.
2. L'affiche comme **hypothèse**, jamais comme diagnostic : « Cette erreur vient
   souvent de… », pas « Vous avez… ».
3. Relie la cause à la compétence de la question.
4. Propose l'action suivante correspondante (renvoi vers F06, Ordonnance).

Codes de cause proposés — **à valider** :

| Code | Nature de l'erreur |
|---|---|
| `confusion_notions` | Deux notions voisines confondues |
| `lecture_enonce` | Énoncé mal lu : négation, quantificateur, consigne |
| `regle_mal_appliquee` | Règle connue, appliquée hors de son domaine |
| `connaissance_absente` | La notion n'est pas acquise |
| `source_perimee` | Réponse juste selon un texte abrogé |
| `calcul` | Raisonnement correct, exécution fautive |
| `piege_formulation` | Formulation conçue pour induire l'erreur |
| `indetermine` | Cause non identifiable |

## Ce qu'elle ne fait jamais

- **Elle n'affirme pas.** Le candidat peut avoir répondu au hasard, ou pour une
  raison que l'étiquette ne couvre pas. Une hypothèse présentée comme un fait
  serait fausse dans une partie mesurable des cas.
- **Elle ne produit aucun score** ni aucune probabilité.
- **Elle ne devine pas** quand l'étiquette manque : elle ne s'affiche pas.
- **Elle ne juge pas le candidat.** La cause décrit une erreur, jamais une
  personne. Aucune formulation du type « vous êtes distrait ».

## Cas limites

| Situation | Comportement attendu |
|---|---|
| Distracteur sans cause étiquetée | La fonction ne s'affiche pas. La correction reste complète par ailleurs. |
| Cause `indetermine` | Affichée telle quelle, honnêtement : la cause n'a pas été identifiée. |
| Question retirée du catalogue après la tentative | La cause reste lisible dans l'historique : elle est figée avec la tentative. |
| Premier usage, aucun historique | Aucun effet : la fonction est locale à une réponse, elle ne dépend pas de l'historique. |
| Le candidat conteste la cause | Doit pouvoir le signaler. Alimente la file éditoriale, sans exposer d'information personnelle. |

## Ce que voit le candidat

À rédiger avec un responsable pédagogique. Contrainte de ton : hypothèse et non
verdict, et jamais de jugement sur la personne.

Exemple de forme attendue :
- FR — « Cette erreur vient souvent d'une confusion entre deux notions voisines. »
- AR — « غالبا ما ينتج هذا الخطأ عن الخلط بين مفهومين متقاربين. »

## Tests d'acceptation

- [ ] Un distracteur étiqueté affiche sa cause, formulée comme hypothèse.
- [ ] Un distracteur non étiqueté n'affiche aucune cause, et la correction
      reste complète.
- [ ] Aucune formulation affirmative ni jugeante n'apparaît dans les textes.
- [ ] La cause est figée avec la tentative : modifier la question plus tard ne
      change pas l'historique du candidat.
- [ ] La contestation d'une cause crée un signalement éditorial.
- [ ] Les huit codes sont couverts en français et en arabe, sans clé brute.

## À trancher

| # | Question | Options | Conséquence |
|---|---|---|---|
| 1 | Les huit codes sont-ils les bons ? | Valider · réécrire · en réduire le nombre | Chaque code ajoute une décision à prendre pour l'auteur, à chaque distracteur |
| 2 | L'étiquetage est-il obligatoire à la publication ? | Oui, bloquant · non, facultatif | Bloquant = qualité garantie mais coût éditorial par question nettement plus élevé |
| 3 | La cause est-elle visible en compte gratuit ? | Oui · non · partiellement | Argument de conversion fort, mais c'est aussi la preuve de valeur qui fait s'inscrire |
| 4 | Les causes alimentent-elles F10 (Atlas des pièges) ? | Oui · plus tard | Si oui, prévoir l'agrégation dès maintenant |

## Dépendances

- Banque de questions avec distracteurs étiquetés par cause (pas non encore livré)
- F06 — Ordonnance Najah, pour l'action suivante
- Décision D02 (taxonomie) : la cause se rattache-t-elle à une compétence ou à
  une microcompétence ?
