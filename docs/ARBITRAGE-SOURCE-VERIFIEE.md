# Arbitrage à rendre — la source vérifiée conditionne-t-elle la publication ?

**Ce document ne tranche pas.** Il pose les deux issues et leur coût, comme
demandé. La décision est un choix de produit, pas un choix technique.

## Le fait, mesuré

Recette humaine du 17 août, sur une instance qui tourne et une base neuve. Une
question a été menée de bout en bout dans le back-office, **sans jamais
renseigner de source**. Elle a été publiée sans obstacle.

État vérifié en base après publication :

```
statut = published    source_id = NULL
eligible_for_diagnostic = false    eligible_for_simulation = false
```

Ce n'est donc pas la publication qui est conditionnée par la source, c'est
l'**éligibilité au diagnostic**. La modale de publication le dit d'ailleurs
correctement : « Éligible au diagnostic — Exige une cause sur chaque
distracteur, une remédiation et **une source vérifiée** ».

Le guide de visite, lui, affirme l'inverse :

> En Valideur : valider puis publier — **la publication refuse si la source
> n'est pas vérifiée** (registre des sources).

`scripts/demonstration/VISITE.md`. C'est ce texte que le pilote lit et montre.

## Issue A — la règle est vraie, le code doit la tenir

La publication refuse toute question dont la source n'est pas vérifiée, quelle
que soit son éligibilité.

**Ce que cela apporte.** Une seule règle à retenir, et elle est forte : rien
n'est servi à un candidat sans une source vérifiée par un membre de l'équipe.
La promesse est simple à énoncer devant un partenaire, et elle ne demande pas
d'expliquer la distinction entre publier et rendre éligible.

**Ce que cela coûte.**

- Le contrôle `QuestionIntegrityChecker::publicationIssues()` doit exiger la
  source **hors** du bloc d'éligibilité. Une dizaine de lignes.
- **Le coût réel est ailleurs** : toute question aujourd'hui publiée sans source
  devient non conforme. Il faut les compter avant de décider — sur la base de
  démonstration il y en a une, mais c'est une base de recette. La question à
  poser au corpus réel est : combien de questions publiées ont `source_id IS
  NULL` ?
- Les fixtures de test qui publient sans source devront attacher une source
  vérifiée. Le motif est déjà présent dans plusieurs classes, la migration est
  mécanique.
- **Un effet de bord à ne pas manquer** : une question hors diagnostic — par
  exemple servie seulement en examen blanc — serait elle aussi bloquée. Est-ce
  voulu ? Si oui, la règle est cohérente. Si non, l'issue A est trop large et
  il faut la restreindre, ce qui la rapproche de l'issue B.

## Issue B — la règle n'a jamais été voulue, la documentation ment

Le code est juste : la source conditionne l'éligibilité au diagnostic, parce que
c'est là qu'elle sert — une cause d'erreur adossée à une source non vérifiée
n'est pas une cause, c'est une opinion. Une question publiée non éligible ne
prétend rien de tel.

**Ce que cela apporte.** Rien à changer au produit. La distinction est déjà
correctement dite à l'endroit qui compte : dans la modale de publication, au
moment du geste.

**Ce que cela coûte.**

- Corriger `VISITE.md`, et vérifier que la même affirmation ne se trouve pas
  ailleurs — le document du parcours candidat, les supports commerciaux.
- **Le coût invisible, et c'est le vrai** : une règle annoncée puis démentie par
  le produit a déjà été montrée à des tiers. Ce n'est pas une faute de frappe,
  c'est une promesse. Si le pilote l'a répétée en démonstration, il faut le
  savoir et le reprendre avec lui, pas seulement corriger un fichier.

## Ce qui ne dépend pas de l'arbitrage

Dans les deux cas, le chiffre à établir d'abord est le même : **combien de
questions publiées n'ont pas de source vérifiée dans le corpus réel ?** Il
décide du coût de l'issue A et mesure l'exposition de l'issue B.

```sql
select count(*) from questions
where status = 'published' and source_id is null;
```
