# La règle des portes

**Établie le 18 août 2026**, avant le code du lot PORTES phase 2, à partir des
cinq défauts de la recette humaine du 17 août.

> **Un écran qui mesure offre toujours la porte qui le remplit. Aucun état vide
> ne se termine sans un chemin cliquable vers l'action qui en sort. Et tout
> élément qui a l'apparence d'un lien EST un lien : la couleur des liens de la
> page est une promesse d'interaction.**

Ce fichier est une **copie conforme** de `docs/regles/PORTES.md` du dépôt
`Naja7i_frontend`. La règle vaut des deux côtés : un tableau de bord Filament
sans trou à montrer et un tableau de bord candidat sans tentative sont le même
défaut, à deux endroits.

---

## Ce que la règle interdit, en trois clauses

### 1. Un écran qui mesure offre la porte qui le remplit

Un écran de mesure — maîtrise, ordonnance, couverture de la banque, tableau de
bord — n'a de sens que rempli. Vide, il ne dit pas « rien à faire » : il dit
« je ne sais rien de vous ». Ces deux phrases n'appellent pas la même suite, et
seule la seconde est vraie tant que le candidat n'a rien fait.

L'écran doit donc porter, dans son état vide, **le geste qui produit la donnée
qu'il mesure** — pas un lien vers l'accueil, pas un renvoi vers l'aide : le
geste.

### 2. Aucun état vide ne se termine sans un chemin cliquable

Un état vide sans sortie est un cul-de-sac. La formulation compte : « cliquable »
signifie `<a>` ou `<button>` dans le DOM rendu, pas un texte qui décrit
l'action. Un état vide qui dit « passez un diagnostic » sans y mener demande au
lecteur de deviner l'adresse.

La vérification est donc mécanique et se fait sur la **page rendue** : dans le
corps d'un écran en état vide, compter les ancres et les boutons. Zéro est un
défaut.

### 3. Tout élément qui a l'apparence d'un lien EST un lien

L'inverse de la clause 2, et le plus insidieux : un élément coloré, souligné ou
libellé comme une action (« Reprendre : … », « Vérifier sur une autre
question ») qui n'est ni `<a>` ni `<button>` promet une interaction qu'il ne
tient pas. Le candidat clique, rien ne se passe, et il conclut que le produit
est cassé — ce qu'il est.

Deux conduites, jamais une troisième :

- soit l'élément devient un vrai lien ou un vrai bouton ;
- soit il perd l'apparence d'action — libellé descriptif, couleur de texte
  courant.

Un `<span>` qui ressemble à un lien n'est pas une décision d'affichage, c'est
une promesse non tenue.

---

## Ce que la règle n'exige pas

Elle **n'exige pas qu'une action soit toujours disponible**. La règle du mur
payant reste entière : soit l'action est proposée, soit elle n'existe pas dans
le rendu — jamais de bouton grisé, jamais de lien masqué en CSS pour cause de
droits.

Elle **n'exige pas d'inventer une destination**. Quand aucune porte n'existe
encore, c'est la porte qu'il faut construire, pas un lien vers une page qui
n'existe pas. Un lien mort est pire qu'un texte inerte : il ment deux fois.

---

## Comment on la vérifie

Un test qui construit lui-même l'élément qu'il vérifie ne prouve rien du
câblage du produit. La vérification de cette règle **interroge la page rendue** :

- côté frontend, `scripts/recette-portes.mjs` charge les écrans dans un vrai
  navigateur, après hydratation, et compte les ancres et boutons du corps ;
- côté backend, le contrôle visite **toutes** les pages Filament, pas celle où
  le défaut a été vu la dernière fois.

Et comme partout ici : **le vert ne suffit pas.** Chaque règle installée reçoit
sa mutation — on retire ou on inverse la ligne qui la porte, on relance, et on
vérifie que le ou les tests attendus rougissent, et eux seuls. Une mutation qui
laisse la suite verte désigne un test du genre 1 du bestiaire.

---

## Les cinq défauts d'où elle vient

| Défaut | Clause violée | Écran |
|---|---|---|
| D-01 | 1 et 2 | `/fr/app` — compte sans tentative : « Vous n'avez pas encore passé de diagnostic », aucun lien |
| D-06 | 2 et 3 | `/fr/app/ordonnance/…` — chaque ligne recommande un domaine, aucun élément cliquable dans le corps |
| D-09 | — (règle voisine) | `/fr/app/tentative/…` — passer sans répondre fabriquait une réponse fausse au lieu d'une question sautée |
| D-13 | 1 et 2 | la page 403 ne nomme ni la permission manquante ni la surface, et n'offre aucune suite |
| D-03 | 1 et 2 | `/admin` — « Aucun trou » sur une épreuve sans contenu ni candidat, et aucune porte vers la rédaction |
