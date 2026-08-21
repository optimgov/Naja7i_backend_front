# ADR-0031 — Portées typées et règle de contenance des droits

**Statut :** proposé · intégré au correctif documentaire 0A du 21 août 2026
**Dépend de :** ADR-0002, ADR-0012, ADR-0014, ADR-0020, ADR-0029
**Développe :** la normalisation des portées de l'ADR-0029

## Problème

`AccessGrantRecord` porte actuellement un `scope_uuid` nullable sans type. La
résolution sait seulement reconnaître une portée globale ou l'UUID exact. Elle
ne peut donc ni distinguer un UUID d'épreuve d'un UUID de nœud, ni faire couvrir
un chapitre par un droit posé sur sa matière ou son épreuve.

Multiplier un octroi sur chaque descendant serait une erreur : l'ajout d'un
nœud laisserait le contrat incomplet et chaque prolongation devrait modifier un
ensemble de lignes.

## Décision

Une portée est le couple `(scope_type, scope_uuid)`. L'énumération fermée de la
première version contient :

| `scope_type` | Objet désigné |
|---|---|
| `null` | La plateforme entière |
| `audience` | Une catégorie de public |
| `filiere` | Une filière du catalogue |
| `exam_family` | Une famille d'épreuves |
| `exam` | Une épreuve |
| `competency_node` | Un nœud de compétence, à toute profondeur |

`track`, `specialty`, `matiere` et `chapitre` ne sont pas des types de portée.
`Track` est une jointure de catalogue sans intention commerciale propre.
`Specialty` est une spécialité de concours, pas une matière scolaire, et aucune
offre du périmètre actuel ne requiert ce type. Il pourra être ajouté par une
nouvelle décision lorsqu'une offre réelle l'exigera.

Une matière et un chapitre sont exclusivement des `competency_node`. Leur nom
vient des niveaux FR/AR de `TaxonomyProfile`, jamais d'une énumération
d'autorisation.

## Portée globale et contrainte de cohérence

`scope_type IS NULL AND scope_uuid IS NULL`, et seulement ce couple, signifie
« toute la plateforme ». Un couple mi-nul n'a aucun sens et doit être refusé
par une contrainte en base :

```sql
CHECK (
    (scope_type IS NULL AND scope_uuid IS NULL)
    OR (scope_type IS NOT NULL AND scope_uuid IS NOT NULL)
)
```

Le serveur vérifie également que l'objet existe, correspond au type annoncé et
n'est pas retiré au moment où une version d'offre le référence.

## Règle de contenance

Un droit couvre une demande lorsque sa portée est la portée demandée ou l'un de
ses ancêtres ; la portée nulle est la racine de toutes les chaînes.

Pour un nœud `N` rattaché à une épreuve `E`, la chaîne contient, du plus fin au
plus large :

1. `(competency_node, N)` puis chacun de ses ancêtres lu depuis `path` ;
2. `(exam, E)` ;
3. `(exam_family, F)` ;
4. `(filiere, Fi)` ;
5. `(audience, A)` lorsque le catalogue rattache la famille à ce public ;
6. `(null, null)`.

Un nœud historique rattaché directement à une famille suit la même chaîne à
partir de `(competency_node, N)`, puis `exam_family`, `filiere`, `audience` et
la racine. Aucun `specialty` n'est injecté dans la chaîne.

La résolution construit cette chaîne une fois et interroge les droits actifs
en une seule requête. Il n'existe ni récursion par capacité ni requête par
niveau.

## Effets de la contenance

- une portée globale couvre tout ;
- une filière couvre ses familles, leurs épreuves et leurs nœuds ;
- une famille couvre ses épreuves et leurs nœuds ;
- une épreuve couvre tous ses nœuds ;
- un nœud couvre le nœud lui-même et ses descendants ;
- un chapitre ne couvre pas sa matière, et une matière ne couvre pas sa sœur ;
- deux portées dont aucune n'est ancêtre de l'autre coexistent sans fusionner
  ni prolongation croisée.

L'autorisation est l'union des droits actifs couvrants. Une portée large peut
autoriser un geste tandis qu'une portée fine reste traçable et reprend effet si
la large expire avant elle. Les dates et origines ne fusionnent jamais.

## Catalogue global et tenant

L'ADR-0002 rend le catalogue, les questions et les taxonomies globaux. La table
`access_grants` est elle aussi globale : le droit suit le compte ; son
`origin_tenant_id` est une trace et ne conditionne pas sa validité.

La résolution d'une portée ne doit donc pas inventer un `tenant_id` sur un
objet de catalogue ni filtrer un nœud global par l'organisme émetteur. Elle
respecte néanmoins l'isolation tenant de trois façons :

1. elle exige le compte authentifié et ne lit que ses droits ;
2. les routes d'activité continuent d'être résolues par `TenantContext` selon
   l'ADR-0002 ;
3. `origin_tenant_id` ne sert jamais à élargir la chaîne, et une ressource
   d'activité appartenant à un autre tenant reste introuvable.

Si une future décision rend une taxonomie propre à un organisme, elle devra
amender l'ADR-0002 et le présent ADR avant toute donnée : un simple filtre
tenant ajouté à la résolution actuelle contredirait le caractère global du
catalogue.

## Évolution de la taxonomie

Un droit sur un `competency_node` suit l'UUID du nœud, pas sa position. Un
déplacement dans le même arbre est accepté et journalisé avec le nombre de
droits actifs concernés.

Déplacer un nœud vers une autre épreuve est refusé tant qu'un droit actif vise
ce nœud ou l'un de ses descendants. Retirer un nœud rend le droit inerte sans
supprimer sa trace. Renommer un niveau de `TaxonomyProfile` n'affecte aucun
droit.

## Octroi, quotas et performance

Honorer une version crée exactement une ligne `AccessGrantRecord` par couple
`(capacité, portée)`, quelle que soit la taille du sous-arbre. Il n'existe
jamais un octroi par descendant.

Une enveloppe de quota appartient au droit et donc à sa portée. Plusieurs
enveloppes sur des portées disjointes ne s'additionnent pas. Le débit choisit
uniquement parmi les droits couvrants, selon l'ADR-0029.

L'index cible est `(user_id, capability, scope_type, scope_uuid)`, complété par
la condition d'activité. La chaîne d'ascendance est construite une fois par
ressource demandée et réutilisée pour toutes les capacités vérifiées durant la
requête HTTP. Aucun droit n'est mis en cache dans la session.

## Supersessions documentaires

Cet ADR fait autorité sur les portées du lot 3A. Il remplace les propositions
hors dépôt qui incluent `specialty` dans la première version ou qui demandent
un filtre tenant sur le catalogue global. Il conserve leur règle de contenance,
leur contrainte de couple complet, la non-multiplication des octrois et la
portée du quota par droit.

## Tests d'acceptation

- Les couples `(type, null)` et `(null, uuid)` sont refusés par la base.
- Un droit d'épreuve couvre tous ses nœuds et aucun nœud d'une autre épreuve.
- Un droit de matière couvre ses chapitres, jamais sa sœur ; un chapitre ne
  couvre jamais son parent.
- Une vérification sur un nœud profond effectue une seule requête sur les
  droits.
- Honorer une capacité sur une épreuve de quarante nœuds crée une seule ligne
  d'octroi.
- Une matière ou un chapitre est toujours représenté par
  `competency_node`, jamais par `specialty`.
- L'organisme d'origine d'un droit ne modifie ni la chaîne du catalogue global
  ni sa validité pour le compte.
- Deux enveloppes sur des portées distinctes ne sont jamais additionnées.
