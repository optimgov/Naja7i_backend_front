# ADR-0023 — Un trigger qui lit sans verrou ne garantit rien

**Statut :** accepté · 9 août 2026
**Contexte :** contre-revue PAS-12. Trois blocants, tous fondés.

---

## Un mode de défaillance nouveau

Les trois revues précédentes portaient sur des **chemins oubliés** : une
écriture qui n'empruntait pas la porte gardée. Celle-ci porte sur un **ordre
d'exécution** — et c'est une classe de défaut plus difficile à voir, parce que
chaque opération prise seule est correcte.

Sous `READ COMMITTED`, niveau d'isolation par défaut de PostgreSQL :

```
T1 lit l'état, le juge conforme, écrit — sans valider.
T2 lit l'état d'AVANT T1, le juge conforme, écrit.
Les deux valident. L'état combiné est interdit.
```

Aucun trigger n'a rien vu, parce qu'aucun n'a jamais observé l'autre. C'est
l'anomalie d'écriture, et deux de nos invariants y étaient exposés.

**Règle :** un trigger qui LIT pour décider doit VERROUILLER ce qu'il lit.
Sinon il vérifie un passé, pas un présent.

## Décisions

### 1. Ordre de verrouillage stable : parent, puis enfants

Les gardes enfants verrouillent la ligne `questions` avant de lire son statut.
Le contrôle de publication verrouille les lignes `question_options` et
`question_sources` avant de les compter — la ligne parente étant déjà
verrouillée par l'UPDATE en cours.

Les deux côtés respectent donc le même ordre. C'est ce qui fait qu'ils se
**sérialisent** au lieu de s'interbloquer : deux transactions qui verrouillent
dans un ordre opposé produisent un interblocage, pas une garantie.

Quand une option change de parent, les deux parents sont verrouillés par
identifiant croissant, pour la même raison.

### 2. Les deux triggers de rôle se donnent rendez-vous sur la ligne `roles`

Le trigger d'appartenance et celui d'attachement de permission verrouillent la
même ligne `roles` avant de contrôler. C'était le point de rendez-vous
manquant : sans lui, attribuer un rôle global dans un organisme et lui attacher
une permission réservée pouvaient se valider simultanément.

### 3. Une question retirée est gelée comme une question publiée

Le trigger des options ne se déclenchait que sur `published`. Le PAS-12 a gelé
la ligne `questions` en `retired` sans mettre à jour ce trigger : les options
d'une question retirée redevenaient modifiables.

Une question retirée a été présentée à des candidats. Son contenu doit rester
lisible tel qu'il a été vu — le retrait la sort du service, il n'efface pas son
histoire.

### 4. Les deux parents sont contrôlés, pas seulement le nouveau

Les gardes n'examinaient que `COALESCE(NEW.question_id, OLD.question_id)`. Sur
un UPDATE de `question_id`, NEW l'emporte : déplacer une option d'une question
publiée vers un brouillon ne contrôlait que le brouillon. La question gelée
perdait une option sans qu'aucune ligne `questions` ne bouge.

**Généralisation :** toute garde portant sur une association doit contrôler
l'ancien parent **et** le nouveau. Un déplacement est une suppression et un
ajout, jamais l'un des deux seulement.

## Comment on teste un invariant de concurrence

Un test séquentiel ne prouve rien — c'était déjà la leçon du PAS-12. Un test à
deux connexions ne suffit pas non plus s'il ne discrimine pas : celui du PAS-12
levait bien un délai d'attente, mais provoqué par une écriture ultérieure, pas
par la prise de verrou qu'il prétendait vérifier.

**Protocole retenu :**

1. La seconde connexion ouvre une transaction et écrit sans valider.
2. La première tente son écriture avec un `lock_timeout` court.
3. Un délai dépassé prouve que les deux se disputent la même ligne.
4. **Mutation obligatoire** : retirer le verrou du code doit faire virer le
   test au rouge. Sans cette vérification, on ignore ce que le test mesure.

Le point 4 n'est pas une précaution de zèle. Sur les trois derniers lots, deux
tests verts prouvaient autre chose que ce qu'ils annonçaient.

## Sur le dispositif d'audit

Quatre revues, vingt et un constats, vingt fondés. Aucun trouvé de l'intérieur.

Le motif s'est déplacé : d'abord des chemins d'écriture oubliés, maintenant des
ordres d'exécution possibles. Les deux ont la même racine — je raisonne sur ce
que le code fait, l'audit raisonne sur ce qu'il n'empêche pas.
