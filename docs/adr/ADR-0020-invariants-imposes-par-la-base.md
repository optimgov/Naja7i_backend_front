# ADR-0020 — Un invariant détectable n'est pas un invariant imposé

**Statut :** accepté · 9 août 2026
**Contexte :** revue externe du 9 août, huit constats recevables sur dix lots.

---

## Le défaut commun aux huit constats

Ils ont tous la même forme. Une règle est énoncée dans un ADR, un service sait
la vérifier — et **rien ne l'impose sur le chemin d'écriture réel**.

| Règle annoncée | Ce qui l'imposait |
|---|---|
| L'historique juridique ne se modifie pas | Un commentaire de classe |
| Une question publiée ne se réécrit pas | Un paragraphe d'ADR |
| Une question non validée ne se publie pas | Un service que personne n'appelait |
| Un jeton sert une seule fois | Une lecture avant écriture |
| Le quota se décompte une fois | Une lecture avant écriture |
| Le test d'architecture attrape les contournements | Une recherche de chaînes littérales |

C'est exactement l'écart G10 que la méthode du projet prétend traquer, commis
dans les lots censés le fermer. Et il n'a pas été vu de l'intérieur.

## La cause profonde : des tests qui visaient le mauvais objet

`BanqueDeQuestionsTest` appelait `QuestionIntegrityChecker` directement, au lieu
de tenter la mutation interdite. Il prouvait que le contrôleur **sait dire
non** ; jamais que le système **empêche**. Un test vert donnait donc une
confiance que le code ne méritait pas.

**Règle adoptée :** un test d'invariant vise le chemin d'écriture le plus
direct — assignation de masse, SQL brut, commande — jamais le service qui
détecte. Si l'invariant tient par le chemin le plus court, il tient par les
autres.

## Décisions

### 1. Ce qui doit survivre à tous les chemins vit en base

Trigger PostgreSQL, contrainte ou index. Un service se contourne par un import,
une commande console, une console Tinker ; une contrainte non.

Sont désormais imposés en base : l'ajout seul des actes juridiques, le gel du
contenu des questions publiées, l'unicité tenant-aware, la consommation unique
des jetons et du quota.

### 2. Ce qui relève d'un processus vit dans un service unique

Les transitions éditoriales passent par `QuestionTransitionService`, et les
champs correspondants **sortent de `$fillable`**. Même discipline que
`tenant_id` sur les tables isolées, pour la même raison : ce qui est assignable
en masse finit par être assigné.

### 3. Les unicités d'une table isolée incluent le tenant

`attempts` et `mastery_scores` portaient `tenant_id` avec des unicités qui
l'ignoraient. Un même compte membre de deux organismes se heurtait à l'index de
l'un depuis l'autre — une ligne invisible sous le scope courant bloquant une
insertion légitime.

Le défaut n'était pas exploitable : un seul tenant existe. Il l'aurait été au
premier contrat B2B, c'est-à-dire au pire moment.

**Règle générale :** toute contrainte d'unicité sur une table portant
`tenant_id` doit l'inclure. À vérifier à chaque nouvelle table isolée.

### 4. Les séquences « lire puis écrire » sont proscrites sur un invariant

Un `doesntExist()` suivi d'un `create()` n'est pas une garantie d'unicité, et un
test de drapeau avant transaction n'est pas un décompte fiable. On emploie un
`UPDATE ... WHERE condition` et l'on compte les lignes affectées : la base
arbitre, l'application constate.

### 5. La garde architecturale cherche des chemins, pas des noms

Chercher `DB::table('memberships')` était une course perdue d'avance :
`DB::table($variable)` y échappe. La garde interdit désormais **toute**
primitive d'accès bas niveau dans `app/`, hors liste blanche nommée et
justifiée.

**Limite assumée, écrite pour ne pas être oubliée :** ce contrôle reste
syntaxique. Il ne voit ni le SQL construit à l'exécution, ni un appel traversant
une abstraction tierce. La défense de profondeur demeure la Row-Level Security
PostgreSQL, différée au gate B2B (ADR-0002) — et cette revue en renforce la
justification.

### 6. La preuve juridique porte sur le document exact

`hasAcceptedCurrent()` comparait le type et la version, sans la langue. Une
acceptation des CGU françaises satisfaisait donc les CGU arabes de même
version : la plateforme affirmait qu'un candidat avait accompli un acte sur un
texte qu'il n'avait jamais reçu.

La comparaison porte désormais sur `legal_document_id`. Un document, pas une
version homonyme.

## Ce que cette revue enseigne sur le dispositif

Les garde-fous internes n'ont attrapé aucun de ces huit défauts. Ils vérifient
que le code fait ce qu'on lui demande ; ils ne vérifient pas que ce qu'on lui
demande couvre ce qu'on a promis.

C'est la valeur propre du regard externe, et la raison de le maintenir à chaque
palier — pas seulement quand un doute apparaît.
