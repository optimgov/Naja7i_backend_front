# ADR-0021 — Ce qui constitue une frontière de sécurité, et ce qui n'en est pas une

**Statut :** accepté · 9 août 2026
**Contexte :** revue PAS-9 / PAS-10. Six constats, dont deux réouvrant des
défauts que le lot précédent prétendait avoir fermés.

---

## L'erreur de raisonnement à ne pas répéter

Le PAS-10 a retiré `status`, `published_at` et les drapeaux d'éligibilité du
`$fillable` de `Question`, et a présenté ce retrait comme la correction du
défaut de publication.

**C'était faux.** `$fillable` filtre l'assignation de masse depuis un tableau.
Il n'intervient pas sur `Question::whereKey($id)->update([...])`, qui est du
Eloquent parfaitement ordinaire. Le contournement ne demandait ni SQL brut, ni
astuce.

Et le trigger de gel n'examinait que les lignes dont l'**ancien** statut était
déjà `published` — donc jamais au moment de la publication elle-même. Les deux
protections annoncées laissaient exactement passer le scénario qu'elles étaient
censées interdire.

### Règle

| Mécanisme | Ce qu'il protège | Ce qu'il ne protège pas |
|---|---|---|
| `$fillable` | L'assignation de masse depuis une requête HTTP | `update()`, `forceFill()`, SQL, imports |
| Un service | Le chemin qui l'appelle | Tous les autres |
| Une contrainte ou un trigger | **Tous les chemins** | Rien |

**Une frontière de sécurité est en base, ou n'existe pas.** Le reste est de
l'ergonomie de développement : utile pour produire un message lisible, jamais
pour garantir un invariant.

## Décisions

### 1. La publication est gardée au moment de la transition

`assert_question_publishable` s'exécute sur INSERT et UPDATE, dès que le
statut visé est `published`. Il vérifie l'état de départ, le valideur distinct
de l'auteur, le nombre d'options, l'unicité de la bonne réponse, la présence
des justifications, l'étiquetage des distracteurs si le diagnostic est visé, et
l'existence d'une source de contenu vérifiée.

Cette logique **duplique** celle de `QuestionIntegrityChecker`. La duplication
est assumée et documentée : le service produit des messages lisibles pour
l'éditeur, la base garantit qu'aucun chemin ne l'esquive. Un test vérifie
qu'ils refusent les mêmes cas.

### 2. Le gel procède par liste blanche, pas par énumération

Le trigger compare la ligne entière convertie en JSON, moins cinq champs
autorisés. Ajouter une colonne à `questions` la gèle donc automatiquement.

C'est l'inverse du réflexe habituel, et c'est délibéré : **l'oubli doit
produire une protection, pas un trou.** L'ancienne version énumérait six
colonnes et en laissait passer douze.

### 3. L'éligibilité ne s'élargit jamais après publication

La restreindre est permis — c'est ainsi qu'on retire une question du
diagnostic. L'élargir contournerait les contrôles de publication : il faut
republier.

### 4. L'attribution d'un rôle est gardée au niveau de l'appartenance

Le PAS-9 gardait l'attachement permission → rôle. Il ne gardait pas
rôle → appartenance, ce qui laissait ouverte une escalade directe : attribuer
le rôle global `super_admin` à une appartenance d'organisme donnait les dix-neuf
permissions, dont `tenants.manage` et `refunds.issue`.

Règle : un rôle d'organisme n'est attribuable que dans le sien ; un rôle de
plateforme n'est attribuable dans un organisme que s'il n'est pas `is_staff` et
ne porte aucune permission réservée. Le rôle `candidat` reste donc universel,
`super_admin` reste confiné.

### 5. L'unité rare est ce qu'il faut verrouiller

Le décompte de quota était atomique **par réponse** alors que la ressource rare
est **le quota**. Avec une unité restante, deux réponses différentes passaient.

L'unité est désormais réservée d'abord, par un `UPDATE ... WHERE revealed_total
< quota`. Si la réservation échoue, rien d'autre ne se produit. Si le marquage
de la réponse échoue ensuite, l'unité est rendue.

### 6. Un lot ne se déclare pas appliqué sans consommateur

Le PAS-9 annonçait « permissions fines appliquées » alors que le résolveur
n'était référencé par aucun contrôleur. Le mécanisme existait, l'autorisation
réelle reposait toujours sur les rôles.

Règle : un mécanisme d'autorisation n'est déclaré livré que lorsqu'au moins une
action réelle en dépend, avec un test HTTP prouvant le refus **et**
l'acceptation. Ce lot livre `POST /admin/questions/{uuid}/publish`.

## Sur le dispositif d'audit

C'est la deuxième revue consécutive dont les constats sont tous valides, et la
première où un lot correctif s'est révélé inopérant sur le défaut qu'il visait.

Le motif est constant : je vérifie que le code fait ce que j'ai voulu, l'audit
vérifie qu'aucun chemin ne l'esquive. Ce sont deux questions différentes, et la
seconde ne se pose pas depuis l'intérieur.
