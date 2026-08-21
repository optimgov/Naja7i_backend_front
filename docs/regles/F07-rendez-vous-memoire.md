# F07 — Rendez-vous Mémoire

**Statut :** **brouillon révisé — autonome, reconstruite depuis le code livré**, jamais validée
**Version :** 0.3 — 21 août 2026
**Validée par :** — *(en attente d'arbitrage OptimGov)*
**Source de la ligne d'origine :** `NAJAH-INV-001` §8, F07
**Dépend de :** [[F02]], [[F03]] (validée), [[F05]] (vivier partagé)

> **Avertissement de méthode.** Fiche écrite après le code. Voir [[F01]].
> C'est la fonction la plus riche en décisions non écrites : neuf valeurs
> numériques d'architecte y sont concentrées, regroupées en cinq paramètres
> métier potentiels et signalées en dette.

---

## Pourquoi cette fonction existe

Une erreur comprise aujourd'hui est une erreur refaite dans trois semaines. La
répétition espacée est le seul mécanisme connu qui transforme une compréhension
ponctuelle en savoir disponible le jour de l'épreuve.

## Quand elle se déclenche

**La planification** est appelée à la soumission de **toute tentative close** —
révision, entraînement ou diagnostic. Si seules les révisions y passaient, un
candidat qui travaille beaucoup ne ferait avancer aucun rendez-vous.

**La consultation** est libre : voir ce qui est dû est un geste quotidien.
**L'ouverture d'une séance** est limitée : elle ne se fait qu'une fois.

## Ce qu'elle fait

### Le principe : on planifie une erreur, pas une question

Le rendez-vous porte le couple **(compétence, cause)**. Resservir douze fois le
même item apprendrait l'item, pas la compétence.

### Un calendrier à casiers, pas un facteur d'aisance

Cinq paliers fixes, en jours : **1 · 3 · 7 · 16 · 35**.

Le choix d'un système à la Leitner plutôt qu'un SM-2 est délibéré : SM-2 produit
des nombres qui **ont l'air scientifiques et ne le sont pas** sur une banque
jeune, sans historique de calibration. Le projet refuse déjà la fausse
précision. Et un palier nommé s'explique au candidat — « revu dans 3 jours » se
comprend, « facteur d'aisance 2,36 » non.

### Les règles de mouvement

| Règle | Valeur | Raison |
|---|---|---|
| **Sortie du calendrier** | 2 réussites **certaines** consécutives | Une réussite hésitante ou au hasard ne fait pas sortir |
| **Marge avant l'épreuve** | 2 jours | Un rendez-vous la veille du concours n'aide plus : on révise, on ne découvre pas |
| **Plafond d'une séance** | 20 rendez-vous | Un candidat revenu après trois semaines en a des dizaines ; les servir tous ferait une séance qu'il n'entamerait pas |
| **Plafond de l'énoncé resservi** | palier 3 (7 jours) | Voir ci-dessous |

**Le plafond n'est jamais silencieux :** ce qui n'est pas servi est **compté et
annoncé**.

### Clarification des constantes et de leur gouvernance

La fonction porte exactement **neuf valeurs numériques d'architecte** : les
cinq durées de palier, le seuil de deux réussites, la marge de deux jours, le
plafond de vingt rendez-vous et l'indice de plafond de l'énoncé resservi
(palier 3). Elles se regroupent en **cinq paramètres métier potentiels** :
calendrier des paliers, seuil de sortie, marge, plafond de séance et plafond de
resservi.

La gouvernance doit encore décider si le calendrier se paramètre comme un
ensemble cohérent ou durée par durée. Toute modification agit en avant
seulement : elle ne déplace ni ne recalcule les rendez-vous déjà posés.

### Le cas de l'énoncé resservi

Quand la banque n'a qu'une seule question pour un couple, la révision ressert le
même énoncé — sauter une échéance serait pire. Mais un énoncé resservi ne monte
alors **que jusqu'au milieu de l'échelle** :

- **geler complètement** ferait revenir chaque jour, indéfiniment, tout couple
  servi par une seule question — et ces couples satureraient la séance plafonnée
  à vingt, en évinçant les rendez-vous que le candidat peut résoudre ;
- **laisser filer jusqu'à 35 jours** ferait disparaître le rendez-vous par la
  petite porte, après lui avoir fermé la grande.

Le plafond **borne la montée, il ne fait jamais redescendre** un palier déjà
atteint par de vraies questions sœurs.

### L'évitement est compté

Une question **servie et sautée** entre dans l'urgence, à facteur partiel et
**sous son propre motif** — l'évitement cesse de payer.

### Consommation et protection contre l'aspiration

La consultation du nombre dû ne consomme rien. Les items servis par F07 ne
débitent pas le quota général de questions : ils remédient à une erreur déjà
rencontrée et ne constituent pas un entraînement libre.

Cette exemption est tenue par le serveur : un nouvel énoncé ne peut provenir
que d'un rendez-vous issu d'une **erreur causée appartenant au compte**. Le
serveur conserve le plafond de vingt rendez-vous par séance, refuse toute
énumération du vivier, rend ouverture et reprise idempotentes, limite leur débit
et trace séance, rendez-vous, item et origine. Les valeurs de limitation de
débit et l'éventuel plafond de nouveaux énoncés sœurs par rendez-vous ou couple
doivent être spécifiés avant toute modification applicative.

## Ce qu'elle ne fait jamais

- **Aucune prédiction.** Une date de prochaine revue, et rien d'autre : ni
  probabilité de rétention, ni « vous retiendrez 80 % ».
- **Elle ne ressert jamais une question comme unité de planification.** L'unité
  est le couple.
- **Elle ne dépasse jamais la marge avant l'épreuve.**
- **Elle ne masque jamais ce qu'elle n'a pas servi.**
- **Elle ne fait pas sortir du calendrier sur une réussite hésitante.**
- **Elle ne débite jamais le quota général de questions.**
- **Elle ne sert jamais un nouvel énoncé sans erreur causée préalable du même
  compte**, ni par accès direct ou énumération du vivier.

## Cas limites

| Situation | Comportement |
|---|---|
| **Aucun rendez-vous dû** | Zéro est une **information** — « rien à réviser aujourd'hui » — pas un vide. L'écran le dit et porte une porte. |
| **Des dizaines de rendez-vous échus** | Vingt sont servis, le reste est compté et annoncé. |
| **Aucune question sœur** pour un couple | L'énoncé est resservi, et le palier plafonne à 7 jours. |
| **Épreuve dans moins de 2 jours** | Aucun nouveau rendez-vous n'est planifié au-delà de la marge. |
| **Fuseau horaire du candidat** | La date d'un rendez-vous en dépend. Aujourd'hui **une clé de configuration globale**, pas une colonne — DET-33. |
| **Double ouverture, reprise ou rejeu réseau** | La même séance et les mêmes items sont rendus ; aucun nouvel énoncé ni effet de calendrier n'est produit par le rejeu. |
| **Item dû mais non servi sous le plafond** | Il reste compté et annoncé ; son énoncé n'est ni révélé ni tracé comme servi. |
| **Tentative d'accès sans erreur causée du compte** | Aucun nouvel énoncé n'est rendu, même si le couple existe dans la banque. |
| **Tout premier usage** | Aucun rendez-vous. La fonction se remplit à la première erreur causée. |

## Ce que voit le candidat

Le nombre de rendez-vous dus — **la seule chose du tableau de bord qui change
tous les jours, et donc la raison de revenir**. Puis une séance, plafonnée, avec
mention de ce qui n'a pas été servi.

**Formulation exacte :** *à trancher.*

## Tests d'acceptation

- [ ] Une erreur causée crée un rendez-vous sur le **couple**, pas sur la
      question.
- [ ] Deux réussites **certaines** consécutives font sortir du calendrier ; deux
      réussites hésitantes non.
- [ ] Aucun rendez-vous n'est planifié dans les 2 jours précédant l'épreuve.
- [ ] Plus de 20 rendez-vous dus → 20 servis, et le reste **annoncé**.
- [ ] Un couple sans question sœur ne dépasse jamais le palier de 7 jours.
- [ ] Un palier déjà supérieur n'est pas **abaissé** par le plafond de l'énoncé
      resservi.
- [ ] Une question sautée entre dans l'urgence sous son propre motif.
- [ ] Consultation, ouverture et reprise F07 ne débitent jamais le quota général
      de questions.
- [ ] Aucun nouvel énoncé n'est servi sans rendez-vous issu d'une erreur causée
      appartenant au compte.
- [ ] Même ouverture rejouée ou deux appareils concurrents → même séance et
      mêmes items, sans divulgation supplémentaire.
- [ ] Les items dus mais non servis ne sont ni révélés ni tracés comme servis.
- [ ] Un changement de paramètres ne déplace aucun rendez-vous historique.
- [ ] Aucune sortie ne contient de probabilité de rétention.
- [ ] **Mutation :** on retire le plafond de l'énoncé resservi → le cinquième
      test rougit, et lui seul.

## À trancher

| # | Question | Options | Conséquence du choix |
|---|---|---|---|
| 1 | **Le calendrier se paramètre-t-il comme un ensemble ou durée par durée ?** | (a) ensemble cohérent · (b) cinq durées bornées séparément | Dans les deux cas, un changement agit en avant seulement et ne recalcule pas les rendez-vous déjà posés. |
| 2 | **Les quatre autres paramètres** (sortie, marge, plafond de séance, plafond de resservi) deviennent-ils pédagogiques ? | (a) constantes · (b) paramètres bornés | Le plafond de séance a un effet d'usage direct ; aucun réglage ne peut désactiver les invariants de sécurité et de traçage. |
| 3 | **Le fuseau du candidat doit-il devenir une colonne ?** | DET-33 — aujourd'hui une clé globale | Un candidat hors du Maroc reçoit ses rendez-vous à la mauvaise date. |
| 4 | **Une bonne réponse fait avancer jusqu'à quatre rendez-vous** — DET-37 | | Conséquence de l'arbitrage sur l'unité de planification. Effet non mesuré à ce jour. |
| 5 | **Formulation exacte, FR et AR** | — | Décision produit. |
| 6 | **Quelles bornes anti-aspiration complémentaires ?** | Limite de débit et éventuel plafond de nouveaux énoncés sœurs par rendez-vous ou couple | Les valeurs doivent protéger la banque sans transformer F07 en rendez-vous sans remédiation. |

## Dépendances

[[F02]] (la sortie exige des réussites **certaines**), [[F03]] — validée (la
cause est l'unité planifiée), [[F05]] (vivier de questions sœurs **partagé**,
politique de repli **divergente**), DET-32, DET-33, DET-37.
La consommation et la protection de la banque suivent ADR-0027 et sa matrice.
