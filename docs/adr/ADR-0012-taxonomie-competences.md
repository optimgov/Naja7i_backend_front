# ADR-0012 — Taxonomie de compétences : un arbre, profilé à la création du concours

**Statut :** accepté · 8 août 2026
**Contexte :** décision D02 de `NAJAH-INV-001`, tranchée par OptimGov le 8 août.
**Dépend de :** ADR-0002 (tenant = organisation), ADR-0011 (extensibilité),
ADR-0013 (vocabulaire : organisme, concours, contributeur).

---

## Le problème

Le prototype a deux niveaux : pilier → compétence. Le document fonctionnel en
demande quatre : pilier → domaine → compétence → microcompétence. Le prompt
destiné aux experts, lui, produit déjà des microcompétences (`CG1.1`).

Mais la vraie contrainte est ailleurs, et elle est structurante :

> Naja7i s'appuiera sur des **contributeurs** qui fournissent les cadres de
> référence et les annales — souvent les mêmes personnes qui conçoivent les
> épreuves réelles. **Ces cadres ne partageront ni la même profondeur, ni le
> même vocabulaire.**

Un cadre CRMEF parle de piliers et de compétences. Un cadre médecine post-bac
parlera peut-être d'unités et d'objectifs, sur trois niveaux. Un concours
professionnel aura sa propre logique.

Coder quatre tables `piliers`, `domaines`, `competences`, `microcompetences`
condamnerait donc à une migration à chaque nouveau concours dont le cadre
diffère — c'est-à-dire à transformer chaque ouverture de concours en projet de
développement.

## Décision

### 1. Une seule table de nœuds, hiérarchie libre

`competency_nodes` : identifiant, parent, profondeur, code, libellé FR/AR,
famille de concours. Un nœud sans parent est une racine.

La profondeur n'est pas fixée par le schéma. Quatre niveaux pour le CRMEF,
trois pour un autre concours, deux pour un troisième : c'est la même table.

### 2. Un profil de taxonomie, défini **à la création du concours**

Chaque famille de concours porte un profil qui déclare :

- **le nombre de niveaux** ;
- **le nom de chaque niveau**, dans les deux langues — « pilier », « domaine »,
  « compétence », « microcompétence » pour le CRMEF ; ce qu'emploie le cadre de
  référence du concours pour un autre ;
- **le niveau minimal de rattachement** exigé pour qu'une question soit
  publiable.

Ce profil se renseigne dans le back-office **au moment où le concours est
créé**, au même titre que ses spécialités, ses sessions et le barème de son
blueprint. C'est une opération de données, jamais de code (ADR-0011 §4).

Ouvrir une nouvelle famille — Médecine, ENCG, ENSA, COPS — consiste donc à
renseigner un formulaire, puis à charger le cadre de référence fourni par le
contributeur, dans son vocabulaire d'origine.

### 3. La taxonomie appartient au concours, ni au contributeur ni à l'organisme

Point d'attention, car la confusion est facile et coûteuse :

- Un **contributeur** fournit un cadre de référence ; il n'en devient pas
  propriétaire.
- Un **organisme** achète des accès pour ses membres ; il ne possède aucune
  taxonomie et n'en redéfinit aucune.

La taxonomie est rattachée à la **famille de concours**, objet de catalogue —
donc globale, sans `tenant_id` (ADR-0002, ADR-0013).

Deux contributeurs travaillant sur le même concours partagent la même
taxonomie. S'ils sont en désaccord sur le cadre, c'est un arbitrage éditorial,
pas une duplication technique.

### 4. Le CRMEF garde quatre niveaux

Décision OptimGov : pilier → domaine → compétence → microcompétence. Le niveau
« domaine » est conservé malgré son absence des documents existants ; il devra
donc être défini par les responsables pédagogiques avant la production de
contenu.

### 5. La maîtrise se calcule en remontant l'arbre

Aucun score n'est stocké par niveau. La maîtrise se mesure aux nœuds où les
questions sont rattachées, puis s'agrège vers les parents.

Conséquence utile : le mécanisme fonctionne quelle que soit la profondeur, sans
code conditionnel par concours. Et la règle du volume d'évidence (R04) s'agrège
naturellement — un parent hérite de l'évidence de ses enfants.

## Ce que ce choix coûte

**L'interface doit gérer une profondeur variable.** Un tableau de bord conçu
pour quatre niveaux figés est plus simple à écrire. C'est le prix assumé de ne
pas redévelopper à chaque partenariat.

**La comparaison entre familles devient impossible.** Un score de maîtrise
CRMEF et un score médecine ne se comparent pas — mais ils ne le devraient pas
davantage avec des niveaux figés. La contrainte est rendue explicite plutôt que
cachée.

**Le profil doit être rempli sérieusement à la création.** Un concours créé
avec un profil bâclé produira une taxonomie inexploitable. Le back-office doit
donc rendre ce formulaire difficile à survoler.

## Ce qu'on ne fait pas

- **Pas de profondeur illimitée en pratique.** Le profil est borné à six
  niveaux. Au-delà, aucun cadre de référence réel ne l'exige, et l'interface
  deviendrait illisible.
- **Pas de taxonomie propre à un organisme.** Un client ne redéfinit pas le
  cadre d'un concours national pour ses seuls membres.
- **Pas de rattachement multiple.** Une question porte un nœud et un seul.
  Autoriser plusieurs rattachements rendrait tout calcul de maîtrise ambigu.

## Tests d'acceptation

- Créer un concours à trois niveaux nommés autrement se fait sans migration.
- Une question rattachée au-dessus du niveau minimal exigé par le profil est
  refusée à la publication.
- La maîtrise d'un nœud parent s'agrège depuis ses enfants, à toute profondeur.
- Un nœud ne peut pas devenir son propre ancêtre.
- Supprimer un nœud portant des questions publiées est refusé.
- La taxonomie reste identique quel que soit l'organisme dont relève le
  candidat qui consulte le concours.
