# F04 — « Pourquoi pas B ? » — la correction par élimination

**Statut :** **brouillon — reconstruite depuis le code livré**, jamais validée
**Version :** 0.1 — 21 août 2026
**Validée par :** — *(en attente d'arbitrage OptimGov)*
**Source de la ligne d'origine :** `NAJAH-INV-001` §8, F04
**Dépend de :** [[F03]] (validée), ADR-0015 (banque de questions)

> **Avertissement de méthode.** Fiche écrite après le code. Voir [[F01]].

---

## Pourquoi cette fonction existe

Savoir que la bonne réponse était C n'apprend presque rien. Ce qui apprend, c'est
de comprendre **pourquoi B était tentante** — parce que c'est cette tentation-là
qui reviendra le jour du concours, sous un autre énoncé.

F04 est la contrepartie éditoriale de cette promesse : **chaque option porte sa
justification**, la bonne comme les mauvaises.

## Quand elle se déclenche

À l'affichage de la correction, **après soumission uniquement**. Jamais pendant
la passation — la passation ne connaît jamais la correction, et c'est tenu à
deux endroits.

## Ce qu'elle fait

1. **Elle rend la justification de chaque option**, la bonne et toutes les
   mauvaises. C'est le contenu éditorial de la question.

2. **Cette justification est gratuite, par conception.** Elle n'est jamais
   soumise au quota de [[F03]]. La retirer aux comptes gratuits ferait de la
   correction un QCM ordinaire — et c'est exactement ce que le produit refuse
   d'être.

3. **Elle sépare strictement deux objets** qui se ressemblent et ne sont pas de
   même nature :

   | Objet | Ce que c'est | Qui y a droit |
   |---|---|---|
   | **Justification** (F04) | Pourquoi cette option est juste ou fausse — un fait éditorial sur la question | Tout le monde, toujours |
   | **Cause** (F03) | Une hypothèse sur **ce que le candidat a fait** | Soumise au quota |

4. **La cause n'est rendue que pour le distracteur effectivement choisi.**
   La rendre sur toutes les options la viderait de son sens : une cause porte
   sur un geste du candidat ; sur une option qu'il n'a pas prise, elle ne
   diagnostique rien — elle expose le travail d'étiquetage.

## Ce qu'elle ne fait jamais

- **Elle ne publie jamais une question dont un distracteur n'est pas justifié.**
  C'est une règle permanente (`METHODE.md` §7.5), tenue à la publication.
- **Elle ne fuit jamais pendant la passation.** Aucune justification, aucune
  bonne réponse, aucun `is_correct` ne sort avant la soumission — la ressource
  de passation est une liste blanche stricte.
- **Elle ne juge pas la personne.** Une justification porte sur l'option, jamais
  sur celui qui l'a choisie.
- **Elle ne se substitue pas à la cause**, et réciproquement. Les deux
  coexistent, avec des droits différents.

## Cas limites

| Situation | Comportement |
|---|---|
| **Question sautée** | La correction s'affiche avec toutes les justifications ; aucune cause, puisque aucun distracteur n'a été choisi. |
| **Bonne réponse du premier coup** | Les justifications des distracteurs restent affichées — c'est là qu'un candidat qui a bien répondu apprend le plus. |
| **Quota de causes épuisé** | La justification reste **entière** ; seule la cause est remplacée par une invitation. |
| **Question amendée** après la réponse | *À trancher — point 2.* |
| **Tout premier usage** | Aucun historique nécessaire. |

## Ce que voit le candidat

Chaque option, avec son statut (juste / fausse) et sa justification. La cause,
quand elle est due, apparaît **sur la seule option qu'il a choisie**, et se
présente comme une **hypothèse** — jamais comme un verdict sur la personne.

**Formulation exacte :** *à trancher.* Une réserve documentée subsiste : la
recette du 17 août a relevé que **le mot « hypothèse » n'apparaît nulle part**
sur les causes, alors que la fiche F03 impose ce ton. À vérifier à l'écran et à
corriger si le constat tient toujours.

## Tests d'acceptation

- [ ] La correction rend une justification pour **chaque** option.
- [ ] Les justifications sont rendues même quand le quota de causes est épuisé.
- [ ] La cause n'est rendue que sur le distracteur choisi — sur aucune autre
      option.
- [ ] Aucune justification, aucun `is_correct`, aucune bonne réponse ne sort
      pendant la passation.
- [ ] Une question dont un distracteur n'a pas de justification ne peut pas être
      publiée, et le refus dit lequel.
- [ ] **Mutation :** on rend la cause sur toutes les options → le troisième test
      rougit, et lui seul.

## À trancher

| # | Question | Options | Conséquence du choix |
|---|---|---|---|
| 1 | **Le mot « hypothèse » doit-il apparaître littéralement ?** | (a) oui, dans le libellé · (b) le ton suffit | F03 impose « hypothèse et non verdict ». La recette dit que le mot est absent. (a) est vérifiable mécaniquement, (b) ne l'est pas. |
| 2 | **Une question amendée après réponse : quelle version voit le candidat en correction ?** | (a) celle qu'il a vue · (b) la version courante | (b) peut afficher une justification qui ne correspond pas à l'énoncé auquel il a répondu. |
| 3 | **Formulation exacte, FR et AR** | — | Décision produit. |
| 4 | **Le nom même de la fonction** | « Pourquoi pas B ? » vient de l'inventaire | Un libellé d'interface qui nomme une option par sa lettre vieillit mal avec 4 **ou 5** options selon l'épreuve (A-09). |

## Dépendances

[[F03]] — **validée**, et c'est la seule dont F04 dépende de façon
contraignante. [[F05]] (le miroir vérifie que cette explication a pris),
ADR-0015.
