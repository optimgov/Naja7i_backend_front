# Naja7i.ma — Instructions à Claude pour créer les catalogues et la banque de questions CRMEF 2025

**Version :** 1.0  
**Date :** 8 août 2026  
**Périmètre :** prototype front-office `optimgov/Najah.ma`  
**Session officielle de référence :** novembre 2025

---

# 1. Mission

Inspecte le dépôt existant, puis enrichis la plateforme Naja7i.ma avec :

1. un catalogue structuré des parcours CRMEF couverts par les descriptifs officiels de novembre 2025 ;
2. un parcours publiquement accessible pour la préparation au CRMEF — Langue française — enseignement secondaire collégial ou qualifiant ;
3. les trois épreuves distinctes de ce parcours ;
4. leurs blueprints officiels versionnés ;
5. l’audit et le reclassement des questions de démonstration déjà présentes ;
6. une banque initiale cohérente de questions de démonstration ;
7. les métadonnées nécessaires à la Boucle Najah : certitude, explication de chaque option, confusion probable, remédiation, question miroir et rappel différé ;
8. l’affichage clair des sources, de la couverture et du statut éditorial.

Tu ne recevras pas les PDF officiels. Les informations utiles qui en ont été extraites sont intégralement consignées dans ce document. Ne prétends toutefois pas posséder ou avoir consulté les PDF.

Le présent travail concerne les **concours d’accès aux cycles de qualification des cadres enseignants dans les CRMEF**, session de novembre 2025. Il ne concerne pas les concours d’accès aux Licences d’Éducation.

---

# 2. Résultat attendu

À la fin du travail :

- le catalogue CRMEF doit être visible et navigable ;
- le parcours Français secondaire doit être utilisable ;
- les trois épreuves doivent rester séparées ;
- les données officielles doivent être distinguées des choix éditoriaux de Naja7i ;
- les questions existantes doivent conserver leurs identifiants ;
- aucune question non validée ne doit être présentée comme certifiée ;
- les anciennes fonctionnalités et routes du prototype doivent continuer à fonctionner ;
- `node build.js` doit terminer sans erreur et reconstruire `index.html` selon le processus existant.

Ne refais pas l’ensemble du design ni l’architecture du prototype. Étends proprement le modèle et les composants existants.

---

# 3. Règles absolues

## 3.1 Ne rien inventer sur le concours

Il est interdit d’inventer :

- le nombre officiel de questions ;
- le barème détaillé ;
- un seuil d’admission ;
- une note éliminatoire ;
- une règle de navigation ;
- une pénalité pour mauvaise réponse ;
- une probabilité de réussite ;
- un classement fictif ;
- une précision réglementaire absente de ce document.

Lorsqu’une information n’est pas connue, utilise `null`, `unknown`, `non_confirme` ou une formulation visible comme « Information non confirmée par les sources disponibles ».

## 3.2 Séparer quatre types de données

Chaque information importante doit porter une origine parmi :

- `official` : explicitement fournie par le descriptif officiel 2025 ;
- `observed` : constatée dans les questions ou contenus déjà présents dans le dépôt ;
- `editorial` : choix de Naja7i pour l’apprentissage ou l’entraînement ;
- `unverified` : information ou contenu nécessitant une validation humaine.

Un choix éditorial ne doit jamais être affiché comme une caractéristique officielle du concours.

## 3.3 Publication et validation

- Tout nouveau contenu créé par IA reçoit par défaut le statut `a_verifier`.
- Une question ne devient `valide_pedagogiquement` ou `publiee` que si une donnée existante dans le dépôt prouve cette validation humaine.
- Ne crée aucune fausse identité d’auteur, de relecteur ou de validateur.
- Si aucun validateur réel n’est renseigné, stocke `validatedBy: null`.
- Une question `a_verifier` peut être visible en mode démonstration, avec le badge « Démonstration — à vérifier ».
- Une question dépourvue de source de contenu fiable est exclue des diagnostics et simulations ; elle peut seulement être conservée comme brouillon éditorial.

## 3.4 Une explication complète par question

Une question n’est pas éligible à la Boucle Najah si elle ne contient pas :

- la justification de la bonne réponse ;
- une justification propre à chacune des trois mauvaises options ;
- une confusion probable formulée prudemment ;
- une source ;
- une microcompétence ;
- une courte remédiation ;
- une question miroir réellement différente ;
- une règle ou activité de rappel différé.

La confusion doit être formulée comme une hypothèse : « Cette réponse peut traduire une confusion entre… », jamais comme un diagnostic psychologique certain.

---

# 4. Autorité et registre des sources

Les trois sources suivantes proviennent du **Centre national des examens scolaires et de l’évaluation des apprentissages**, Ministère de l’Éducation nationale, du Préscolaire et des Sports, Royaume du Maroc.

Crée les entrées versionnées suivantes dans le registre des sources :

| ID stable | Intitulé à enregistrer | Session | Langue | Localisation utile | Statut |
|---|---|---|---|---|---|
| `SRC-CRMEF-2025-SE` | Descriptif des domaines des épreuves écrites — Sciences de l’éducation — concours d’accès aux cycles de qualification des cadres enseignants | Novembre 2025 | Arabe ; épreuve autorisée en arabe ou en français au choix du candidat | Page 1 : métadonnées ; pages 2-3 : domaines et poids | `source_officielle` |
| `SRC-CRMEF-2025-FR-DID` | Descriptif de l’épreuve de didactique — spécialisation Langue française | Novembre 2025 | Français | Page 1 : métadonnées ; pages 2-3 : domaines et poids | `source_officielle` |
| `SRC-CRMEF-2025-FR-SPEC` | Descriptif de l’épreuve de spécialité — discipline Langue française | Novembre 2025 | Français | Page 1 : métadonnées ; pages 2-7 : domaines, contenus et poids | `source_officielle` |

Important : ces descriptifs prouvent le périmètre et les poids des domaines. Ils ne suffisent pas, à eux seuls, à prouver toutes les réponses à des questions de contenu. Une question doit donc distinguer :

- `blueprintSource` : l’un des descriptifs ci-dessus ;
- `contentSources` : ouvrage, texte ou support qui fonde réellement la correction ;
- `sourceLocation` : page, chapitre, article ou section précise ;
- `sourceVerificationStatus` : `verified`, `to_verify` ou `missing`.

Si aucune source de contenu fiable n’est déjà disponible dans le dépôt, utilise `SOURCE_CONTENU_A_VALIDER` et rends la question inéligible au diagnostic et aux simulations.

---

# 5. Catalogue CRMEF à intégrer

Crée un concours stable :

```yaml
id: crmef-qualification-enseignants
code: CRMEF
title_fr: Concours d’accès aux cycles de qualification des cadres enseignants
title_ar: مباراة ولوج سلك تأهيل أطر التدريس بالمراكز الجهوية لمهن التربية والتكوين
reference_session: 2025-11
authority: Centre national des examens scolaires et de l’évaluation des apprentissages
status: active_reference
```

## 5.1 Parcours primaire bilingue

Créer une fiche catalogue pour le parcours primaire bilingue. Les descriptifs disponibles couvrent :

- Sciences de l’éducation ;
- Didactique du primaire bilingue ;
- Matières de spécialité du primaire bilingue.

La didactique et la spécialité bilingues couvrent notamment l’arabe, le français, les mathématiques et l’activité scientifique.

Statut public : `coming_soon`. Ne génère pas encore de banque de questions pour ce parcours dans ce chantier.

## 5.2 Parcours primaire — Langue amazighe

Créer une fiche catalogue pour le parcours primaire amazigh. Les descriptifs disponibles couvrent :

- Sciences de l’éducation ;
- Didactique de la langue amazighe ;
- Spécialité Langue amazighe.

Statut public : `coming_soon`. Ne génère pas encore de banque de questions pour ce parcours dans ce chantier.

## 5.3 Parcours secondaire

Créer les spécialités suivantes dans le catalogue secondaire :

1. Langue arabe ;
2. Langue française ;
3. Langue anglaise ;
4. Éducation islamique ;
5. Sciences sociales — Histoire et géographie ;
6. Philosophie ;
7. Mathématiques ;
8. Physique et chimie ;
9. Sciences de la vie et de la Terre ;
10. Informatique ;
11. Sciences économiques et gestion ;
12. Technologie ;
13. Éducation physique et sportive.

Pour chacune, le corpus officiel disponible comporte :

- le descriptif commun de Sciences de l’éducation ;
- un descriptif de didactique de la discipline ;
- un descriptif de spécialité de la discipline.

Seule la spécialité **Langue française** reçoit le statut `available_demo` dans ce chantier. Toutes les autres restent `coming_soon`, même si leur fiche catalogue et l’existence de leurs trois descriptifs sont enregistrées.

Ne déduis ni les coefficients ni les durées des autres spécialités à partir du parcours français. Chaque spécialité doit conserver `coefficient: null` et `durationMinutes: null` tant que ses métadonnées n’ont pas été intégrées et vérifiées séparément.

---

# 6. Parcours prioritaire : Français secondaire

Créer un parcours unique couvrant les deux cycles mentionnés par les sources :

- enseignement secondaire collégial ;
- enseignement secondaire qualifiant.

Le candidat choisit son cycle, mais les documents disponibles fournissent un même descriptif d’épreuve pour les deux cycles. Ne crée donc pas artificiellement deux blueprints différents.

Le parcours comprend trois épreuves séparées :

| Code | Épreuve | Coefficient | Durée | Format | Langue |
|---|---|---:|---:|---|---|
| `CRMEF-SE-2025` | Sciences de l’éducation | 8 | 120 minutes | QCM | Arabe ou français, au choix du candidat |
| `CRMEF-FR-DID-2025` | Didactique de la langue française | 12 | 120 minutes | QCM | Français |
| `CRMEF-FR-SPEC-2025` | Spécialité — Langue française | 20 | 240 minutes | QCM | Français |

Ne mélange jamais les trois épreuves dans une « simulation officielle » unique.

---

# 7. Blueprint officiel — Sciences de l’éducation

## 7.1 Métadonnées

```yaml
id: BP-CRMEF-SE-2025
exam_id: CRMEF-SE-2025
version: 2025-11
coefficient: 8
duration_minutes: 120
format: qcm
languages_allowed: [ar, fr]
official_question_count: null
official_detailed_scoring: null
official_admission_threshold: null
source_id: SRC-CRMEF-2025-SE
```

## 7.2 Domaines et poids officiels

### Domaine 1 — Psychologie de l’éducation : 40 %

- `SE-PSY-DEV` — Psychologie du développement : 20 %.
  - Concepts du développement.
  - Théories de Piaget, Freud et Erikson.
  - Facteurs du développement.
  - Manifestations et troubles du développement.
- `SE-PSY-LEARN` — Psychologie de l’apprentissage : 20 %.
  - Gestaltisme.
  - Behaviorisme.
  - Constructivisme.
  - Socioconstructivisme.
  - Cognitivisme.
  - Obstacles et difficultés d’apprentissage.

### Domaine 2 — Approches pédagogiques et méthodes d’enseignement : 30 %

- `SE-PED-PPO-APC` — De la pédagogie par objectifs à l’approche par compétences : 15 %.
  - Fondements théoriques.
  - Concepts fondamentaux.
  - Mise en œuvre opérationnelle.
- `SE-PED-METHODS` — Méthodes d’enseignement et stratégies d’enseignement-apprentissage : 15 %.
  - Méthode générative et socratique.
  - Auto-apprentissage.
  - Résolution de problèmes.
  - Pédagogie du projet.

### Domaine 3 — Sociologie de l’éducation : 30 %

- `SE-SOC-EDU` — Sociologie de l’éducation : 15 %.
  - Concepts et théories de la sociologie de l’éducation.
  - Sociologie de l’éducation dans l’école marocaine.
- `SE-SOC-GROUP` — Dynamique de groupe : 15 %.
  - Groupe-classe.
  - Leadership.
  - Sociométrie.
  - Gestion des relations et résolution des conflits.
  - Communication pédagogique.
  - Techniques d’animation en classe.
  - Vie scolaire.

Les six sous-domaines totalisent 100 % : `20 + 20 + 15 + 15 + 15 + 15`.

---

# 8. Blueprint officiel — Didactique de la langue française

## 8.1 Métadonnées

```yaml
id: BP-CRMEF-FR-DID-2025
exam_id: CRMEF-FR-DID-2025
version: 2025-11
coefficient: 12
duration_minutes: 120
format: qcm
language: fr
official_question_count: null
official_detailed_scoring: null
official_admission_threshold: null
source_id: SRC-CRMEF-2025-FR-DID
```

## 8.2 Domaines et poids officiels

### Bloc A — Didactique : 60 %

- `FR-DID-CHAMP` — Champ de la didactique : 10 %.
  - Pédagogie et didactique.
  - Domaines d’investigation de la didactique.
  - Place de la didactique dans les sciences de l’éducation.
  - Tendances actuelles de la didactique de la discipline.
- `FR-DID-CONCEPTS` — Concepts de base : 20 %.
  - Contrat didactique.
  - Représentation et conception.
  - Niveau de formulation d’un concept.
  - Objectif-obstacle.
  - Conflit sociocognitif.
  - Situation-problème.
  - Trame conceptuelle.
  - Modèle didactique.
  - Transposition didactique.
- `FR-DID-CURRICULUM` — Curriculum : 10 %.
  - Notion de curriculum.
  - Déterminants du curriculum de la discipline.
- `FR-DID-RESOURCES` — Ressources didactiques : 20 %.
  - Définition, typologie et opérationnalisation dans la discipline.
  - Outils didactiques propres à l’enseignement de la discipline.
  - Usages pédagogiques des TIC.

### Bloc B — Approches et apprentissage actif : 40 %

- `FR-DID-PPO-CONCEPTS` — Concepts clés de la pédagogie par objectifs : 5 %.
  - Finalité, but, intention, objectif général et objectif spécifique.
  - Caractéristiques et principes de la PPO.
  - Types de taxonomies.
- `FR-DID-PPO-FOUNDATIONS` — Fondements et mise en œuvre de la PPO : 10 %.
  - Formulation des objectifs.
  - Critères d’évaluation et indicateurs de réussite.
  - Cadre méthodologique de mise en œuvre.
  - Intérêts et limites.
- `FR-DID-APC-CONCEPTS` — Concepts clés de l’approche par compétences : 5 %.
  - Compétence, capacité, habileté et contenu disciplinaire.
  - Savoir, savoir-faire et savoir-être.
  - Types de situations-problèmes.
  - APC et théories de l’apprentissage.
- `FR-DID-APC-FOUNDATIONS` — Fondements et mise en œuvre de l’APC : 10 %.
  - Compétences disciplinaires et transversales.
  - Déclinaisons : compétences de vie, interdisciplinarité, intégration des acquis et standards.
  - Cadre méthodologique de mise en œuvre.
  - Différence entre PPO et APC.
- `FR-DID-ACTIVE` — Apprentissage actif : 10 %.
  - Intérêt et notions fondamentales.
  - Démarches favorisant l’apprentissage actif.
  - Approche documentaire.
  - Approche par projet.
  - Résolution de problèmes.

Les neuf sous-domaines totalisent 100 % : `10 + 20 + 10 + 20 + 5 + 10 + 5 + 10 + 10`.

---

# 9. Blueprint officiel — Spécialité Langue française

## 9.1 Métadonnées

```yaml
id: BP-CRMEF-FR-SPEC-2025
exam_id: CRMEF-FR-SPEC-2025
version: 2025-11
coefficient: 20
duration_minutes: 240
format: qcm
language: fr
official_question_count: null
official_detailed_scoring: null
official_admission_threshold: null
source_id: SRC-CRMEF-2025-FR-SPEC
```

## 9.2 Domaine Langue : 50 %

### `FR-SPEC-LING` — Linguistique, phonétique, lexicographie et lexicologie : 15 %

- Phonétique : syllabe, découpage syllabique, accent de mot, liaisons, phonétique corrective, API, voyelles et consonnes.
- Lexicographie : signification, sens en contexte, informations phonétiques, grammaticales et pragmatiques, orthographe et usage morphosyntaxique.
- Lexicologie : étymologie, morphologie lexicale et grammaticale, dérivation affixale et non affixale, champs lexicaux, sémantique lexicale, synonymie, antonymie, hyponymie, polysémie, sens propre et figuré.
- Sciences du langage : langage humain et autres langages, oral et écrit, langue/parole/langage, axes paradigmatique et syntagmatique, forme et substance, signe linguistique, signifiant et signifié, arbitraire du signe, synchronie et diachronie, double articulation, fonctions du langage, performance et compétence.
- Apprentissage des langues : théories de l’apprentissage, facteurs d’apprentissage d’une langue seconde ou étrangère, troubles de la parole et du langage.

### `FR-SPEC-GRAM` — Grammaire : 15 %

- Grammaire des mots : classes et morphologie du nom, déterminant, adjectif, verbe, adverbe, pronom, préposition et conjonction.
- Syntagmes nominal, verbal, adverbial et prépositionnel.
- Modes personnels et impersonnels, tiroirs verbaux, conjugaison et concordance des temps.
- Construction du verbe : transitif direct, transitif indirect et intransitif.
- Phrase simple : phrase verbale ou nominale, types, formes, affirmation, négation, voix active et passive, juxtaposition et coordination.
- Phrase complexe : types, relatives, complétives et circonstancielles.
- Grammaire du texte : reprise de l’information, progression de l’information, cohésion, cohérence et articulations logico-sémantiques.

### `FR-SPEC-STYL` — Stylistique : 10 %

- Registres de langue : courant, familier et soutenu.
- Figures d’analogie : métaphore, allégorie et parabole.
- Figures de substitution : métonymie, synecdoque et périphrase.
- Figures d’amplification : hyperbole et gradation.
- Figures d’atténuation : euphémisme et litote.
- Figures d’opposition : antithèse, oxymore, paradoxe et chiasme.
- Figures de construction : polyptote, asyndète, hypotaxe et parataxe.
- Effets phoniques : allitération, assonance et onomatopée.

### `FR-SPEC-DISCOURSE` — Analyse du discours et énonciation : 10 %

- Caractéristiques et structure du discours.
- Genres du discours et typologie textuelle.
- Champs de l’analyse du discours.
- Approches énonciative, distributionnelle, fonctionnaliste et pragmatique.
- Énoncé ancré ou coupé de la situation d’énonciation.
- Appareil formel de l’énonciation.
- Embrayeurs, déictiques et modalisateurs.
- Cohésion, cohérence, connecteurs et articulateurs.
- Théorie des actes de langage.

## 9.3 Domaine Littérature et culture françaises : 50 %

### `FR-SPEC-HIST-MYTH` — Histoire des idées, histoire littéraire et mythes : 5 %

- Contextes politique, socio-économique et culturel du Moyen Âge, des XVIe, XVIIe et XVIIIe siècles.
- Courants littéraires et artistiques.
- Mythes gréco-romains et bibliques.
- Adaptations, réécritures et portée symbolique des mythes.
- Mythe littéraire comme réécriture individuelle d’un texte fondateur.
- Figures universelles : Don Quichotte, Faust, Don Juan et Robinson Crusoé.

### `FR-SPEC-NOVEL` — Roman et genres du récit : 10 %

- Sources du texte narratif : épopée, chansons de geste, romans de chevalerie et fabliaux.
- Genres narratifs majeurs : conte, fable, nouvelle et roman.
- Autobiographie, récit de voyage, récit historique, journal, mémoires et correspondance.
- Genres romanesques : précieux, épistolaire, picaresque, autobiographique, réaliste et naturaliste.

### `FR-SPEC-NARRATIVE` — Analyse du texte narratif : 10 %

- Narration, histoire et récit.
- Ordre des événements, analepse et prolepse.
- Schéma actantiel.
- Point de vue, narrateur et focalisation.
- Description : caractéristiques, insertion et fonctions esthétique, réaliste, narrative ou symbolique.
- Personne, personnage, auteur, narrateur, actant et schéma relationnel.
- Temps de l’histoire et temps du récit ; scène, pause, sommaire et ellipse.
- Espaces physique et psychologique ; ouvert et fermé ; ambivalence.
- Idée, thème, champ, image, motif et progression thématique.
- Registres dramatique, épique, pathétique, tragique, lyrique, réaliste, merveilleux et fantastique.

### `FR-SPEC-THEATRE` — Théâtre : 10 %

- Tragédie.
- Comédie.
- Drame bourgeois.
- Drame romantique.

### `FR-SPEC-POETRY` — Poésie et versification : 10 %

- Éléments de métrique française.
- Sonorités.
- Formes et genres poétiques.
- Registres et fonctions de la poésie.
- Thèmes et réseaux lexicaux.
- Figures et images poétiques.
- Figure du poète.
- Techniques d’analyse d’un texte poétique.
- Mouvements poétiques et figures emblématiques.

### `FR-SPEC-MAGHREB` — Littérature maghrébine d’expression française : 5 %

- Structure narrative.
- Esthétique du texte.
- Ambiguïtés génériques.
- Représentation de la société.
- Identité, altérité et représentation de l’autre.
- Enjeux identitaires.
- Réflexion critique sur les sociétés maghrébines.

Les dix sous-domaines totalisent 100 % : `15 + 15 + 10 + 10 + 5 + 10 + 10 + 10 + 10 + 5`.

---

# 10. Modèle de données à mettre en place

Adapte ce modèle aux conventions réelles du dépôt. N’introduis pas une deuxième architecture parallèle si des structures équivalentes existent déjà.

Hiérarchie minimale :

```text
Concours → session → parcours → cycle → spécialité → épreuve → blueprint → domaine → sous-domaine → compétence → microcompétence
```

Prévoir au minimum :

- `competitions` ;
- `sessions` ;
- `tracks` ;
- `cycles` ;
- `specialties` ;
- `exams` ;
- `blueprints` ;
- `domains` ;
- `subdomains` ;
- `competencies` ;
- `microcompetencies` ;
- `sources` ;
- `questions` ;
- `remediations` ;
- `mirrorQuestions` ;
- `reviewSchedules` ;
- `editorialStatuses`.

Chaque objet doit posséder un identifiant stable et lisible, non dépendant de son index dans un tableau.

## 10.1 Schéma minimal d’une question

Adapte les noms au code existant, mais ne perds aucun champ sémantique :

```js
{
  id: "CRMEF-FR-DID-Q001",
  competitionId: "crmef-qualification-enseignants",
  sessionId: "crmef-2025-11",
  trackId: "crmef-secondary",
  specialtyId: "crmef-secondary-french",
  examId: "CRMEF-FR-DID-2025",
  blueprintId: "BP-CRMEF-FR-DID-2025",
  domainId: "fr-did-didactique",
  subdomainId: "FR-DID-CONCEPTS",
  competencyId: "...",
  microcompetencyId: "...",
  language: "fr",
  cognitiveLevel: "understand",
  editorialDifficulty: 2,
  stem: "...",
  context: null,
  choices: [
    { id: "A", text: "..." },
    { id: "B", text: "..." },
    { id: "C", text: "..." },
    { id: "D", text: "..." }
  ],
  correctChoiceId: "B",
  rationales: {
    A: "Pourquoi cette option peut attirer et pourquoi elle est fausse.",
    B: "Pourquoi cette réponse est exacte.",
    C: "Pourquoi cette option peut attirer et pourquoi elle est fausse.",
    D: "Pourquoi cette option peut attirer et pourquoi elle est fausse."
  },
  probableConfusion: {
    label: "...",
    cautiousMessage: "Cette réponse peut traduire une confusion entre…"
  },
  remediationId: "REM-...",
  mirrorQuestionId: "MIR-...",
  delayedReview: {
    enabled: true,
    policyId: "default-spaced-review"
  },
  blueprintSourceId: "SRC-CRMEF-2025-FR-DID",
  contentSources: [
    {
      ref: "SOURCE_CONTENU_A_VALIDER",
      location: null,
      verificationStatus: "missing"
    }
  ],
  provenance: "ai_assisted",
  editorialStatus: "a_verifier",
  eligibleForDiagnostic: false,
  eligibleForSimulation: false,
  authorId: null,
  reviewerId: null,
  validatedBy: null,
  revisedAt: "2026-08-08"
}
```

## 10.2 Statuts éditoriaux

Utilise ou mappe les statuts suivants :

- `draft` — Brouillon ;
- `a_verifier` — À vérifier ;
- `reviewed` — Relu par un second expert ;
- `pedagogically_validated` — Validé pédagogiquement ;
- `published` — Publié ;
- `retired` — Retiré.

Ne confonds pas `published` avec le simple fait qu’un objet existe dans `data.js`.

---

# 11. Audit obligatoire des questions existantes

La banque actuelle contient environ 26 questions de démonstration. Avant d’ajouter de nouvelles questions :

1. inventorie-les toutes ;
2. conserve leurs identifiants ;
3. détecte les doublons réels ;
4. rattache chaque question au concours, à l’épreuve, au domaine, au sous-domaine et à la microcompétence lorsqu’un rattachement est justifié ;
5. vérifie la présence des quatre justifications ;
6. vérifie la source de la correction ;
7. attribue un statut éditorial ;
8. détermine son éligibilité au diagnostic et aux simulations ;
9. signale les domaines officiels non couverts ;
10. conserve comme contenu complémentaire les questions utiles mais hors blueprint.

Points de vigilance :

- Les questions sur la législation ou l’organisation administrative ne sont pas explicitement couvertes par le blueprint 2025 de Sciences de l’éducation. Classe-les comme `complementary`, sauf preuve contraire.
- Les questions sur l’évaluation ou la docimologie doivent être rattachées avec prudence à la PPO, à l’APC ou au contenu complémentaire.
- Les questions de didactique centrées uniquement sur lecture et production écrite ne couvrent pas l’ensemble du blueprint officiel.
- La spécialité française ne doit pas être découpée en catégories trop générales : utilise les dix sous-domaines officiels ci-dessus.
- Une question sans rattachement solide est exclue d’une simulation fondée sur le blueprint.

Produis dans le compte rendu final un tableau d’audit synthétique : identifiant, ancien classement, nouveau classement, statut, source, éligibilité et motif.

---

# 12. Banque initiale à constituer

## 12.1 Volume éditorial

L’objectif de ce chantier est d’obtenir au minimum **20 questions de démonstration éligibles par épreuve**, soit 60 questions pour les trois épreuves, en comptant les questions existantes qui passent l’audit.

Ce volume de 20 questions est un **choix éditorial Naja7i**, retenu parce qu’il permet de refléter exactement les pourcentages du blueprint. Il ne doit jamais être affiché comme le nombre officiel de questions du concours.

Si les questions existantes éligibles ne permettent pas de compléter un domaine, crée seulement le nombre nécessaire pour atteindre la répartition ci-dessous. Ne duplique pas une question sous un nouvel identifiant.

## 12.2 Répartition cible — Sciences de l’éducation, 20 questions

| Sous-domaine | Poids officiel | Cible éditoriale |
|---|---:|---:|
| `SE-PSY-DEV` | 20 % | 4 |
| `SE-PSY-LEARN` | 20 % | 4 |
| `SE-PED-PPO-APC` | 15 % | 3 |
| `SE-PED-METHODS` | 15 % | 3 |
| `SE-SOC-EDU` | 15 % | 3 |
| `SE-SOC-GROUP` | 15 % | 3 |

## 12.3 Répartition cible — Didactique du français, 20 questions

| Sous-domaine | Poids officiel | Cible éditoriale |
|---|---:|---:|
| `FR-DID-CHAMP` | 10 % | 2 |
| `FR-DID-CONCEPTS` | 20 % | 4 |
| `FR-DID-CURRICULUM` | 10 % | 2 |
| `FR-DID-RESOURCES` | 20 % | 4 |
| `FR-DID-PPO-CONCEPTS` | 5 % | 1 |
| `FR-DID-PPO-FOUNDATIONS` | 10 % | 2 |
| `FR-DID-APC-CONCEPTS` | 5 % | 1 |
| `FR-DID-APC-FOUNDATIONS` | 10 % | 2 |
| `FR-DID-ACTIVE` | 10 % | 2 |

## 12.4 Répartition cible — Spécialité Langue française, 20 questions

| Sous-domaine | Poids officiel | Cible éditoriale |
|---|---:|---:|
| `FR-SPEC-LING` | 15 % | 3 |
| `FR-SPEC-GRAM` | 15 % | 3 |
| `FR-SPEC-STYL` | 10 % | 2 |
| `FR-SPEC-DISCOURSE` | 10 % | 2 |
| `FR-SPEC-HIST-MYTH` | 5 % | 1 |
| `FR-SPEC-NOVEL` | 10 % | 2 |
| `FR-SPEC-NARRATIVE` | 10 % | 2 |
| `FR-SPEC-THEATRE` | 10 % | 2 |
| `FR-SPEC-POETRY` | 10 % | 2 |
| `FR-SPEC-MAGHREB` | 5 % | 1 |

## 12.5 Qualité du lot

Dans chaque lot de 20 :

- varier les niveaux cognitifs : restitution, compréhension, application et analyse ;
- inclure au moins six questions contextualisées par un extrait, une situation ou un exemple ;
- éviter que la position de la bonne réponse soit prévisible ;
- utiliser des distracteurs plausibles correspondant à des confusions identifiables ;
- éviter les doubles négations ;
- éviter les questions purement décoratives sur des dates ou des noms lorsqu’elles n’évaluent aucune compétence ;
- rendre les quatre options comparables en longueur et en précision ;
- vérifier qu’une seule option est défendable ;
- ne pas traduire mécaniquement une question française vers l’arabe.

Pour Sciences de l’éducation, une version française et une version arabe sont deux contenus éditoriaux distincts. Ne marque une traduction comme disponible que si elle existe réellement et a été relue.

---

# 13. Remédiations, questions miroir et rappels

## 13.1 Remédiation

Chaque remédiation doit être courte et ciblée :

- notion à corriger ;
- distinction essentielle ;
- exemple ;
- contre-exemple ou piège ;
- source ;
- microcompétence associée.

Elle ne doit pas être un cours générique sans rapport avec l’erreur.

## 13.2 Question miroir

La question miroir doit :

- tester la même microcompétence ;
- utiliser un autre cas, un autre extrait ou une autre opération cognitive ;
- ne pas reprendre le même énoncé avec des mots remplacés ;
- posséder ses propres options et explications ;
- être enregistrée comme un objet lié, pas comme du texte inaccessible dans une justification.

Une réussite immédiate à la question miroir ne suffit pas à déclarer la compétence consolidée.

## 13.3 Rappel différé

Le rappel différé doit pointer vers une autre question ou une autre situation. Utilise la politique de répétition déjà présente dans le prototype. Si aucune politique n’existe, crée une configuration éditoriale simple sans prétention scientifique et clairement paramétrable.

Ne code pas une date absolue dans les données catalogue. Calcule l’échéance à partir de la tentative du candidat.

---

# 14. Intégration fonctionnelle

## 14.1 Catalogue public

Le catalogue doit permettre de :

- voir les parcours primaire et secondaire ;
- filtrer par cycle et spécialité ;
- distinguer `Disponible en démonstration` et `Prochainement disponible` ;
- ouvrir la fiche CRMEF Français secondaire ;
- consulter les trois épreuves, leurs coefficients, durées, langues et domaines ;
- voir les badges « Source officielle », « Couverture partielle », « Démonstration » et « À vérifier » selon les données réelles.

Ne laisse pas un bouton actif mener vers une page vide. Pour un parcours à venir, proposer uniquement une fiche informative ou une liste d’attente si celle-ci existe déjà dans le produit.

## 14.2 Entraînement

Les questions doivent pouvoir être filtrées par :

- épreuve ;
- domaine ;
- sous-domaine ;
- microcompétence ;
- niveau cognitif ;
- statut éditorial ;
- éligibilité ;
- rappel arrivé à échéance.

Chaque activité affiche l’épreuve et le sous-domaine officiel. La correction affiche la justification de chaque option, la confusion probable, la source et la remédiation.

## 14.3 Diagnostic et simulations

- Utiliser uniquement les questions `eligibleForDiagnostic: true` dans le diagnostic.
- Utiliser uniquement les questions `eligibleForSimulation: true` dans les simulations.
- Si la banque validée ne couvre pas le blueprint, afficher : « Simulation d’entraînement — couverture partielle du programme officiel ».
- Afficher le nombre de questions réellement posées et la couverture obtenue.
- Ne jamais afficher une probabilité de réussite.
- Ne jamais appeler la série de 20 questions « format officiel ».

## 14.4 Persistance

Les tentatives doivent survivre au rechargement. Une double soumission ou un rechargement ne doit pas créer deux tentatives identiques. Réutilise la couche de persistance existante et prévois une migration sûre des données locales déjà enregistrées.

---

# 15. Ordre d’exécution obligatoire

1. Inspecter l’arborescence, `src/data.js`, le routeur, les composants de quiz et la persistance.
2. Exécuter le build initial et noter son résultat.
3. Inventorier et auditer les questions existantes.
4. Proposer le mapping exact entre le modèle existant et le modèle cible.
5. Créer le registre des sources.
6. Créer le catalogue global CRMEF et les statuts de disponibilité.
7. Créer le parcours Français secondaire et les trois épreuves.
8. Créer les trois blueprints et vérifier mathématiquement que chaque somme vaut 100 %.
9. Reclasser les questions existantes sans changer leurs identifiants.
10. Créer les microcompétences nécessaires.
11. Compléter progressivement la banque jusqu’aux cibles éditoriales, sans doublons.
12. Ajouter remédiations, questions miroir et règles de rappel.
13. Brancher les données sur le catalogue, l’entraînement, le diagnostic et les simulations existants.
14. Vérifier les badges, sources, filtres et avertissements.
15. Vérifier la persistance et l’idempotence des tentatives.
16. Tester les anciennes routes et fonctionnalités.
17. Exécuter `node build.js` et reconstruire `index.html` selon le processus existant.

Ne t’arrête pas après un plan ou une maquette. Implémente et vérifie les comportements demandés.

---

# 16. Contrôles automatisés ou assertions à ajouter

Ajoute des contrôles adaptés à l’outillage existant pour garantir au minimum :

- unicité de tous les identifiants ;
- existence de chaque référence entre question, épreuve, blueprint, domaine et microcompétence ;
- exactement quatre options par QCM ;
- une seule bonne réponse ;
- une justification non vide pour chacune des quatre options ;
- somme des poids de chaque blueprint égale à 100 ;
- aucune question non validée éligible par erreur aux simulations ;
- aucun parcours `coming_soon` présenté comme disponible ;
- aucun nombre officiel de questions renseigné ;
- aucune probabilité de réussite ;
- aucune duplication de tentative pour une même soumission idempotente ;
- conservation des identifiants des questions existantes.

Si le dépôt ne possède aucun framework de tests, crée au minimum un script de validation des données exécuté par le build. N’introduis pas une lourde dépendance uniquement pour ces assertions.

---

# 17. Vérifications manuelles obligatoires

Vérifie au minimum :

1. catalogue public en français ;
2. affichage en arabe et sens RTL des éléments concernés ;
3. thème clair et thème sombre ;
4. affichage mobile ;
5. ouverture du parcours Français secondaire ;
6. affichage séparé des trois épreuves ;
7. exactitude des coefficients et durées ;
8. choix de langue pour Sciences de l’éducation ;
9. correction avec quatre justifications ;
10. source et statut éditorial visibles ;
11. remédiation et question miroir accessibles ;
12. reprise après rechargement ;
13. absence de double tentative ;
14. simulation séparée par épreuve ;
15. avertissement de couverture partielle ;
16. anciennes routes toujours fonctionnelles ;
17. absence d’erreur JavaScript bloquante dans la console.

---

# 18. Compte rendu final attendu de Claude

À la fin, fournis un compte rendu court et factuel contenant :

- commit ou état Git de départ ;
- fichiers modifiés et créés ;
- modèle de données retenu ;
- catalogue réellement intégré ;
- routes ou écrans touchés ;
- nombre de questions existantes auditées ;
- nombre de questions reclassées par épreuve et domaine ;
- nombre de nouvelles questions créées ;
- nombre de questions réellement éligibles au diagnostic ;
- nombre de questions réellement éligibles aux simulations ;
- questions ou domaines laissés à vérifier ;
- sources de contenu manquantes ;
- données encore simulées ;
- tests et contrôles exécutés ;
- résultat exact de `node build.js` ;
- éventuelles limites restantes.

Ne présente jamais comme « implémentée » une fonctionnalité seulement maquettée ou une question seulement générée sans intégration réelle.

---

# 19. Définition de terminé

Le chantier est terminé uniquement si :

- le catalogue global CRMEF est présent ;
- Français secondaire est le seul parcours marqué disponible dans ce lot ;
- ses trois épreuves sont séparées ;
- les trois blueprints correspondent exactement aux matrices de ce document ;
- les questions existantes ont été auditées sans changement d’identifiant ;
- chaque question utilisable possède quatre justifications, une source, une microcompétence, une remédiation et une question miroir ;
- aucune question IA n’est faussement marquée comme validée ;
- les simulations excluent les questions non éligibles ;
- la couverture partielle est visible ;
- les anciennes fonctionnalités sont préservées ;
- le build final réussit.

Commence maintenant par inspecter le dépôt et exécuter le build initial, puis passe directement à l’implémentation.
