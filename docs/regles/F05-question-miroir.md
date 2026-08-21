# F05 — La question miroir

**Statut :** **brouillon — reconstruite depuis le code livré**, jamais validée
**Version :** 0.1 — 21 août 2026
**Validée par :** — *(en attente d'arbitrage OptimGov)*
**Source de la ligne d'origine :** `NAJAH-INV-001` §8, F05
**Dépend de :** [[F03]] (validée), [[F04]], ADR-0015

> **Avertissement de méthode.** Fiche écrite après le code. Voir [[F01]].

---

## Pourquoi cette fonction existe

Lire une explication et l'avoir comprise sont deux choses différentes, et le
candidat lui-même ne sait pas les distinguer. La question miroir **tend le même
piège sous un autre énoncé** : si le candidat retombe dedans, l'explication n'a
pas pris.

C'est la seule fonction du produit qui vérifie un **transfert**, et non une
mémorisation.

## Quand elle se déclenche

**À la demande du candidat, jamais d'office.** La correction n'annonce que
l'**existence** d'un miroir ; elle ne le sert pas.

Condition nécessaire : le candidat a choisi un distracteur portant une cause
étiquetée. Sans erreur causée, il n'y a rien à vérifier.

## Ce qu'elle fait

1. **Elle cherche une question sœur** — une autre question de la **même
   compétence** portant la **même cause** sur l'un de ses distracteurs. Le vivier
   est indexé par le couple (compétence, cause), et ce sélecteur est **partagé**
   avec les rendez-vous mémoire : « quelles questions portent ce piège » n'a
   qu'une bonne réponse, et trois définitions du même concept divergeraient.

2. **Elle refuse de resservir le même énoncé.** C'est la divergence délibérée
   avec [[F07]] : une révision ressert l'énoncé déjà vu faute de mieux, parce que
   sauter une échéance serait pire ; le miroir **refuse**, parce que sa raison
   d'être est de changer d'énoncé. Le resservir ne vérifierait rien.

3. **Elle ouvre une tentative d'une seule question**, comptée dans le parcours
   comme les autres.

4. **Elle n'autorise qu'un seul miroir ouvert à la fois.** Une demande alors
   qu'un miroir est ouvert sur un autre item est refusée et **renvoie vers le
   miroir ouvert** — plutôt que de servir une question sans rapport sous la cause
   de la nouvelle demande.

5. **Elle ne débite pas le quota général de questions.** Le miroir est un geste
   de vérification après une erreur déjà rencontrée, pas une voie d'entraînement
   libre. Le serveur borne néanmoins le nombre de miroirs par compte et par
   couple `(compétence, cause)`, interdit l'énumération du vivier et limite le
   débit des ouvertures. La valeur du plafond et sa fenêtre restent à spécifier
   avant implémentation.

6. **Elle distingue trois refus, et les nomme :**

   | Refus | Ce qu'il dit | Ce que le candidat peut faire |
   |---|---|---|
   | `MIRROR_NOT_APPLICABLE` | Rien à vérifier ici — pas d'erreur causée | Rien, et c'est normal |
   | `MIRROR_NOT_AVAILABLE` | La **banque** n'a pas d'autre énoncé pour ce piège | Rien de son côté — le couple entre au plan de rédaction |
   | `MIRROR_ALREADY_OPEN` | Un miroir est déjà ouvert ailleurs | Aller le terminer |

   La distinction n'est pas cosmétique : le deuxième cas n'est **pas la faute du
   candidat**, et le lui présenter comme un refus serait faux.

## Ce qu'elle ne fait jamais

- **Elle ne se sert jamais d'office.** Le candidat décide de se tester.
- **Elle ne ressert jamais l'énoncé déjà vu.** Un miroir qui répète la question
  ne mesure rien.
- **Elle ne révèle pas la cause qu'elle vérifie.** Annoncer « voici la même
  erreur » avant la réponse détruirait la mesure.
- **Elle ne produit aucun verdict sur le candidat.** Retomber dans le piège
  n'est pas un échec : c'est l'information qui justifie un rendez-vous mémoire.
- **Elle n'est pas un quota déguisé.** Son exemption du quota général est
  compensée par une borne métier propre et des protections anti-aspiration côté
  serveur.

## Cas limites

| Situation | Comportement |
|---|---|
| **Aucune question sœur dans la banque** | Refus explicite et nommé. Le couple apparaît au plan de rédaction, ordonné par le nombre de candidats en attente. |
| **Un miroir est déjà ouvert** | Refus, avec l'identifiant de la tentative ouverte — l'interface y mène plutôt que d'annoncer l'indisponibilité. |
| **Double clic / rejeu** | Neutralisé par une clé d'idempotence. La même clé rend la même tentative, elle n'en ouvre pas deux. |
| **Réseau coupé** pendant l'ouverture | La clé d'idempotence protège : rejouer la demande ne crée pas de doublon. |
| **Question sœur retirée** entre la demande et la réponse | *À trancher — point 3.* |
| **Tout premier usage** | Impossible : le miroir suppose une correction, donc une tentative close. |

## Ce que voit le candidat

Dans la correction : une **annonce d'existence**, formulée comme une
proposition — « vérifier sur une autre question : le même piège, un autre
énoncé ».

⚠️ **Défaut documenté à ne pas reproduire :** cet élément a longtemps eu
l'apparence d'un lien sans en être un. La règle des portes, clause 3, s'applique
intégralement — **tout élément qui a l'apparence d'un lien EST un lien.**

**Formulation exacte :** *à trancher.*

## Tests d'acceptation

- [ ] Le miroir n'est jamais servi sans demande explicite.
- [ ] La question servie est **différente** de celle qui a produit l'erreur.
- [ ] Elle porte la **même** compétence et la **même** cause sur un distracteur.
- [ ] Sans question sœur → refus `MIRROR_NOT_AVAILABLE`, distinct de
      `MIRROR_NOT_APPLICABLE`.
- [ ] Un second miroir demandé alors qu'un est ouvert → refus avec l'identifiant
      du miroir ouvert.
- [ ] Même clé d'idempotence rejouée → même tentative, pas une seconde.
- [ ] Un miroir servi ne débite pas le quota général de questions.
- [ ] Le plafond par compte et couple est tenu côté serveur ; au-delà, aucun
      nouvel énoncé ni identifiant de sœur n'est divulgué.
- [ ] Dans le rendu de la correction, l'annonce du miroir est une **ancre ou un
      bouton** — pas un `span` coloré.
- [ ] **Mutation :** on autorise le resservi du même énoncé → le deuxième test
      rougit, et lui seul.

## À trancher

| # | Question | Options | Conséquence du choix |
|---|---|---|---|
| 1 | **Quelle borne propre protège le miroir ?** | Plafond par compte et couple, avec fenêtre éventuelle et limitation de débit | Le principe est tranché : aucun débit du quota général. La valeur doit empêcher l'aspiration sans rendre la vérification inutilisable. |
| 2 | **Question sœur retirée entre l'ouverture et la réponse** | (a) la tentative continue · (b) elle est annulée | (a) fait répondre à une question jugée mauvaise ; (b) fait disparaître une tentative sous les yeux du candidat. |
| 3 | **Formulation exacte, FR et AR** | — | Décision produit. |

## Dépendances

[[F03]] — validée. [[F04]] (l'explication dont le miroir vérifie la prise),
[[F07]] (avec qui le vivier est partagé et la politique de repli **diverge
délibérément**), ADR-0015.
