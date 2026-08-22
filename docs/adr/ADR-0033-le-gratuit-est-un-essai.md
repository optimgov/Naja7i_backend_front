# ADR-0033 — Le gratuit est un essai, clos au premier paiement

**Statut :** accepté · décision propriétaire ferme du 22 août 2026
**Dépend de :** ADR-0025, ADR-0026, ADR-0027, ADR-0029
**Remplace :** la composition gratuit/payant — AR-2 règles 2 et 3 cessent de
s'appliquer entre catégories

## Problème

Le palier gratuit livré au lot 3A.7 est un droit comme un autre : sans terme,
avec son enveloppe. Rien ne le distingue d'un droit acheté aux yeux de la
résolution, et c'est précisément là que le modèle se défait.

`DatabaseAccessGrant::allows()` est un `exists()` sur les octrois **actifs** :
aucune notion de priorité, de forfait courant ni de catégorie. N'importe quel
octroi actif portant la capacité suffit. Un droit d'essai non clos continuerait
donc d'ouvrir `questions.answer` sous un abonnement payant — et la consommation
(3B) devrait choisir laquelle des deux enveloppes débiter, question à laquelle
aucune règle ne répond sans en inventer une.

Le produit, lui, a une réponse simple : **le gratuit n'est pas un palier, c'est
un essai.** Il sert à découvrir et à provoquer le premier abonnement. Une fois
le premier forfait payé, il n'a plus d'objet.

## Décision

> **Le droit d'essai et un droit payant ne coexistent jamais. La première
> activation d'un forfait payant clôt l'essai définitivement, dans la
> transaction qui ouvre le forfait. Aucun chemin ne recrée l'éligibilité.**

Les dix règles du cycle, telles que le propriétaire les a arrêtées :

1. À l'inscription, le candidat reçoit un accès d'essai minimal et limité.
2. L'essai sert à découvrir le produit et à provoquer le premier abonnement.
3. À la première activation d'un forfait payant, l'essai est **définitivement
   clos**.
4. Le forfait commence avec ses capacités, sa durée et son enveloppe propres,
   **entièrement neuves**.
5. **Aucun reliquat** d'essai n'est transféré ni additionné au forfait.
6. À l'expiration ou à l'épuisement du forfait, le candidat **ne revient jamais
   à l'essai**.
7. Il renouvelle, achète un autre forfait, ou change de forfait.
8. Le compte reste accessible — « Mon dossier », historique, justificatifs,
   catalogue — mais les fonctions soumises à droit sont fermées.
9. L'essai clos **reste conservé** comme trace d'audit ; il n'est pas supprimé.
10. **Aucun rejeu** — renouvellement, changement de forfait, nouvelle
    attribution technique — ne recrée l'éligibilité d'un compte déjà converti.

## Le modèle d'état — déduit des droits, jamais stocké

Une colonne d'état serait une seconde source de vérité, et divergerait au
premier `ends_at` écrit ailleurs. L'état **se lit** :

| État | Se lit ainsi | Ce qui est ouvert |
|---|---|---|
| `essai` | un octroi d'essai actif, aucun octroi payant actif | l'essai : les capacités et l'enveloppe de sa version |
| `actif` | au moins un octroi payant actif | ce que le forfait vend |
| `epuise` | une conversion a eu lieu, aucun octroi actif | « Mon dossier », historique, catalogue — les fonctions à droit sont fermées |

| Transition | Déclencheur | Réversible ? |
|---|---|---|
| *(néant)* → `essai` | inscription, **si jamais converti et jamais servi** | — |
| `essai` → `actif` | commande payante honorée (achat réel ou coupon) | **non** |
| `actif` → `epuise` | fin de durée ou enveloppe à zéro | — |
| `epuise` → `actif` | nouvel achat | — |
| **`*` → `essai` après conversion** | **aucun** | **interdit** |

L'interdiction est tenue à **trois niveaux** : la ligne clôturée n'est plus
active ; la garde d'attribution lit l'**historique**, pas les droits actifs ;
un test nommé garde la garantie contre une « optimisation » qui ajouterait
`->active()` à cette garde.

## La règle transactionnelle de première conversion

Point d'insertion : `AbonnementService::honorer()`, après le verrou de commande
et la lecture de la version, **avant** l'octroi.

1. verrouiller la commande *(existant)* ;
2. lire la version payante *(existant)* ;
3. **clore l'essai** : `ends_at = now()` sur les octrois d'essai encore actifs,
   avec la trace « converti par la commande X » ;
4. `octroyerLesDroits()` ;
5. commit.

| Exigence | Comment elle est tenue |
|---|---|
| **Atomicité** | Même transaction : si l'octroi échoue, la clôture est annulée par le rollback |
| **Aucune fenêtre sans droit** | Conséquence directe de la précédente |
| **Rejeu idempotent** | La commande honorée sort en tête ; clore un essai déjà clos ne fait rien |
| **Concurrence** | Verrou de commande, et index unique `(compte, capacité, référence)` sur l'octroi |
| **Preuve durable** | Deux faits durables, jamais un droit actif : la **commande honorée** dont la méthode convertit, et la **ligne d'essai close** qui porte la référence de cette commande |

**« Payante » se lit sur la MÉTHODE, pas sur le montant.** Un coupon convertit —
c'est l'activation manuelle d'un forfait payé hors ligne (décision D-C). Le
paiement simulé ne convertit pas : il n'existe pas en production, et le laisser
clore un essai ferait perdre en recette ce qu'aucun candidat n'a acheté. Un
montant nul n'est pas un critère : une offre à zéro peut être un forfait réel,
et un futur octroi d'expert ne passera pas par une commande commerciale.

## Ce que cet ADR remplace, et ce qu'il n'invente pas

**Remplace.** AR-2 règles 2 (« l'illimité gagne ») et 3 (« les reliquats
survivent ») **cessent de s'appliquer entre catégories** : elles restent
valables entre droits payants successifs, où la succession du lot 3A reste
inchangée (décision D-U). L'invariant 2 de l'ADR-0029 — « un droit sans terme
n'est jamais raccourci » — reçoit **une exception nommée** : le droit d'essai,
et seulement par conversion.

**N'invente pas.** Ni colonne d'état, ni drapeau `converted_at`, ni mécanisme de
clôture nouveau. La clôture est un `ends_at`, comme toute révocation depuis le
PAS-8 ; la preuve est la ligne close elle-même.

**Exclut.** Toute réactivation, tout repli, toute addition de reliquat, tout
« gratuit résiduel » sous un forfait, toute seconde attribution.

## Le droit transitoire, sous cette règle

Livré au lot 3A.8, il reste en place et trois règles le cadrent :

1. **Il ne convertit pas.** Ce n'est pas un paiement : il n'ouvre aucune
   commande et ne clôt pas l'essai. Un compte transitoire garde son essai
   dessous, intact, et le retrouve à l'échéance.
2. **Il n'est pas posé en v1.** Sa pose appartient à l'allumage, qui passe après
   la consommation et sur ordre explicite. Livré, testé, dormant.
3. **Il ne se pose jamais sur un compte converti** — qui a payé n'a pas besoin
   d'un cadeau de transition, et le lui donner masquerait son forfait.

## Tests d'acceptation

- **S-01** — essai consommé puis achat : l'essai est clos, le forfait ouvre son
  enveloppe neuve ; à l'expiration, l'état est `epuise`, **jamais un reliquat
  d'essai retrouvé**.
- **S-17** — atomicité : l'octroi payant échoue, l'essai reste actif, aucune
  fenêtre sans droit.
- **S-18** — non-réattribution : converti puis expiré, l'attribution rejouée par
  l'inscription **et** par le rattrapage ne crée aucun essai neuf.
- Une commande **coupon** honorée convertit ; une commande **simulée** ne
  convertit pas.
- Deux validations concurrentes de la même commande : une seule clôture, une
  seule série d'octrois.

### Tests de mutation

- On sort la clôture de la transaction → S-17 rougit, et lui seul.
- On ajoute `->active()` à la garde de non-réattribution → S-18 rougit, et lui
  seul.
- On fait lire « payante » sur le montant au lieu de la méthode → le test
  coupon/simulé rougit.
