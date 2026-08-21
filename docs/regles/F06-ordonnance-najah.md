# F06 — Ordonnance Najah

**Statut :** **brouillon — reconstruite depuis le code livré**, jamais validée
**Version :** 0.1 — 21 août 2026
**Validée par :** — *(en attente d'arbitrage OptimGov)*
**Source de la ligne d'origine :** `NAJAH-INV-001` §8, F06
**Dépend de :** [[F01]], [[F02]], ADR-0017 §5, ADR-0014 (poids officiels)

> **Avertissement de méthode.** Fiche écrite après le code. Voir [[F01]].

---

## Pourquoi cette fonction existe

Un candidat qui voit sa carte de maîtrise sait où il est faible. Il ne sait
toujours pas **par quoi commencer** — et le temps qui lui reste est la ressource
la plus rare de sa préparation.

L'ordonnance ordonne. Elle dit quoi faire **ensuite**, dans un ordre qui tient
compte de ce que l'épreuve pèse réellement, et pas seulement de ce qui est le
plus bas.

## Quand elle se déclenche

À la demande, sur une épreuve, avec un nombre de lignes demandé. Elle se
recalcule à chaque consultation, à partir de la carte de maîtrise courante.

## Ce qu'elle fait

Elle classe les domaines en croisant **trois facteurs, dans cet ordre** :

1. **Le poids officiel du domaine dans l'épreuve.** Travailler un domaine à 5 %
   avant un domaine à 40 % est un mauvais emploi du temps, même si le score y
   est plus bas. Le poids vient du référentiel, pas d'une estimation.

2. **L'écart de maîtrise** — ce qui manque, pas ce qui est acquis.

3. **La nature des erreurs**, qui module l'urgence :

   | Signal | Facteur | Pourquoi |
   |---|---:|---|
   | Erreur commise avec **certitude** | ×2,0 | Le candidat ne sait pas qu'il ne sait pas — il ne réviserait jamais ce point de lui-même |
   | Réussite **au hasard** | ×1,0 | Le score surestime le savoir réel |
   | Domaine **jamais évalué** | ×0,5 | Un angle mort n'est pas une lacune démontrée |
   | Part **sautée** d'un domaine | ×0,5 | On n'en sait rien — exactement comme d'un domaine jamais servi |

**Le cas de l'évitement mérite d'être explicite**, parce qu'il a été un défaut
réel. Deux candidats sur la même série de dix, cinq bonnes réponses chacun :

```
répond faux aux cinq autres → score 50, écart 0,5 → urgence 10
saute les cinq autres       → score 100, écart 0   → urgence  0
```

Le second sortait de l'ordonnance **en n'affrontant pas ses questions**.
C'était l'exact contraire de la promesse du produit.

**Borne haute mesurée, pas devinée :** au-delà d'un facteur 1,0, le candidat qui
saute passerait devant celui qui répond faux. **L'erreur démontrée reste le
signal le plus sûr du produit, et rien ne doit la dépasser.**

Chaque ligne porte **son motif** — ce qui la place là — et non seulement son
rang.

## Ce qu'elle ne fait jamais

- **Aucune probabilité de réussite** (`METHODE.md` §7.3). Elle dit quoi faire
  ensuite, jamais si le candidat réussira.
- **Elle ne recommande jamais un domaine sans dire pourquoi.** Un ordre sans
  motif est un oracle ; le produit refuse les oracles.
- **Elle ne récompense pas l'évitement**, et cette garantie est tenue par une
  sonde de test, pas par une intention.
- **Elle ne classe pas un domaine jamais évalué devant une faiblesse démontrée
  de poids comparable.**
- **Elle ne remplace pas la carte de maîtrise.** Elle la consomme.

## Cas limites

| Situation | Comportement |
|---|---|
| **Aucune tentative** | Aucune ligne. L'écran vide porte **le geste qui le remplit** — un lien vers le diagnostic (règle des portes, clause 1). |
| **Tous les domaines à l'évidence insuffisante** | Les domaines apparaissent avec le facteur « jamais évalué », classés par poids officiel. |
| **Domaine sans poids officiel déclaré** | *À trancher — point 3.* |
| **Réseau coupé** | Lecture seule. La donnée disparaît, elle ne vaut pas zéro. |
| **Contenu retiré** du catalogue | Un domaine retiré ne doit plus être recommandé. *À vérifier — point 4.* |

## Ce que voit le candidat

Une liste ordonnée de domaines, chacun avec **son motif** et **un chemin
cliquable vers l'entraînement ciblé qui le remplit**.

⚠️ **Défaut documenté à ne pas reproduire :** les lignes de l'ordonnance ont
longtemps eu l'apparence d'actions sans en être. La règle des portes, clauses 2
et 3, s'applique intégralement.

**Formulation exacte des motifs :** *à trancher.* Ils sont le cœur lisible de la
fonction — un motif mal formulé transforme une aide en reproche.

## Tests d'acceptation

- [ ] Un domaine de poids officiel élevé et de maîtrise faible passe devant un
      domaine de poids faible et de maîtrise plus faible encore.
- [ ] Une erreur déclarée « sûr » place son domaine plus haut qu'une erreur
      hésitante identique par ailleurs.
- [ ] Le candidat qui **saute** cinq questions n'est **jamais** classé plus bas
      que celui qui répond faux aux cinq mêmes — la borne 1,0 tient.
- [ ] Un domaine jamais évalué est classé **derrière** une faiblesse démontrée
      de poids comparable.
- [ ] Chaque ligne rendue porte un motif non vide.
- [ ] Aucune sortie ne contient de probabilité de réussite.
- [ ] Dans le rendu, chaque ligne contient une **ancre ou un bouton**.
- [ ] **Mutation :** on porte le facteur des sautées au-dessus de 1,0 → le
      troisième test rougit, et lui seul.

## À trancher

| # | Question | Options | Conséquence du choix |
|---|---|---|---|
| 1 | **Les quatre facteurs deviennent-ils des paramètres pédagogiques ?** | (a) constantes · (b) paramètres bornés (A-10) | (b) est demandé, mais la **borne haute de 1,0 sur l'évitement n'est pas négociable** : au-delà, le produit récompenserait l'évitement. Elle doit être une borne de code, pas une valeur réglable. |
| 2 | **Un changement de facteur recalcule-t-il les ordonnances passées ?** | (a) en avant seulement · (b) recalcul global | L'ordonnance étant recalculée à chaque consultation, (b) est le comportement par défaut — et il ferait bouger l'ordre sous les yeux d'un candidat sans qu'il n'ait rien fait. |
| 3 | **Domaine sans poids officiel** | (a) exclu · (b) poids par défaut | (b) invente un chiffre officiel, ce que le produit refuse ailleurs. |
| 4 | **Domaine ou question retirés du catalogue** | — | À vérifier dans le code avant d'écrire la règle — non vérifié par le présent audit. |
| 5 | **Formulation exacte des motifs, FR et AR** | — | Décision produit. |

## Dépendances

[[F01]] (la carte qu'elle consomme), [[F02]] (les signaux de certitude),
[[F09]] (qui n'affiche que les trois premières lignes), ADR-0017 §5, ADR-0014.
