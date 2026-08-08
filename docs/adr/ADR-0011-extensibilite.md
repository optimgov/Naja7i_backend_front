# ADR-0011 — Extensibilité : ce qu'on rend extensible, et ce qu'on refuse de généraliser

**Statut :** accepté · 8 août 2026
**Contexte :** exigence OptimGov : « l'architecture doit permettre d'étendre la
plateforme avec d'autres fonctionnalités. »

---

## Pourquoi cet ADR commence par une mise en garde

« Extensible » est l'exigence la plus facile à mal servir. Poussée trop loin,
elle produit un moteur de règles générique, une base de données en clés-valeurs,
un système de greffons — c'est-à-dire un logiciel que plus personne ne sait
configurer, pas même celui qui l'a écrit. Le cadrage du 4 août met déjà en garde
contre la généralité prématurée ; cet ADR applique la même prudence.

La question utile n'est donc pas « comment tout rendre paramétrable », mais :
**qu'est-ce qui coûte peu aujourd'hui et très cher plus tard ?**

---

## Ce qu'on rend extensible

### 1. Les événements de domaine — le mécanisme le plus rentable

Les moments significatifs émettent un événement : `AttemptSubmitted`,
`ExamCompleted`, `MasteryRecalculated`, `SubscriptionActivated`.

Recalculer la maîtrise, alimenter l'analytique, déclencher un rappel espacé,
notifier un centre partenaire — chacune de ces fonctions est un abonné, pas une
ligne ajoutée dans le code de la tentative.

**Conséquence concrète :** ajouter « Rendez-vous Mémoire » (F07) plus tard ne
demandera pas de modifier le code des tentatives. C'est exactement ce que
signifie « étendre sans casser ».

Deux règles : la soumission d'une réponse reste **transactionnelle**, l'abonné
travaille en asynchrone ; et un abonné qui échoue ne fait jamais échouer
l'action d'origine.

### 2. Des modules à frontières explicites, dans un monolithe

`app/Domain/{Catalogue, Evaluation, Maitrise, Commerce, Editorial}`.

Un module n'accède jamais aux modèles d'un autre : il passe par une classe de
service publique. Un test d'architecture le vérifie — le même mécanisme que
celui qui protège déjà l'isolation tenant.

Ce n'est pas une préparation aux microservices. C'est ce qui permet de remplacer
le moteur de maîtrise sans toucher au reste, et de savoir où poser un nouveau
domaine.

### 3. Les types de question, ouverts par contrat

Un type de question déclare comment il se valide, se corrige et se score, via
une interface `QuestionType`. La banque stocke `kind` plus une charge utile
JSONB validée par type.

Ajouter le QCM à réponses multiples, l'appariement ou le vrai/faux justifié =
une classe. Pas de migration, pas de modification de l'existant.

**Limite assumée :** un type dont la mécanique d'interaction est réellement
différente — SimuClasse en graphe à branches, préparation à l'oral — demandera
du code frontend. Aucune architecture ne rend gratuit un nouveau type
d'interaction.

### 4. Le catalogue et les blueprints, en données pures

Ajouter Médecine, l'ENCG ou un concours professionnel est une opération de
données : famille, spécialités, sessions, compétences, blueprint (sections,
quotas, durée, barème, navigation). **Jamais de code.**

C'est la promesse la plus visible pour vous, et celle sur laquelle l'échec
serait le plus coûteux : un concours qui exige un développeur transforme votre
plateforme en prestation de service.

### 5. Les règles par concours, en configuration

Composition des séries adaptatives, quotas du compte gratuit, seuils
d'affichage : ces valeurs varient d'un concours à l'autre. Elles vivent en
configuration rattachée au concours, avec des valeurs par défaut à la
plateforme. Jamais en dur dans le code.

### 6. Le contrat d'API, additif

Dans une version majeure, on **ajoute** — on ne retire ni ne renomme. Une
suppression exige une version. Sans cette règle, chaque évolution backend casse
le frontend, et l'extensibilité devient théorique.

---

## Ce qu'on refuse explicitement

| Refusé | Pourquoi |
|---|---|
| **Moteur de règles générique** | Personne ne saurait le configurer, vous compris. Les règles vivent dans des fiches lisibles (`docs/regles/`) et du code testé. |
| **Schéma en clés-valeurs (EAV)** | Détruit l'intégrité relationnelle et rend toute requête analytique impraticable. Le JSONB ciblé et validé suffit. |
| **Système de greffons** | Un produit à un seul éditeur n'a pas besoin d'une architecture de greffons. Coût immédiat, bénéfice nul. |
| **Microservices** | Les seuils d'évolution du cadrage §9.4 ne sont pas atteints. On extrait un domaine le jour où il ralentit réellement les déploiements. |
| **Colonnes spéculatives** | Une colonne inutilisée pendant un an sera mal conçue quand elle servira. |

---

## La règle de décision, pour les cas non prévus

Face à une demande d'extensibilité future, une seule question :

> **Est-ce que le coût de l'ajouter après coup est disproportionné par rapport
> au coût de le poser maintenant ?**

Si oui, on le pose (les événements, les frontières de modules, le contrat de
droit d'accès). Si non, on attend d'avoir deux ou trois cas réels — la
variabilité réelle est presque toujours différente de celle qu'on imagine.

---

## Tests d'acceptation

- Un module qui accède directement au modèle d'un autre fait échouer la CI.
- Un abonné d'événement qui lève une exception ne fait pas échouer la
  soumission d'une réponse.
- Ajouter un type de question ne demande aucune migration.
- Créer un concours complet avec son blueprint se fait sans déploiement.
- Retirer un champ du contrat d'API sans changer de version fait échouer le
  test contractuel.
