# ADR-0030 — Registre des capacités atomiques

**Statut :** proposé · intégré au correctif documentaire 0A du 21 août 2026
**Dépend de :** ADR-0010, ADR-0025, ADR-0027, ADR-0029
**Amende :** ADR-0010, ADR-0025 et ADR-0029

## Problème

Les quatre capacités présentes dans `AccessGrant` ne suffisent pas à exprimer
les trois paliers décidés. Trois d'entre elles ne sont encore appliquées par
aucun chemin applicatif et `certification.take` peut être proposée dans
l'administration alors que la fonction n'existe pas.

Une capacité agrégée telle que `COACHING_AUTOMATIQUE_COMPLET` ajouterait une
seconde source de vérité : une table devrait expliquer quelles capacités fines
elle implique. Elle lierait en outre l'autorisation à un nom commercial
modifiable.

## Décision

Les capacités sont **atomiques**, fermées en code et indépendantes. Un pack est
une composition versionnée de capacités ; son code, son nom, son prix et son
palier ne participent jamais à l'autorisation.

Il n'existe ni capacité `COACHING`, `PREMIUM` ou `PACK_600`, ni table
d'inclusion entre capacités. L'autorisation est l'union des capacités portées
par les droits actifs couvrants. Aucune capacité n'en implique une autre.

## Registre normatif

Le registre compte exactement **neuf capacités**, dont **huit
commercialisables**.

| Code | Définition métier | Point de contrôle | Commercialisable | État au 21 août 2026 |
|---|---|---|:---:|---|
| `questions.answer` | Consommer des questions de la banque. Le gratuit la porte avec une enveloppe ; les payants sans enveloppe. | Service d'un item dans les composeurs | Oui | Nouvelle |
| `corrections.cause` | Révéler sans limite la cause d'une erreur ; sans elle, le quota F03 reste applicable. | Champ de correction | Oui | Existante et appliquée |
| `annales.practice` | S'entraîner sur des questions issues d'anciens sujets. | Filtre du sélecteur d'items | Oui | Nouvelle, déclarée mais non appliquée |
| `series.targeted` | Ouvrir une série ciblée sur un domaine choisi. | Démarrage de l'entraînement ciblé | Oui | Existante, non appliquée |
| `simulator.full` | Ouvrir un examen blanc composé et chronométré. | Démarrage d'une simulation | Oui | Existante, non appliquée |
| `mastery.detail` | Restituer la maîtrise au-delà de la racine, notamment par matière et chapitre. | Profondeur de la restitution de maîtrise | Oui | Nouvelle |
| `remediation.plan` | Restituer l'ordonnance de remédiation et ses motifs. | Champ d'ordonnance | Oui | Nouvelle |
| `memory.sessions` | Restituer les échéances et ouvrir une séance mémoire. | Champs et gestes mémoire | Oui | Nouvelle |
| `certification.take` | Passer une attestation de niveau. | Aucun tant que le lot 11 n'est pas livré | **Non** | Existante, fonction absente |

Les quatre codes existants ne sont ni renommés ni migrés. En particulier, le
code persistant est `corrections.cause`, et non le nom de constante
`CAUSE_REVEAL` ni une variante `cause.reveal`.

## Commercialisation

La liste des capacités commercialisables est un sous-ensemble déclaré en code,
distinct du registre complet. Elle ne peut pas être modifiée par
l'administration. `certification.take` en reste exclue jusqu'à livraison de sa
fonction et de sa règle métier.

L'admin commerciale compose librement les packs à partir des huit capacités
commercialisables. Elle ne crée pas de code technique et ne peut pas rendre
vendable une capacité exclue. Les quotas restent des objets distincts :
l'admin pédagogique définit des profils bornés et justifiés ; l'admin
commerciale sélectionne un profil sans saisir une valeur arbitraire.

## Libellés et descriptions bilingues

Le code d'une capacité est un identifiant technique non éditable. Son libellé,
sa description et sa position sont des données de référentiel éditables en
français et en arabe.

La migration qui déclare une capacité doit aussi semer son entrée de
référentiel avec `libelle_fr` et `libelle_ar`. Une traduction non encore relue
porte un marqueur `a_relire`, sans devenir vide. Le serveur refuse la
composition ou la mise en vente d'une version contenant une capacité dont le
libellé ou la description requis est absent dans une des deux langues.

Un code brut ne parvient jamais au candidat. Il peut apparaître dans la seule
surface d'administration comme diagnostic d'une donnée de référentiel
incomplète ; cette anomalie empêche la mise en vente.

Les textes commerciaux du pack restent distincts de ce référentiel. Leur nom
et leur description FR/AR appartiennent à la version d'offre et versionnent
selon l'ADR-0026.

## Cas particulier des annales

`annales.practice` gouverne un **contenu**, non une route. Un compte qui ne la
porte pas reçoit une série dont les annales sont absentes ; il ne reçoit pas un
refus révélant leur existence.

La capacité est déclarée au lot 3A mais **n'est appliquée à aucun sélecteur**
avant un audit nommé du marqueur d'origine. Cet audit doit établir deux mesures
égales à zéro :

1. questions importées sans trace d'origine ;
2. questions non importées portant une trace d'origine.

Tant que l'une des deux mesures n'est pas nulle, `annales.practice` reste dans
le registre mais sans effet applicatif. Aucun marqueur approximatif ne devient
une frontière commerciale.

## Supersessions documentaires

Cet ADR ferme l'arbitrage de l'ADR-0025 sur une éventuelle capacité agrégée et
fait autorité sur le registre. Il confirme l'amendement de l'ADR-0029 : aucune
table d'inclusion n'existe.

Il supersède aussi les exemples historiques `corrections.full` et
`coach.access` de l'ADR-0010. Ils n'ont jamais fait partie du registre livré et
ne doivent pas être employés comme alias.

Il remplace les registres proposés hors dépôt lorsqu'ils contredisent les
points suivants : neuf capacités, huit commercialisables,
`certification.take` hors commerce et `annales.practice` déclarée mais non
appliquée avant audit.

## Tests d'acceptation

- Le registre contient exactement neuf codes et la liste commercialisable
  exactement huit.
- Composer une offre avec `certification.take` est refusé côté serveur avec un
  motif explicite.
- Une offre composée de trois capacités crée trois droits distincts, jamais un
  droit agrégé.
- Aucun contrôleur ou service d'autorisation ne teste un code de pack, un prix
  ou un nom commercial.
- Une capacité sans référentiel FR/AR complet ne peut appartenir à une version
  mise en vente.
- Sans `annales.practice`, le sélecteur exclut les annales sans fermer la route ;
  ce test n'est activé qu'après audit vert du marqueur.
