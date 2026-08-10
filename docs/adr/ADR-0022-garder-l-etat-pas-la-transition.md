# ADR-0022 — Garder l'état, pas seulement la transition

**Statut :** accepté · 9 août 2026
**Contexte :** contre-revue PAS-11. Quatre blocants, dont trois fondés.

---

## Le motif commun aux trois défauts fondés

Chaque garde protégeait **un moment** au lieu de protéger **un état**.

| Garde | Moment protégé | Ce qui restait ouvert |
|---|---|---|
| Appartenance → rôle | L'écriture de la `membership` | Attacher la permission au rôle **ensuite** |
| Gel du contenu publié | La modification d'une ligne publiée | **Sortir** de l'état publié, puis modifier |
| Publication | Le passage à `published` | Retirer la **source** qui l'avait fondée |

Dans les trois cas, il suffisait de changer l'ordre des opérations.

**Règle :** une garde placée sur une transition doit être doublée d'une garde
sur l'état, sinon il suffit de revenir en arrière. Toute vérification du type
« au moment où X arrive, Y doit être vrai » appelle sa jumelle : « tant que X
est vrai, Y ne peut pas cesser de l'être ».

## Décisions

### 1. Une question publiée ne sort que vers `retired`

Le gel excluait `status` de la comparaison — nécessaire pour permettre le
retrait — mais n'imposait aucune destination. `published → draft` rouvrait
toutes les colonnes à l'écriture suivante.

La sortie est bornée, le retrait doit être horodaté, et une question retirée
ne se réactive pas : elle a été présentée à des candidats, son contenu doit
rester lisible tel qu'il a été vu.

### 2. Les sources d'une question publiée sont gelées

Les options l'étaient déjà (PAS-10, migration 000300). Les sources ne
l'étaient pas — alors que c'est la source vérifiée qui **conditionne** la
publication d'une question de diagnostic. La retirer ensuite invalidait
rétroactivement le contrôle : la question restait servie sans que rien ne fonde
plus sa bonne réponse.

### 3. Une permission réservée ne s'attache pas à un rôle déjà distribué

Le trigger du PAS-9 refusait `platform_only` sur un rôle **d'organisme**. Il ne
disait rien d'un rôle **global** — or `candidat` est global et attribué dans
tous les organismes. Attacher `tenants.manage` à `candidat` après coup accordait
la permission plateforme à toutes les appartenances existantes, qui ne
repassent jamais dans le trigger d'appartenance.

Le contrôle porte désormais sur l'état : refus si le rôle possède une
appartenance hors plateforme, quel que soit l'ordre.

**Défense de profondeur :** le résolveur exclut en outre `platform_only` hors
du tenant plateforme. Deux barrières sur un chemin d'escalade de privilèges,
c'est le minimum — un test éprouve la seconde en corrompant volontairement la
table pour contourner la première.

### 4. La course réponse / soumission — troisième tentative, et pourquoi les deux premières ont échoué

`answer()` lisait l'état de la tentative **hors transaction**, puis ne
verrouillait que l'item. `submit()` verrouillait la tentative. Les deux ne se
disputaient donc jamais la même ligne, et aucun verrou ne pouvait les
sérialiser.

`answer()` verrouille et **relit** désormais la tentative en tête de
transaction. L'ordre est identique dans les deux méthodes — tentative, puis
items — pour qu'aucun interblocage ne remplace la course.

**Ce qui a permis à l'erreur de survivre deux lots :** mon test était
séquentiel. Il soumettait entièrement, puis appelait `answer()`. Il vérifiait
« une tentative close refuse une réponse », jamais l'entrelacement qui produit
le défaut.

**Règle adoptée :** un invariant de concurrence se teste avec deux connexions
et un verrou détenu, jamais par une séquence. Un test séquentiel sur une course
donne une confiance strictement imméritée.

## Un constat écarté, avec sa preuve

La contre-revue affirme qu'aucun trigger ne protège `question_options`. Le
trigger `question_options_published_frozen` existe depuis le PAS-10 —
migration `0001_01_01_000300`, ligne 174 — et la migration du PAS-11 ne le
supprime pas : elle ne retire que `questions_published_frozen`, lignes 226 et
270.

Le volet « options » du BLOC-4 est donc infondé ; le volet « sources » est
fondé et corrigé. Un test vérifie désormais explicitement que la garde des
options est active, pour que la question ne se repose plus.

## Sur le dispositif

Trois revues consécutives, dix-huit constats, dix-sept fondés. Aucun n'a été
trouvé par les garde-fous internes.

Le motif est stable et vaut d'être nommé : je vérifie que le code fait ce que
j'ai voulu ; l'audit vérifie qu'aucun ordre d'opérations ne le contourne. La
seconde question ne se pose pas depuis l'intérieur, parce qu'elle suppose
d'ignorer l'intention.
