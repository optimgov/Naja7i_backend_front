# Ce que le corpus dit des causes d'erreur

**16 août 2026 · lot CRMEF-2, phase 2 · note pour le pilote, sans effet sur le code**

`error_cause` compte huit codes. Leur validation est une décision du pilote
(**DET-16**), non rendue. **Cette note n'ajoute aucun code** — en ajouter un
neuvième avant l'arbitrage rendrait cet arbitrage plus cher, et le ferait porter
sur un jeu que personne n'a validé.

Elle dit trois choses : ce que le corpus prouve, ce qu'il ne prouve pas, et les
codes que les faits imprimés appelleraient si la décision devait être rendue
aujourd'hui.

---

## 1. Ce que le corpus NE prouve pas — et c'est l'essentiel

> « Le corpus ne contient aucun rapport de jury, aucun corrigé officiel, aucune
> analyse de copies, aucune statistique de réussite par item. **Rien, dans les
> 33 fichiers, ne documente les erreurs réellement commises par les candidats.** »
> — §4.1

C'est le manque le plus lourd pour la plateforme, parce que c'est exactement la
matière du diagnostic. **Sans données sur ce que les candidats se trompent, un
code de cause ne peut être qu'une hypothèse d'expert.** Nos huit codes sont dans
ce cas, et rien dans ce corpus ne les valide ni ne les invalide.

**Les marques manuscrites ne sont pas un corrigé.** 59 questions sur 60 en
portent une sur un sujet ; Q85 de 2024 en porte deux (D et E) ; Q110 de 2025 en
porte trois ; un sujet entier est décrit comme portant « plusieurs marques,
parfois contradictoires ». Une source qui se contredit sur des dizaines d'items
n'est pas une source.

---

## 2. Ce que le corpus PROUVE — une famille, et deux pièges de méthode

### 2.1 La seule famille établissable sans hypothèse

> « Plusieurs séries de questions opposent systématiquement des auteurs proches
> ou des notions voisines — Piaget vs Vygotsky, Freud vs Erikson vs Hall,
> béhaviorisme vs cognitivisme. […] **C'est la seule famille de causes d'erreur
> que le corpus permette d'établir sans hypothèse.** » — §4.2.5

Le distracteur EST construit sur la confusion : ce n'est pas une inférence sur
le candidat, c'est une lecture du sujet. Notre code **`confusion_notions`** la
porte déjà, et c'est le seul de nos huit codes que ce corpus corrobore
directement.

### 2.2 Deux causes de MÉTHODE, qui n'ont rien à voir avec le savoir

Ces deux-là sont imprimées sur les sujets. Ce sont des faits, pas des
hypothèses — mais ce sont des faits sur l'ÉPREUVE, pas sur le candidat.

| Fait imprimé | Effet sur le candidat |
|---|---|
| **L'option E** — `جميع الاختيارات المقترحة خاطئة` (§4.2.1) | « L'élimination progressive, stratégie standard du QCM à quatre options, cesse de fonctionner. Un candidat qui écarte trois options ne peut plus conclure. » |
| **La pénalité négative** — `يعتمد تنقيط سالب` (§4.2.2) | « Répondre au hasard devient statistiquement perdant. Le candidat doit apprendre quand s'abstenir » — une compétence métacognitive distincte du contenu. |

Le corpus les qualifie lui-même de pièges « de **méthode**, pas de connaissance ».

### 2.3 Une troisième, matérielle

> « Une case mal remplie, une rature, un usage de Blanco, une réponse hors
> case : `يُعتبر لاغيا` — annulée, et depuis 2025 **pénalisée comme une
> erreur**. » — §4.2.3

« Une cause d'erreur qui n'a rien à voir avec le savoir, et qui est
intégralement évitable par l'entraînement. »

---

## 3. Le cas rencontré en livrant la phase 2

L'option E a dû être écrite dans les fixtures de test, pour que les questions
ressemblent à l'épreuve réelle. Son distracteur demande un code de cause, et
**aucun des huit ne le porte** :

- ce n'est pas une `confusion_notions` — le candidat ne confond aucun auteur ;
- ce n'est pas une `lecture_enonce` — l'énoncé est compris ;
- ce n'est pas une `connaissance_absente` — le candidat peut tout savoir et
  cocher E par excès de prudence, ou par élimination mal conduite.

**`indetermine` a donc été employé**, faute de mieux et sans rien inventer. Ce
n'est pas satisfaisant : ce distracteur a une cause parfaitement nommable, et
c'est précisément celle que §4.2.1 décrit.

---

## 4. Ce que les faits imprimés appelleraient — si la décision devait être rendue

**Proposition, non appliquée.** Deux ou trois codes, pas davantage :

| Code proposé | Ce qu'il nommerait | Fondé sur |
|---|---|---|
| `elimination_inoperante` | Le candidat a écarté les distracteurs et conclu sur la dernière option restante, alors que l'option « toutes fausses » invalide ce raisonnement | §4.2.1, fait imprimé |
| `hasard_sous_penalite` | Le candidat a répondu sans savoir, là où l'abstention rapportait davantage. Sa propre déclaration de certitude — « au hasard », F02 — le dit déjà | §4.2.2, fait imprimé |
| `report_ou_forme` | Réponse annulée pour une raison matérielle : case débordée, rature, décalage de ligne | §4.2.3 et §4.2.4 |

**Trois réserves, qui appartiennent au pilote :**

1. Les deux premiers ne sont observables qu'en croisant la réponse avec **la
   certitude déclarée**. C'est possible — `responses.confidence` existe depuis
   les fondations — mais cela change la nature du code : les huit actuels se
   lisent sur la QUESTION, ceux-ci se lisent sur la RÉPONSE.
2. `report_ou_forme` ne se produit pas sur la plateforme : il n'y a pas de
   feuille à noircir. Il ne serait qu'un objet d'entraînement, pas un
   diagnostic. Le §4.2.4 suggère d'ailleurs le bon remède, et il n'est pas un
   code de cause : reproduire la numérotation réelle — ce que la phase 2 a fait.
3. Aucun de ces trois n'est validé par des erreurs réellement observées. Ils
   sont fondés sur ce que le SUJET fait, pas sur ce que le candidat rate.

---

## 5. Où trouver ce qui manque

Le corpus le dit lui-même (§4.3), et le dernier point est le plus actionnable :

| Manque | Où le chercher |
|---|---|
| Rapports de jury | CNEE / ministère — s'ils existent et sont publics |
| Corrigés officiels | CNEE ; à défaut, double révision experte |
| Statistiques de réussite par item | Non publiques, sauf demande institutionnelle |
| Erreurs réellement observées en copies | **Les formateurs des CRMEF eux-mêmes** — « la source la plus accessible et la plus riche, et celle que le dispositif éditorial doit solliciter en priorité » |

C'est de là que viendra la validation de DET-16, pas d'un corpus de sujets.
