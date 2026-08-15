# CRMEF — rapprochement du corpus et de la base

**15 août 2026 · lot CRMEF-2, phase 1 · aucun code produit**

Confrontation de `docs/corpus/CRMEF-extraction-20260815.md` à ce que la base
affirme aujourd'hui. Ce document constate ; il ne corrige rien.

**Ce qui a été confronté :** `Crmef2025Seeder`, `docs/regles/CRMEF-2025-referentiel-source.md`,
`BlueprintModel`, `Exam`, `CompetencyNode`, `DiagnosticComposer`, le registre
`sources`, `ADR-0014`, `DET-52`, et l'état réel de la base de développement.

---

## 1. Les voies

Le corpus identifie **trois** concours distincts (§1.1). La base modélise
**trois `tracks`**, mais ce ne sont pas les mêmes trois.

| Corpus — intitulé imprimé (§1.1) | Sessions | Dans la base ? |
|---|---|---|
| **A.** …مسلك تأهيل أساتذة التعليم الابتدائي — التخصص المزدوج وتخصص اللغة الأمازيغية | أكتوبر 2024 · نونبر 2025 | **Oui, mais scindée en deux** : `primaire-bilingue` et `primaire-amazigh` |
| **B.** …مسلك تأهيل أساتذة التعليم الثانوي الإعدادي ومسلك تأهيل أساتذة التعليم الثانوي التأهيلي | نونبر 2025 | **Oui** — `secondaire` (« Secondaire collégial et qualifiant ») |
| **C.** مباراة ولوج المراكز الجهوية لمهن التربية والتكوين لتوظيف أساتذة التعليم الثانوي من الدرجة الثانية | 2023 | **NON MODÉLISÉE** |

**Deux écarts.**

1. **La voie C n'existe pas dans la base.** Or **14 des 25 sujets** du corpus en
   relèvent — c'est la voie la mieux documentée du corpus, et la seule dont on
   possède un blueprint intra-bloc complet (§1.5.1). Les importer sous la voie B
   serait une erreur de fond : l'intitulé imprimé, l'autorité émettrice
   (`المركز الوطني للتقويم والامتحانات` en 2023 contre
   `المركز الوطني للامتحانات المدرسية وتقييم التعلمات` ensuite), le nombre
   d'options et le barème diffèrent tous.
2. **La voie A est scindée en deux là où le corpus imprime un intitulé unique.**
   Le scindement n'est pas faux — les épreuves de spécialité diffèrent — mais il
   ne vient pas de l'intitulé imprimé.

---

## 2. Les coefficients

Le corpus imprime 16 coefficients (§1.6). La base en porte 3.

| Épreuve en base | Coef. base | Durée base | Ce que le corpus IMPRIME |
|---|---:|---:|---|
| `CRMEF-SE-2025` — Sciences de l'éducation | 8 | 120 | **8** en primaire 2024, primaire 2025, collège/qualifiant 2025 — **et 5 (ar.) / 8 (fr.) en 2023, contradictoires** (§1.5.3) |
| `CRMEF-FR-DID-2025` — Didactique français | 12 | 120 | **12** pour la didactique **française de 2023, voie C** (§1.5.2). Rien d'imprimé pour 2025. |
| `CRMEF-FR-SPEC-2025` — Spécialité français | 20 | 240 | **Aucun coefficient imprimé pour la spécialité française, aucune session.** Le seul 20 du corpus est celui de la **spécialité physique-chimie 2023** (§1.5.1). |

### Ce qui est corroboré, et ce qui ne l'est pas

- **Le 8 de Sciences de l'éducation est solide** : trois documents, trois voies.
  C'est la seule des trois valeurs de la base que le corpus confirme pour la
  session de référence.
- **Le 12 de didactique n'est corroboré que pour 2023, voie C.** La base le
  porte pour 2025, voie B.
- **Le 20 de spécialité n'est corroboré nulle part pour le français.** La seule
  occurrence de 20 dans le corpus appartient à une autre discipline et à une
  autre voie.

> **Constat, sans interprétation.** Les trois durées de la base — 120, 120, 240 —
> correspondent elles aussi exactement aux durées imprimées en 2023
> (`ساعتان`, `ساعتان`, `أربع ساعات`). Le corpus ne permet ni de confirmer ni de
> réfuter les valeurs 2025 : il ne contient aucun descriptif de cette session.
> Je ne conclus pas qu'elles ont été reportées de 2023 ; je constate que rien
> dans le corpus ne les distingue de ce report.

### Les huit blocs sans coefficient

Le corpus en compte 8 sur 22 sans coefficient imprimé, tous par **absence de
page de garde** (§5.2). Ils restent **NULS** — c'est déjà la discipline du
seeder (« Ce qui n'est PAS dans les descriptifs reste nul ») et la règle ne
change pas. Aucun n'a d'équivalent en base aujourd'hui, la base ne modélisant
que trois épreuves.

---

## 3. ⚠ L'affaire des 40 / 30 / 30

**La question posée :** d'où viennent les poids qui composent nos diagnostics ?

### La chaîne, remontée

| Maillon | Ce qu'il dit |
|---|---|
| `CompetencyNode.weight_percent` | `SE-PSY` 40, `SE-PED` 30, `SE-SOC` 30 — et `source_id` → `SRC-CRMEF-2025-SE` |
| `sources.SRC-CRMEF-2025-SE` | `kind = descriptif_officiel`, autorité citée, localisation « pages 2-3 : domaines et poids ». **`verified_at` = NULL** |
| `Crmef2025Seeder` (l. 19) | « Source : `docs/regles/CRMEF-2025-referentiel.md`, transposé des descriptifs du Centre national… » — le fichier réellement présent est `CRMEF-2025-referentiel-source.md` |
| `docs/regles/CRMEF-2025-referentiel-source.md` §7.2 | imprime les 40/30/30 et les six sous-domaines |
| **le même fichier, ligne 23** | **« Tu ne recevras pas les PDF officiels. Les informations utiles qui en ont été extraites sont intégralement consignées dans ce document. Ne prétends toutefois pas posséder ou avoir consulté les PDF. »** |
| `docs/corpus/…-20260815.md` §1.0 | « Aucun cadre de référence du concours… n'a été fourni » ; les poids intra-bloc ne sont imprimés que pour **la physique-chimie 2023** |

### La réponse : ni (a) ni (b) — un troisième état

Ce n'est **pas** le cas (a) : aucun descriptif officiel n'est citable, parce
qu'aucun n'est dans le dépôt ni dans le corpus, et que le document qui les
transcrit interdit explicitement d'affirmer les avoir consultés.

Ce n'est **pas** le cas (b) non plus : les poids ne sortent pas de nulle part.
Ils ont une origine **identifiée, datée et nommée** — un document d'instructions
du 8 août 2026 qui déclare transcrire trois descriptifs, avec leur autorité,
leur session et leur pagination.

> **Les 40/30/30 viennent d'une source identifiée mais INVÉRIFIABLE en l'état.**
> Ce ne sont pas des poids inventés ; ce sont des poids **rapportés**, dont
> personne dans ce dépôt n'a vu la pièce d'origine.

**C'est cet écart-là qui doit être marqué**, et il l'est en un point précis : le
registre des sources porte déjà `verified_at` / `verified_by` depuis le PAS
« vérifier une source », et **`SRC-CRMEF-2025-SE` et `SRC-CRMEF-2025-FR-DID` ne
sont pas vérifiées**. Le mécanisme existe donc, et il dit déjà la vérité — mais
rien ne l'exploite : un nœud pondéré par une source non vérifiée compose des
diagnostics exactement comme un autre.

### Ce que les tests prouvent, et ce qu'ils ne prouvent pas

`ReferentielCrmefTest` vérifie que les enfants somment au parent et les racines à
100. `ADR-0014 §3` intitule cela « les poids officiels sont vérifiables ».

**C'est une vérification ARITHMÉTIQUE, pas DOCUMENTAIRE.** Elle prouve que
40 + 30 + 30 = 100. Elle ne prouve pas que 40 soit juste, et ne le pourrait pas.
Un jeu de poids entièrement faux mais cohérent passerait ces tests. Le titre de
l'ADR laisse croire l'inverse.

### Conséquence produit, énoncée sans dramatiser

`DiagnosticComposer` répartit les questions au prorata de `weight_percent`. La
composition d'un diagnostic de Sciences de l'éducation est donc **fidèle à un
document que nous n'avons pas**. Le candidat ne se voit rien promettre
d'explicite sur cette fidélité — aucun écran n'affiche « conforme au
référentiel officiel » — mais le produit se règle dessus.

**Non corrigé dans ce lot, conformément à la consigne.** Inscrit en dette
(**DET-60**), et remonté au point d'arrêt.

---

## 4. Ce qui manque pour un examen blanc fidèle (§1.7)

Six manques, tous **actions du pilote**, aucun n'est du code.

| # | Manque | Conséquence produit | Où le trouver |
|---|---|---|---|
| 1 | Cadre de référence officiel du concours | Pas de pondération intra-bloc normée — voir §3 ci-dessus | Ministère / CNEE |
| 2 | Seuil d'admission | Aucun seuil affichable ; la règle « aucune prédiction » le couvre déjà | Avis de concours officiel |
| 3 | Corrigés officiels | **Aucune bonne réponse publiable** ; commande la phase 3 entière | CNEE, ou double révision experte |
| 4 | Coefficient de 8 blocs sur 22 | Score pondéré incomplet | Pages de garde manquantes des mêmes sujets |
| 5 | Bloc اللغة الأمازيغية (primaire, Q1→Q25 didactique) | La voie `primaire-amazigh` existe en base sans aucune donnée d'épreuve | Sujets de la même session |
| 6 | Épreuve Q1→Q60 collège/qualifiant 2025 | Moitié de l'épreuve inconnue pour la voie B | Sujets de la même session |

**Deux réserves du corpus s'y ajoutent** (§5.4), et elles priment sur tout usage :

- **Droits** — 25 reproductions d'un tiers sous filigrane, dont un filigrane
  nominatif de personne physique. La republication commerciale n'est pas
  tranchée. **À vérifier avant toute mise en ligne.**
- **Intégrité** — 10 des 25 sujets sont démontrablement amputés ; l'un perd cinq
  questions en plein milieu (2023 spécialité anglais, Q80→Q84), un autre s'arrête
  avant la fin (2023 SCED version française, Q111→Q120).

---

## 5. Ce que ce rapprochement ne fait pas

- Il ne corrige aucun coefficient. Le 12 et le 20 restent en base tels quels :
  les remplacer par des valeurs de 2023 serait remplacer une valeur invérifiable
  par une valeur d'une autre voie.
- Il ne crée pas la voie C. C'est un pas de modélisation, pas un constat.
- Il ne touche pas aux 40/30/30.
- Il ne tranche pas la contradiction 5/8 de 2023. Le corpus dit
  explicitement de ne pas la trancher sans source officielle.

---

## Dette ouverte par ce rapprochement

| ID | En une ligne |
|---|---|
| **DET-60** | Les poids de composition viennent d'une source identifiée mais non vérifiée |
| **DET-61** | La voie C (secondaire 2e grade) n'est pas modélisée alors que 14 des 25 sujets en relèvent |
| **DET-62** | Le coefficient 12 et le coefficient 20 de la voie française 2025 ne sont corroborés par aucun document du dépôt |
| **DET-63** | `ADR-0014 §3` annonce une vérifiabilité des poids qui est arithmétique, pas documentaire |
| **DET-64** | Contradiction 5 / 8 sur le coefficient de علوم التربية 2023, à lever sur source officielle |
| **DET-65** | Réserve de droits sur les 25 reproductions, à lever avant toute mise en ligne |
