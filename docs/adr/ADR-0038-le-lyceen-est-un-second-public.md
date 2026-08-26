# ADR-0038 — Le lycéen est un second public, pas un quatrième concours

**Statut :** proposé · intention propriétaire du 26 août 2026, conséquences à arbitrer
**Précise :** ADR-0014 (une matrice par épreuve), ADR-0027 et ADR-0033 (le gratuit
est un essai), ADR-0031 (portées typées des droits)

## L'intention, telle qu'elle a été prononcée

Le propriétaire a énoncé le 26 août 2026 que le produit doit **aussi** servir
des lycéens, pour maîtriser leurs cours et préparer leurs examens de classe.
Il a précisé la nature de cet usage, et c'est la précision qui compte :
**opérationnel quotidien, toute l'année — pas un sprint comme le CRMEF.**

Ce document ne tranche pas cette intention : elle est prononcée. Il expose ce
qu'elle coûte, ce qu'elle ne coûte pas, et les trois points qui demandent un
arbitrage avant qu'une ligne de code ne parte dans un sens ou dans l'autre.

## Ce qui transfère, et qui est même mieux employé

Le moteur de rétention est **mieux adapté au lycée qu'au concours**. Les paliers
`1-3-7-16-35` de `MemoryScheduler` ont été calibrés pour une rétention durable ;
un candidat qui prépare en six semaines n'atteint jamais le palier 35. Un
lycéen, si. L'outil d'année scolaire existe déjà — il est employé sur un examen
ponctuel.

Les huit codes de cause valent plus cher encore au lycée : dire à un élève
« tu as confondu deux notions » est un diagnostic que le contrôle continu ne
lui donne jamais.

Et **la mécanique multi-public existe, inutilisée**. Mesuré le 26 août sur la
préproduction : la table `audiences` contient **une seule ligne, `crmef`**. Tout
le lot 3A.6 a été construit pour servir plusieurs publics ; un seul est servi.
La garde existe déjà côté serveur — une souscription sur une version dont le
candidat ne relève pas est refusée (Q-19).

## Décision proposée

**1. Le lycée entre comme AUDIENCE, jamais comme filière de concours.**

Mesuré : les trois filières racines sont `sciences-education`, `post-bac` et
`fonction-publique`. Les trois sont des concours. Un élève de 2ᵉ Bac ne passe
pas un concours : il suit un programme officiel. L'accrocher comme quatrième
filière serait un mensonge de modèle, et un mensonge de modèle se paie dans
chaque libellé de chaque écran.

**2. Le calendrier cesse de supposer une date unique.**

`MemoryScheduler::MARGE_AVANT_EPREUVE` arrête de planifier deux jours avant
*l'*épreuve. Un lycéen n'a pas une date : il a un contrôle toutes les trois
semaines, un examen de fin de trimestre, et le baccalauréat au bout. **C'est le
seul vrai changement d'architecture de ce document.** Sans lui, tout le reste
est du placage : un élève dont le calendrier s'arrête en novembre ne comprendra
pas ce qui lui arrive.

**3. L'unité d'entrée devient le chapitre, à côté de l'épreuve.**

DET-53 écrit qu'un diagnostic « se passe une fois ». C'est vrai d'un concours.
Un lycéen entre à chaque chapitre nouveau, et il en arrive un tous les quinze
jours. La porte d'entrée se dédouble : diagnostic d'épreuve pour le candidat,
entrée par chapitre pour l'élève. **Le moteur ne change pas** — ce sont les
mêmes nœuds, le même calcul de maîtrise, la même ordonnance.

**4. On ne forke pas.** Deux produits, c'est deux dettes. Le moteur est l'actif.

## Ce que ça coûte, dit avant plutôt qu'après

**La duplication des nœuds.** ADR-0014 veut qu'un nœud n'appartienne qu'à une
épreuve, et DET-88 a refermé le déplacement inter-épreuves pour cette raison.
« Loi de Mendel » existera donc deux fois : une pour le programme de 2ᵉ Bac, une
pour la spécialité SVT du CRMEF. C'est exactement la duplication qui a fait
revenir le propriétaire sur les épreuves datées le 26 août — 111 nœuds contre
142. Elle est acceptable ici, mais elle se décide les yeux ouverts.

**L'économie change de forme.** Les offres `7j / 30j / 180j` sont des formes de
sprint. Une année scolaire fait neuf mois, et **celui qui paie n'est pas celui
qui utilise** : c'est un parent. Ce n'est pas une offre de plus, c'est un autre
acte de vente. Le gratuit à dix questions, activé le 26 août, est calibré pour
« un candidat qui décide » — pour un usage quotidien, dix questions font une
soirée. Le registre des profils de quota (3A.5) sait s'ajuster sans déploiement,
donc la mécanique suit ; c'est la valeur qui est à refaire.

**Le rappel cesse d'être une amélioration et devient le produit.** Pour un
concours, on revient parce que la date approche. Pour un lycéen, on revient
parce qu'on a pris l'habitude — et l'habitude, sans rappel, ne se prend pas.
Mesuré le 26 août : trois notifications, toutes transactionnelles, **zéro tâche
planifiée dans tout le dépôt**. Tenable pour un sprint ; intenable sur neuf
mois. Les deux dettes qui le bloquent sont connues et nommées : DET-09, aucun
fournisseur d'e-mail choisi, et DET-14, les notifications ne passent pas par la
file.

## Ce que ça ne coûte pas

**Le sourcing devient plus facile, pas plus difficile.** Le besoin de contenu se
multiplie — niveau × filière × matière × chapitre — mais le programme officiel
marocain est public et documenté. À comparer avec DET-60, où les poids qui
composent les diagnostics CRMEF sont rapportés « et personne ici n'a vu la pièce
d'origine ». Au lycée, la pièce existe et se cite.

## À arbitrer avant tout code

1. **La duplication des nœuds entre programme scolaire et spécialité de
   concours est-elle acceptée ?** Si non, ADR-0014 doit être rouvert, et c'est
   une décision beaucoup plus lourde que ce document.
2. **Le compte parent existe-t-il ?** S'il existe, voit-il la progression
   détaillée ou des agrégats ? C'est la question que DET-25 pose déjà pour les
   organismes et qui n'a jamais été tranchée — elle se posera deux fois si on ne
   la tranche pas une fois.
3. **Quelle est la première maille livrée ?** Un niveau et une matière suffisent
   à éprouver le second public. Ouvrir tout le lycée d'un coup reproduirait le
   goulot actuel : 41 questions publiées pour trois épreuves CRMEF.

## Ce qui reste vrai quoi qu'il arrive

Aucun score prédictif de réussite, sous aucun nom, pour aucun public — METHODE
§7.3. Ce que le produit peut montrer à un lycéen est une **couverture** du
programme et des échéances qui approchent, jamais une probabilité de réussite à
son contrôle. La règle ne se renégocie pas parce que le public change.
