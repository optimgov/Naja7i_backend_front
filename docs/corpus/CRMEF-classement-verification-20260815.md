# Classement des annales par domaine — note de vérification

**Fichier :** `CRMEF-classement-domaines-20260815.csv`
**Origine :** classement produit hors dépôt, à partir de `CRMEF-extraction-20260815.md`.
**Vérifié le 15 août 2026**, indépendamment du rapport de production.

## Ce qui a été contrôlé, et comment

La clé de jointure est le couple `(sujet, numero_question)`. Elle a été vérifiée
par **empreinte MD5 par bloc**, calculée des deux côtés — corpus et classement —
sur les clés triées. Ce n'est pas un décompte : deux blocs peuvent avoir le même
nombre de lignes et des numéros différents.

| Contrôle | Résultat |
|---|---|
| Lignes de données | 1 411 (+ en-tête) |
| Doublons sur (sujet, numéro) | 0 |
| Valeurs de `sujet` inconnues du corpus | 0 |
| Blocs dont l'empreinte de clés correspond exactement | **27 sur 28** |
| Codes de nœud hors de l'arbre en base | 0 |
| `motif` manquant là où il est obligatoire | 0 |
| `confiance` renseignée sur une ligne sans code | 0 |

## Les deux anomalies retenues

**1. Numérotation non uniforme dans le corpus lui-même.** Les fiches de question
sont titrées tantôt `##### Q1`, tantôt `##### Q 1` (bloc `2023_SPEC_anglais`),
tantôt `##### Q01` (bloc `2023_SPEC_philosophie`). Le classement a canonisé en
`Q<entier sans zéro initial>` — et il a eu raison. **L'import doit appliquer
exactement la même canonisation**, sinon la jointure échouera en silence sur
deux blocs entiers : elle ne lèvera pas d'erreur, elle ne trouvera simplement
rien. C'est le mode de défaillance à surveiller.

**2. Une fiche du corpus couvre cinq questions.** Dans `2023_SPEC_anglais`, le
corpus porte un titre `##### Q 80 à Q 84` — une seule fiche pour cinq numéros.
Le classement la représente par la ligne `Q80` ; `Q81` à `Q84` n'existent donc
nulle part. Si l'import éclate cette fiche en cinq questions, quatre seront sans
ligne de classement. Sans conséquence aujourd'hui — ce bloc est hors périmètre —
mais l'import doit **signaler** le cas, pas l'absorber.

## Ce que le fichier contient réellement

| | Lignes |
|---|---|
| **Classées** (code de nœud attribué) | **213** |
| Non attribuées sur علوم التربية, avec motif propre à la question | 32 |
| Hors périmètre — didactique et savoirs de spécialité, arbre non fourni | 1 166 |

Répartition des 213, et les blocs d'où elles viennent :

| Nœud | Lignes | | Bloc | Lignes classées |
|---|---|---|---|---|
| SE-PSY-LEARN | 45 | | 2023_SCED_frar_p01-12 | 54 |
| SE-PSY-DEV | 37 | | 2025_SCED_college_qualifiant | 53 |
| SE-PED-PPO-APC | 36 | | 2023_SCED_frar_p13-24 | 44 |
| SE-SOC-EDU | 34 | | 2025_SCED_primaire | 21 |
| SE-SOC-GROUP | 30 | | 2025_DIDA_SCED_p31-44 | 21 |
| SE-PED-METHODS | 26 | | 2024_SCED_primaire | 20 |
| SE-PSY *(domaine)* | 3 | | | |
| SE-PED *(domaine)* | 2 | | | |

Confiance déclarée sur les 213 : **152 haute · 42 moyenne · 19 basse.**

## Le point à traiter à l'import

**Cinq lignes sont rattachées à un domaine, pas à un sous-domaine** (3 sur
`SE-PSY`, 2 sur `SE-PED`). Or `TaxonomyProfile.min_depth_for_publication = 1`
exige le rattachement au sous-domaine pour publier. Ces cinq brouillons seront
donc importables mais non publiables en l'état — c'est le comportement correct,
et il n'y a rien à assouplir. Elles rejoignent simplement la file de relecture
avec un motif de plus.

## Ce que ce fichier ne dit pas

Il donne un **domaine**, jamais une **bonne réponse**. Le corpus n'en contient
aucune d'officielle. Une question classée reste un brouillon non publiable tant
qu'un relecteur humain n'a pas établi la réponse, justifié chaque option et
étiqueté la cause de chaque distracteur.
