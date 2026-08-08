# ADR-0014 — La taxonomie se rattache à l'épreuve, et chaque donnée porte sa provenance

**Statut :** accepté · 8 août 2026 — **amende l'ADR-0012**
**Contexte :** transposition des descriptifs officiels CRMEF, session de
novembre 2025 (`docs/regles/CRMEF-2025-referentiel.md`).

---

## Ce que le référentiel officiel a révélé

L'ADR-0012 rattachait le profil de taxonomie à la **famille de concours**. Les
descriptifs officiels montrent que c'est faux : chaque **épreuve** a sa propre
matrice de domaines et de poids.

| Épreuve | Coefficient | Durée | Langue | Matrice |
|---|---:|---:|---|---|
| Sciences de l'éducation | 8 | 120 min | arabe ou français au choix | 3 domaines, 6 sous-domaines |
| Didactique du français | 12 | 120 min | français | 2 blocs, 9 sous-domaines |
| Spécialité français | 20 | 240 min | français | 2 domaines, 10 sous-domaines |

Rattacher la taxonomie à la famille aurait forcé à fusionner ces trois
matrices. Un simulateur construit dessus aurait mélangé trois épreuves qui se
passent séparément, avec des coefficients allant du simple au triple — et
aurait produit un score sans rapport avec le concours réel.

**Deuxième révélation, plus embarrassante :** le prototype traitait « sciences
de l'éducation », « didactique » et « spécialité » comme trois *piliers* de
taxonomie. Ce sont trois *épreuves*. La confusion venait de ce que ces trois
mots désignent aussi trois champs de savoir — mais dans le concours, ce sont
trois copies distinctes, à trois moments distincts.

## Décision

### 1. `taxonomy_profiles` et `competency_nodes` se rattachent à `exams`

Une épreuve porte son profil, ses domaines et ses sous-domaines. Le trigger de
cohérence devient « même épreuve » au lieu de « même famille ».

Le reste de l'ADR-0012 tient sans changement : arbre à profondeur libre,
vocabulaire de niveaux repris du cadre d'origine, six niveaux au maximum.

### 2. Un niveau `tracks` s'intercale entre famille et spécialité

Le CRMEF n'est pas un concours unique. Primaire bilingue, primaire amazigh et
secondaire ont des épreuves différentes. Sans ce niveau, les treize spécialités
du secondaire seraient mélangées aux matières du primaire.

### 3. Les poids officiels sont vérifiables

Les enfants d'un nœud somment au poids de leur parent ; les racines somment à
100. Deux tests l'imposent sur chaque matrice. Une erreur de saisie dans un
poids officiel fait échouer la CI plutôt que de fausser silencieusement un
futur simulateur.

### 4. Chaque donnée porte sa provenance

Quatre valeurs, jamais mélangées :

| Provenance | Signification |
|---|---|
| `official` | Figure explicitement dans un descriptif officiel, avec sa source |
| `observed` | Constatée dans le contenu existant du dépôt |
| `editorial` | Choix de Naja7i pour l'apprentissage |
| `unverified` | À valider par un humain |

**Un choix éditorial ne doit jamais s'afficher comme une caractéristique
officielle du concours.** C'est la règle qui protège la crédibilité de la
plateforme auprès des candidats — et celle qui la détruirait si elle était
enfreinte une seule fois de façon visible.

### 5. Ce qui n'est pas dans la source reste nul

Nombre de questions, barème détaillé, seuil d'admission, note éliminatoire,
règle de navigation, coefficients des spécialités non documentées : tous nuls.

Le référentiel interdit explicitement de déduire le coefficient d'une
spécialité à partir d'une autre. Les douze spécialités secondaires sans
descriptif ont donc une fiche catalogue, et **aucune épreuve** — plutôt qu'une
épreuve aux métadonnées devinées.

### 6. Deux sources différentes sur une question

Un descriptif officiel prouve le **périmètre et les poids**. Il ne prouve pas
la bonne réponse à une question de contenu. Le modèle de question (PAS-6)
distinguera donc `blueprint_source_id` de `content_sources`, avec un statut de
vérification propre. Une question sans source de contenu fiable sera exclue du
diagnostic et des simulations.

## Conséquences

- L'ADR-0012 se lit avec cet amendement : partout où il dit « famille de
  concours » à propos de la taxonomie, lire « épreuve ».
- Un simulateur ne mélange jamais deux épreuves. La formule « format officiel »
  est interdite tant que le nombre de questions n'est pas établi par une source.
- Le back-office devra afficher la provenance de chaque donnée à l'éditeur, et
  le frontend au candidat lorsqu'elle n'est pas officielle.
