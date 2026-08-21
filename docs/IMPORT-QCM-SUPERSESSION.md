# Supersession du modèle d'import QCM

**Établi le 22 août 2026.** La zone de préparation livrée au lot Q2.1 —
migration `0001_01_01_000580_creer_la_zone_de_preparation_des_questions`, table
`prepared_questions` — **remplace** la méthode d'exploitation décrite au §3 des
instructions d'import, matérialisée par `Questions/schema-qcm.sql` : tables
`question`, `sujet`, `domaine`, fonction `controle_publication()`, déclencheur
`trg_garde_validation` et vues de pilotage.

Ce document ne réécrit pas ce schéma et n'en propose pas la migration. Il dit
**où chacun de ses objets vit désormais**, et ce qui, à ce jour, n'a pas
d'équivalent — parce qu'un contrôle perdu en silence est le seul vrai risque de
cette supersession.

## Où vivent les objets du schéma remplacé

| Objet du schéma remplacé | Où il vit désormais |
|---|---|
| Les contrôles de `controle_publication()` | Les portes de publication de la banque : `QuestionIntegrityChecker` (`structuralIssues`, `diagnosticIssues`, `publicationIssues`) et la règle des quatre yeux. Correspondance contrôle par contrôle ci-dessous. |
| `v_file_saisie`, `v_avancement` | L'écran de file de la zone de préparation et l'indicateur de résorption (invariant I-5). |
| `v_poids_observes` | Se mesurera **sur la banque publiée**, jamais sur la zone (invariant I-4). |
| L'état `valide` de la machine `a_saisir → saisi → valide` | La chaîne éditoriale de la banque, après transfert en brouillon. Une seule validation, quatre yeux tenus par le service. |

## Correspondance contrôle par contrôle

`controle_publication()` refuse une validation en accumulant des motifs. Elle en
émet **onze**, portés par huit instructions de contrôle — le décompte de « dix »
retenu par la mission ne correspond ni aux messages ni aux instructions ; les
onze sont donc listés ici, sans regroupement, pour qu'aucun ne puisse disparaître
dans un écart de comptage.

| # | Refus de `controle_publication()` | Équivalent dans la banque | État |
|---:|---|---|---|
| 1 | `aucune bonne réponse` | `structuralIssues` : « Une question doit avoir exactement une bonne réponse » | **Couvert, et renforcé** — le schéma exigeait au moins une, la banque en exige exactement une |
| 2 | `justification manquante pour X` | `structuralIssues` : « L'option N n'a pas de justification » | **Couvert** — sur toutes les options, pas seulement celles servies |
| 3 | `justification de X trop courte` (< 15 mots) | — | **Absent.** Aucune longueur minimale de justification n'est contrôlée |
| 4 | `justification de X trop longue` (> 60 mots) | — | **Absent.** Aucune longueur maximale n'est contrôlée |
| 5 | `la justification de la bonne réponse doit commencer par « Exact. »` | — | **Absent.** Aucune convention de forme sur la justification de la bonne réponse |
| 6 | `piège non renseigné` | Partiel : `diagnosticIssues` exige une **cause d'erreur** sur chaque distracteur (fiche F03), dont `piege_formulation` est l'un des codes | **Partiel, et d'une autre nature** — la banque étiquette la cause de l'erreur, elle ne demande pas un texte décrivant le piège ; et l'exigence ne porte que sur les questions éligibles au diagnostic |
| 7 | `source absente` | `publicationIssues` : source de contenu **vérifiée** exigée | **Partiel, délibérément** — exigé pour le diagnostic et la simulation, pas pour l'entraînement libre, dont la source peut rester à confirmer si elle est signalée |
| 8 | `source non localisée` | `question_sources.locator` existe (page, article, chapitre) mais reste nullable | **Absent en tant que garde** — le champ existe, aucune porte ne l'exige |
| 9 | `aucun domaine de compétence` | `structuralIssues` : rattachement à un nœud, au niveau minimal exigé par le profil de taxonomie de l'épreuve, et refus d'un nœud appartenant à une autre épreuve | **Couvert, et renforcé** |
| 10 | `aucun niveau cognitif` | `Question.cognitive_level` existe | **Absent en tant que garde** — le champ existe, aucune porte ne l'exige à la publication |
| 11 | `aucun valideur signé` | `publicationIssues` : valideur enregistré, statut validé pédagogiquement, **et le valideur n'est jamais l'auteur** | **Couvert, et renforcé** — les quatre yeux ne figuraient pas dans le schéma remplacé |

### Ce qui manque, consigné et non comblé

Cinq refus du schéma remplacé n'ont pas d'équivalent opposable dans la banque :
la longueur minimale (3) et maximale (4) des justifications, la convention
« Exact. » (5), la localisation de la source (8) et le niveau cognitif (10). Le
piège (6) n'est couvert que sous une autre forme et pour le seul diagnostic.

Ces manques ne sont **pas comblés ici** : ajouter une garde de publication est
un pas de code, avec ses tests et son arbitrage — trois d'entre eux (les
longueurs, la convention de forme) sont d'ailleurs des **valeurs de calibrage**
au sens de l'ADR-0032, donc des paramètres bornés à poser dans le registre
pédagogique, et non des constantes à figer dans un validateur. Les inscrire ici
sert exactement à cela : qu'on ne puisse pas dire, dans six mois, que la
supersession a été indolore.

## Ce que la supersession ne change pas

- Aucune bonne réponse n'est inventée. Le corpus d'origine n'en contient pas, et
  la zone de préparation conserve les **suggestions** distinctes des réponses
  établies.
- Les poids observés se mesurent sur la banque publiée, jamais sur la zone.
- La zone de préparation n'est pas une seconde banque : rien n'y est servi à un
  candidat, et le transfert vers `questions` reste un geste explicite.
