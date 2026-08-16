# Le bestiaire des tests qui mentent — et les règles de mesure

**Établi le 16 août 2026**, à partir des défauts réellement rencontrés dans ce
projet entre le 5 et le 16 août. Chaque genre est né d'un cas vécu, pas d'une
lecture. C'est pour cela qu'il fait autorité ici : il ne décrit pas les tests en
général, il décrit ceux qui nous ont trompés.

> **Deux taxonomies, à ne pas confondre.** Ce document répond à la question
> « **ce test dit-il la vérité ?** ». Il ne répond PAS à « **pourquoi ce test
> est-il instable ?** » — un test intermittent relève d'une autre grille
> (attente asynchrone, concurrence, dépendance à l'ordre, fuite de ressource,
> réseau, horloge, collection non ordonnée). Un test peut être parfaitement
> stable et mentir ; un test peut être instable et dire vrai. Nommez la bonne
> grille avant de chercher.

---

## Les sept genres

### 1. Le test qui ne discrimine pas
Il passe avec le correctif et **il passe aussi sans**. C'est le genre fondateur :
un test qui ne distingue rien ne prouve rien. D'où la règle de la mutation —
tout correctif s'accompagne d'une modification du code qui doit faire rougir le
test. Pas de rouge à la mutation, pas de preuve.

### 2. Le test qui accuse du code juste
Il rougit alors que le code est correct. Le défaut est dans le test, la fixture
ou l'environnement. Danger propre : on « corrige » du code sain jusqu'à ce que
le test se taise, et on installe un vrai défaut pour satisfaire un faux.

### 3. Le test qui absout du code non vérifié
Il passe **parce que le code n'a jamais tourné**. Cas vécu : un vrai 429 du
limiteur de débit rendait la recette verte — la requête n'était jamais parvenue
à la logique testée. Un vert doit prouver un passage, pas une absence d'échec.

### 4. Le test qui passe pour la mauvaise raison
La garde qu'il prétend éprouver n'est jamais exercée : la condition d'entrée
n'est pas réunie, le chemin testé n'est pas celui qu'on croit. Cas vécu : une
mutation restée verte parce que le champ visé n'était pas assignable en masse —
la garde tenait au modèle, pas à la ligne qu'on croyait éprouver. Ce n'est pas
une mauvaise nouvelle : c'est une garantie qu'on ignorait avoir, et qu'il faut
alors documenter.

### 5. Le test qui passe grâce à un autre défaut
Deux défauts se cachent l'un l'autre. Corriger le premier fait rougir un test
sain. Cas vécu : une fuite de données et une dépendance à l'état accumulé du
quota se compensaient. Règle : on rend l'état déterministe, on n'affaiblit
aucun des deux.

### 6. Le test qui ne peut pas distinguer, faute de fixture
La différence qu'il devrait détecter **n'existe pas dans les données** qu'on lui
donne. Cas vécu : la reconstruction d'un repère ne servait pas à bloquer mais à
ne pas trop bloquer — sans le cas qui distingue les deux, on garde une ligne
inutile en croyant l'avoir prouvée.

### 7. L'outil de préparation qui ment sur son propre succès
Le test n'est pas seul en cause : le script qui prépare son état peut échouer en
silence. Cas vécu : `php artisan tinker <fichier>` rend 0 même quand le script
lève une exception. Tout outil de préparation doit rendre un code de sortie
honnête, sans quoi il fabrique des verts.

---

## Les règles de mesure

**La preuve se fait sur les octets.** Ce qui compte est ce qui circule — la
réponse réseau, la charge utile du rendu serveur — pas le code qu'on a lu. Lire
le code produit une hypothèse ; mesurer produit un fait.

**Une durée est une médiane sur trois, avec sa plage.** Une mesure unique n'est
pas une durée.

**Une inférence n'est pas un empêchement.** Quand on déduit un blocage, on va
vérifier à la source. Cas vécu : trente-huit minutes perdues sur une API jugée
inaccessible — il manquait un préfixe de route.

**D-F39 — quand une recette échoue, vérifier ce qu'elle MESURE avant de corriger
ce qu'elle DÉSIGNE.** L'échec nomme un coupable ; il ne le prouve pas.

**Fabriquer un état que le produit interdit pour prouver une ligne, c'est tester
le démontage.** Si l'état est inatteignable, la ligne est peut-être inutile.

**Préférer une garantie à un contrôle.** Quand le compilateur, une contrainte de
base ou un type peuvent rendre le défaut impossible, ils valent mieux qu'un test
qui le détecte après coup. Un `satisfies Record<…>` bat une assertion ; une
version lue à la source bat deux chiffres comparés.

**Une anomalie qui revient n'est pas du bruit.** Un état non suivi, un
avertissement, un fichier inattendu qui réapparaît à chaque vérification se
réexamine — il ne se qualifie pas d'un mot. Cas vécu deux fois le 16 août.

**Un rapport qui omet une action est plus grave que l'action.** Ce qui n'est pas
dit ne peut pas être arbitré.

---

## Comment s'en servir

À la fin d'un correctif, deux phrases suffisent : *« la mutation X rougit le
test Y »*, et *« ce défaut relevait du genre N »*. Si aucun genre ne convient,
c'est peut-être un huitième — décrivez-le et ajoutez-le ici. Le bestiaire n'est
clos que jusqu'au prochain cas.

### Un cas vécu du genre 3 — le vert qui absout du code jamais exécuté

**16 août 2026.** Une suite annoncée « 593 vertes » avait tourné sur l'arbre de
travail d'une branche de quarantaine, sur laquelle le dépôt avait basculé sans
que personne le demande. Cet arbre ne contenait PAS le lot en cours : les
onze tests de l'import des annales n'existaient pas dedans, et le code qu'ils
éprouvent non plus.

Le vert était parfaitement exact — et il ne disait rien de ce qu'on croyait
qu'il disait. Rejouée sur la bonne branche : 603 vertes, dix de plus.

**Ce que le cas apprend :** un décompte de tests n'a de sens qu'accompagné de
l'arbre sur lequel il a été mesuré. « La suite est verte » est une phrase
incomplète ; « la suite est verte sur `main` à tel commit » est une mesure.
C'est aussi pourquoi la règle permanente 10 existe.
