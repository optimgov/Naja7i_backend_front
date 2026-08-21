# ADR-0032 — La frontière du paramétrable

**Statut :** proposé · intégré au reliquat documentaire du 22 août 2026
**Dépend de :** ADR-0011, ADR-0027, ADR-0030, ADR-0031
**Ferme :** la question Q-06 du cahier d'évolution v1.1 — « ce qui reste **non**
paramétrable, nommément »

## Problème

La plateforme se vend paramétrable, et d'autres concours suivront. Poussée trop
loin, l'extensibilité produit pourtant un logiciel que plus personne ne sait
configurer — l'ADR-0011 en a déjà posé la mise en garde. Sans frontière écrite,
chaque arbitrage se rejoue : celui-ci est-il un réglage d'administration, une
donnée, ou du code ? Deux réponses différentes au même type de question, à six
mois d'écart, et le produit porte deux mécanismes pour une seule idée.

Q-06 demande donc l'inverse d'une liste de fonctionnalités : la décision qui
empêche le moteur de règles, et elle s'écrit **avant** le code.

## Décision

> **Est paramétrable tout ce dont une valeur fausse produit un mauvais produit.
> Reste en code tout ce dont une valeur fausse produit un état que le domaine
> interdit.**

Une valeur fausse dans un calibrage donne un produit médiocre qu'un
administrateur corrige. Une valeur fausse dans un invariant donne un état que
le domaine n'admet pas — deux bonnes réponses, un droit qui n'ouvre rien, un
score sans évidence — et aucun écran ne devrait pouvoir l'atteindre.

| Nature | Exemple | Où |
|---|---|---|
| **Valeur de calibrage** | échelle de difficulté, plage de temps, quota, paliers mémoire, seuil d'évidence | **Paramètre borné**, admin pédagogique |
| **Libellé lu par un humain** | nom d'une capacité, d'un niveau de taxonomie, d'un sujet | **Donnée**, admin, FR et AR |
| **Composition commerciale** | quelles capacités dans quel pack, quelle portée, quel prix | **Donnée versionnée**, admin commerciale |
| **Identifiant d'autorisation** | code de capacité, type de portée | **Code** — un droit qui n'ouvre rien est pire qu'un droit absent |
| **Invariant de domaine** | une seule bonne réponse, quatre yeux, 404 jamais 403, borne 1,0 de l'évitement | **Code**, jamais réglable |

**Le cas de la difficulté est l'exemple canonique.** La valeur `2` n'est pas
fausse : elle est **arbitraire et non calibrée**. Elle appartient donc au
premier rang — un paramètre borné que l'admin pédagogique règle — et non à une
constante d'import qui la promeut en donnée de production.

## La règle du badge — ce qui distingue « paramétrable » de « pré-rempli en silence »

Un paramètre livré avec une valeur de départ est une valeur d'ARCHITECTE. Elle
est utile — elle évite qu'un lot ultérieur ait à inventer un nombre — mais elle
n'a été validée par personne, et un produit qui l'oublie finit par vendre les
conventions de son développeur.

> **Une valeur posée par un architecte n'entre jamais en production sans un
> geste humain qui la confirme, et le système sait toujours distinguer les
> deux.**

Le mécanisme est celui du corpus : tant qu'un champ figure dans
`valeurs_par_defaut`, il est une valeur provisoire et **se lit comme telle**.
Il n'est jamais projeté au premier niveau de l'objet de production — le
transfert d'une question écrit `difficulty = null` tant qu'aucun humain n'a
confirmé — et le système retire l'entrée dès qu'un humain modifie le champ. La
valeur reste disponible, visible, modifiable en masse par filtre ; elle ne
devient une donnée de production que par un geste.

La règle vaut au-delà du corpus. Elle gouverne le futur registre des paramètres
pédagogiques (lot 8) : chaque paramètre y porte son nom, son type, ses bornes
**justifiées**, sa valeur de départ, son badge « à confirmer », son journal, et
son effet **en avant seulement**.

## Ce que le paramétrage n'atteint jamais

La frontière n'est pas une préférence de style, et trois invariants la rendent
opposable :

- **La borne 1,0 de l'évitement**, les **quatre yeux** et **404 jamais 403**
  restent en code. Aucun réglage ne les approche, aucun organisme ne les
  desserre.
- **Un score ne sort jamais sans son volume d'évidence.** C'est structurel, pas
  une convention d'affichage.
- **Aucune prédiction de réussite**, nulle part, même dérivée.

Les listes fermées en code — capacités atomiques (ADR-0030), capacités
commercialisables, types de portée (ADR-0031), unités et fenêtres de quota
(ADR-0027) — relèvent du quatrième rang : ce sont des identifiants
d'autorisation. Les rendre administrables permettrait de créer un droit que
rien n'ouvre, ou une unité que rien ne compte.

## Conséquences

- **Face à une valeur non tranchée, la première réponse à examiner est « en
  faire un paramètre borné ».** Un blocage en attente d'arbitrage et une
  constante en dur sont l'un et l'autre des échecs de cette règle ; une valeur
  de départ marquée « à confirmer » ne l'est pas.
- Une borne se pose **avec sa justification écrite**, opposable et relisible
  sans nous. Le précédent est en base : `quota_profiles` refuse une borne dont
  la justification fait moins de vingt caractères, et refuse de la déplacer en
  conservant la raison de l'ancienne.
- Un paramètre ne vaut **qu'en avant**. Le changer ne réécrit jamais ce qui a
  été vendu ni ce qui a été servi : l'instantané de quota dans la version
  d'offre est l'application directe de cette conséquence.
- Ajouter une entrée au cinquième rang — un invariant — se fait par ADR, pas
  par écran.

## Tests d'acceptation

- Un paramètre pédagogique de production porte une valeur, deux bornes et deux
  justifications écrites ; la base refuse la borne injustifiée.
- Une valeur de départ non confirmée n'apparaît jamais comme donnée de
  production : l'objet transféré porte `null`, pas la valeur du badge.
- Un code de capacité, un type de portée, une unité de quota ne se créent par
  aucun écran d'administration.
- Déplacer un paramètre ne modifie aucun droit déjà accordé ni aucune version
  d'offre déjà composée.
