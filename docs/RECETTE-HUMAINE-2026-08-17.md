# Recette humaine — 17 août 2026

**Un candidat marocain qui arrive demain sur cette plateforme ne peut PAS préparer son concours : il crée son compte, arrive sur un tableau de bord vide, et n'y trouve aucun chemin pour commencer.**

Tout le reste du parcours fonctionne, et par endroits remarquablement bien. Mais
il est inatteignable sans connaître une URL, et la première porte n'existe pas.

---

## Comment cette recette a été faite

Base **neuve** (`naja7i-demo.sh reset`), plateforme démarrée par le script, puis
**117 écrans parcourus dans un vrai navigateur** — Chromium piloté, DOM lu après
hydratation, console et réseau relevés à chaque écran, capture d'écran conservée.

Pourquoi un navigateur et pas `curl` : le front est un Nuxt. `curl` ne rend que
le HTML du serveur ; une clé de traduction non résolue, un libellé qui n'arrive
qu'à l'hydratation, une erreur de console — rien de cela n'est visible autrement.
La recette porte sur ce que voit un humain, donc sur le DOM rendu.

**Le principe posé dans la consigne s'est vérifié dès la première minute.** La
suite backend était à 614 verts. Le script de démonstration n'avait, lui, jamais
su créer une base neuve : `createdb` n'existe pas sur cette machine, PostgreSQL
tournant dans Docker. Aucun test ne pouvait le dire. Il a fallu le lancer.

---

## Ce que la recette n'a PAS pu vérifier, et pourquoi

Cette section vaut autant que le reste. Un parcours dont on ignore les trous
n'est pas une recette.

| Non vérifié | Pourquoi | Ce qu'il faudrait |
|---|---|---|
| **La question miroir** | Aucun chemin d'interface trouvé vers elle. La correction propose « Vérifier sur une autre question — le même piège, un autre énoncé », mais l'élément n'est pas cliquable (même défaut que D-06). | Reprendre après correction de D-06. |
| **Le rapport d'examen blanc et sa note sur barème** | L'examen blanc dure 240 minutes et compte 20 questions ; je l'ai lancé, vérifié le chronomètre, la grille et la survie au rechargement, puis abandonné. Répondre à 20 questions puis attendre l'échéance n'était pas tenable dans cette session. | Une passation complète, ou un script qui avance l'échéance comme `echoir-revisions.php` le fait pour la mémoire. |
| **L'avertissement des 5 dernières minutes** | Même raison : il se déclenche à T-5 min sur 240. | Idem. |
| **« Répondre après l'échéance »** | Idem. | Idem. |
| **La chaîne éditoriale de bout en bout** (écrire → faire relire par un autre → publier) | J'ai ouvert et décrit chaque écran et chaque champ du formulaire de création, mais je n'ai pas soumis de question réelle jusqu'à publication. Le formulaire exige une justification sur chaque option et une cause par distracteur ; le remplir honnêtement demande d'écrire une vraie question. | Une session avec un rédacteur humain. |
| **« Un auteur ne peut pas relire son propre travail »** | Découle du point précédent : il faut une question dont l'auteur est identifié pour l'éprouver. | Idem. |
| **La reprise sur un second appareil** | Deux navigateurs simultanés non testés. Le tableau de bord lit `GET me/attempts` côté serveur, donc la mécanique est là, mais je ne l'ai pas vue. | Deux contextes navigateur en parallèle. |
| **Le thème sombre** | Bouton présent partout, jamais activé. | Un passage complet en thème sombre. |
| **Le collecteur d'annonces** | Hors périmètre demandé, et les annonces sont une fixture du 8 août. | — |
| **Les écrans commerciaux avec le rôle prévu** | Impossible : voir D-02. J'ai opéré avec un compte `super_admin` créé pour la recette. | Corriger D-02. |

**Deux comptes ont été créés pour cette recette** et n'existent pas dans le semis :
`recette.finance@naja7i.test` (rôle `finance`) et `recette.admin@naja7i.test`
(rôle `super_admin`). Les rôles sont ceux du produit, pas des rôles fabriqués.
Sans eux, aucune page commerciale n'était atteignable — ce qui est le défaut D-01.

---

## I — Le parcours candidat, en français

| Écran | Ce que j'attendais | Ce que j'ai vu | Verdict |
|---|---|---|---|
| `/fr` accueil | Vitrine, démonstration de correction | Démonstration complète avec les 4 options justifiées, chiffres du catalogue annoncés comme tels (« Ces nombres viennent du catalogue publié, pas d'une promesse commerciale »). 0 erreur console | ✅ |
| `/fr/concours` | Catalogue par filière | 3 filières, statuts « Ouvert » / « En préparation » | ✅ |
| Filière → famille → spécialité | Descente complète | `/fr/concours/sciences-education` → `/famille/crmef` → `/famille/crmef/langue-francaise`. Fonctionne | ✅ |
| Spécialité fermée | Message honnête | « La préparation de cette spécialité n'est pas encore ouverte. Aucun diagnostic n'est proposé tant que les questions n'ont pas été validées » | ✅ |
| Bloc « Concours ouverts en ce moment » sur une fiche spécialité | Annonces liées à CETTE spécialité | Les **mêmes trois annonces** sur « Langue amazighe », « Langue arabe » et « Langue française » — dont un poste de français sur la page amazighe | ⚠️ D-07 |
| `/fr/opportunites` | Tapis d'annonces | 22 annonces, facettes filière/nature/région, bandeau « Données d'exemple — capture du 8 août 2026 » | ✅ |
| Fiche d'annonce | Résumé, source, historique | Complète. « Ce résumé est rédigé par naja7i à partir des champs de l'avis officiel. Il n'en reproduit pas le texte. » | ✅ |
| `/fr/robot` | Page de transparence du robot | Excellente : user-agent, cadence, adresse d'opposition, ce qui est fait des données | ✅ |
| `/robots.txt` | Un fichier robots.txt | **404.** La page `/fr/robot` promet pourtant de respecter « une règle ajoutée à votre robots.txt » — le site n'en sert aucun | ⚠️ D-08 |
| `/fr/tarifs` | Trois offres | 49 / 199 / 699 MAD, et une explication franche du paiement par code cadeau, délai de 48 h annoncé | ✅ |
| `/fr/inscription` | Formulaire | E-mail, mot de passe (12 car. min.), confirmation, 3 cases dont marketing séparée du consentement | ✅ |
| Vérification e-mail | Lien dans Mailpit, bon préfixe de langue | `http://localhost:3000/**fr**/verifier-email?token=…` — **préfixe correct**, lien fonctionnel, « Votre adresse est confirmée » | ✅ |
| **Premier écran d'un compte neuf** | Une porte | « Votre préparation » + un encart gris « Vous n'avez pas encore passé de diagnostic. » **Aucun lien, aucun bouton, rien.** | 🔴 **D-01** |
| Écran de seuil du diagnostic | Ce qui est mesuré | Remarquable : « Ce qui n'est pas mesuré — aucune prédiction de réussite au concours n'est produite, sous aucun nom » | ✅ |
| Passation du diagnostic | Certitude à chaque question | **10 questions sur 10**, trois niveaux (Sûr / Hésitant / Au hasard) avec leur définition | ✅ |
| Soumettre sans répondre | Refus | « Suivante » reste actif sans réponse et **fait avancer** ; « Précédente » est bien désactivé en Q1. La série ne peut pas être *terminée* sans réponse (le bouton final n'apparaît qu'à la dernière question) mais on peut la traverser vide | ⚠️ D-09 |
| Fin de série | Confirmation | Voile : « Terminer la série ? Après cette étape, la correction s'affiche et la série ne se modifie plus » | ✅ |
| **Correction** | Chaque option justifiée | **40 justifications pour 10 questions** : « Exacte : … » sur la bonne, « Pourquoi cette option est fausse » sur chacune des trois autres | ✅ |
| Cause sous quota | 2 gratuites puis mur | « Cette erreur vient souvent de : Piège de formulation » ×2, puis « Vous avez consulté 2 causes sur 2. La justification de chaque option reste visible. » | ✅ |
| Mur payant | Un champ, pas une route | Confirmé : la question reste entière, seule la cause est voilée, lien « Découvrir l'abonnement » → `/fr/tarifs` | ✅ |
| Le mot « hypothèse » sur chaque cause | Présent | **Absent — zéro occurrence sur tout le parcours.** La couverture est faite par « Cette erreur vient **souvent** de » | ⚠️ D-10 |
| `/fr/app/maitrise` | Score jamais sans volume | Chaque domaine : « Pas encore assez de réponses pour conclure. Réponses : 2 · Manquantes pour conclure : 3 · Erreurs avec certitude : 1 » | ✅ |
| `/fr/app/ordonnance` | Plan de travail | « Naja7i mesure ce que vous avez démontré. Aucune prédiction… ». Chaque ligne porte son motif, son poids officiel, son volume | ✅ |
| **Cliquer une ligne d'ordonnance** | Série d'entraînement ciblée | « Reprendre : Grammaire » est affiché **en orange, couleur de lien** — et n'est ni `<a>` ni `<button>`. Le DOM ne contient aucun élément cliquable hors en-tête | 🔴 **D-06** |
| `/fr/app/revisions` | Rendez-vous mémoire | Après `echoir-revisions.php` : 7 rendez-vous, la page les liste | ✅ |
| **Examen blanc** | Seuil, chrono serveur, grille | Grille 1→20, « Marquer pour y revenir », « 0 répondues sur 20 · 0 marquée(s) » | ✅ |
| Chronomètre | Serveur, survit au rechargement | Avant : `239:57`. Après F5 : `239:55` — **ancré serveur, il a continué de courir** | ✅ |
| Lisibilité du chronomètre | — | Affiché `239:57` pour 4 heures. Un candidat doit diviser de tête | ⚠️ D-11 |
| Rechargement en pleine épreuve | La série survit | Intacte : même question, même grille, chrono juste | ✅ |

### Le parcours commercial (I.6) — déroulé en entier

1. Équipe → `/admin/coupons` → **Créer** → offre, nombre d'utilisations, validité, note interne (« Jamais lue par le candidat »). Code engendré : `NJ7-F3GF-TM9E-7CNM`.
2. Candidat → `/fr/app/abonnement` → saisie du code → **« en_attente »**, et la cause **reste fermée**. Vérifié : mur toujours en place avant validation. ✅ C'est le point que la consigne voulait montrer.
3. Un code erroné répond : « Ce code n'existe pas. Vérifiez la saisie — les codes ne contiennent ni O ni I ni chiffre 1. » ✅
4. Équipe → `/admin/orders` → **Valider** → voile : « Ouvrir l'abonnement ? Le compte recette.a@naja7i.test obtiendra « Découverte — 7 jours » immédiatement. L'échéance court à partir de maintenant. » ✅
5. Candidat recharge → **mur levé, 8 causes ouvertes**, « Abonnement actif — Jusqu'au 24 août 2026 », commande « honorée ».

**Le seul parcours commercial du produit fonctionne de bout en bout** — à condition d'avoir un compte qui puisse l'opérer, ce que le semis ne fournit pas (D-01/D-02).

---

## I bis — Le même parcours en arabe

Dix écrans parcourus en `/ar`, connecté, jusqu'à la correction.

| Contrôle | Résultat |
|---|---|
| `lang="ar"` et `dir="rtl"` sur `<html>` | ✅ sur les 10 écrans |
| `dir="auto"` sur les contenus mixtes | ✅ jusqu'à 88 éléments par écran |
| **Aucune clé de traduction visible (`::`)** | ✅ **zéro** sur les 10 écrans |
| **Aucun libellé anglais résiduel** | ✅ **zéro** |
| Interface traduite | ✅ التمكن, وصفة نجاحي, الأجوبة, للعمل عليه, استئناف السلسلة الجارية… |
| Chiffres | Latins (`15 %`, `20`) — conforme à la décision `-u-nu-latn` prise antérieurement |
| **Deux chaînes françaises fuitent dans l'interface arabe** | ⚠️ **D-12** |

Les deux fuites, sur `/ar/app/ordonnance` :

- « Naja7i mesure ce que vous avez démontré. Aucune prédiction de réussite au concours n'est produite. » — c'est de la **copie d'interface**, pas du contenu.
- « Reprendre : Grammaire » — le libellé d'action, en français.

**Ce qui n'est PAS un défaut** : les noms de domaines (« Grammaire »,
« Linguistique, phonétique… ») et le nom d'épreuve (« Spécialité — Langue
française ») restent en français. C'est du **contenu**, et il s'agit d'une
épreuve de langue française : ses domaines n'ont pas d'autre nom.

---

## II — Le back-office

| Écran | Attendu | Vu | Verdict |
|---|---|---|---|
| `/admin/login` | Connexion | Fonctionne pour les 3 comptes éditoriaux | ✅ |
| **Toutes les entrées du menu** | Aucune 500 | **6 entrées, 6 × 200.** Les deux 500 trouvés par le pilote sont éteints | ✅ |
| Tableau de bord (`Couverture`) | Utile | « Ce qui manque à la banque », **filtré par défaut sur « Didactique de la langue française »** — une épreuve sans contenu ni candidat. Réponse : « Aucun trou ». La banque semée est sur une AUTRE épreuve | ⚠️ D-03 |
| `/admin/questions` | File de rédaction | 20 questions, filtres statut / langue / auteur, pagination | ✅ |
| Formulaire de création | Exigeant | Exemplaire : justification **obligatoire sur chaque option, y compris la bonne** ; cause par distracteur ; remédiation « exigée pour servir au diagnostic » ; « Une question est monolingue. La version arabe est une question distincte » | ✅ |
| Filtres du relecteur | Auteurs | **Le filtre « auteur » liste TOUS les comptes du tenant — les 4 candidats compris, adresses en clair** | 🔴 **D-04** |
| `/admin/sources` | Registre | Présent, bouton Créer | ✅ |
| `/admin/coupons` | Liste + création | Bouton **Créer** présent (correctif du lot précédent tenu), création fonctionnelle | ✅ |
| `/admin/orders` | File + valider/refuser | Colonnes complètes, actions **Valider** et **Refuser**, filtre « En attente » par défaut | ✅ |
| `/admin/plans` | Offres | Répond 200, **mais trois clés de traduction fuient dans le tableau** : `filament-tables::table.columns.icon.boolean.true` | 🔴 **D-05** |

### Les permissions, en vrai (II.5)

| Compte | Menu visible | `/admin/orders` | `/admin/coupons` | `/admin/plans` |
|---|---|---|---|---|
| `editorial.auteur` (auteur) | Couverture, Questions, Sources | **403** | **403** | **403** |
| `editorial.valideur` (editeur) | Couverture, Questions, Sources | **403** | **403** | **403** |
| `recette.finance` (**finance** — porte `orders.validate`) | *(aucun)* | renvoyé à la page de connexion | idem | idem |

**Éconduit proprement, pas cassé** : 403 et non 500, sur les six cas. ✅
Le corps de la page 403 se réduit toutefois à « 403 FORBIDDEN », sans nommer ce
qui manque ni où aller — voir D-13.

**Et le rôle `finance` ne peut pas entrer du tout** — voir D-02.

---

## III — Les trois contrôles qui traversent tout

### 404 jamais 403 — **conforme**

Candidat B demandant les ressources du candidat A :

| Demande | Réponse |
|---|---|
| `GET /api/v1/me/attempts/{uuid de A}` | `404` — `{"error":{"code":"RESOURCE_NOT_FOUND","message":"Cette ressource n'existe pas ou n'est pas encore publiée."}}` |
| `GET /api/v1/me/attempts/{uuid de A}/correction` | `404`, **corps identique** |
| `GET /api/v1/me/attempts/{uuid inexistant}` | `404`, **corps identique** |

Les trois réponses sont indiscernables. Rien ne confirme l'existence de la
ressource d'autrui. ✅

### Aucune prédiction, aucun score sans son volume — **conforme**

Recherché sur les 117 écrans : `probabilité`, `chances de réussir`, `vous
réussirez`, `pronostic`, `score prédit`. **Zéro occurrence.** Deux écrans
l'affirment explicitement, et chaque score s'accompagne de « Réponses : n » et
« Manquantes pour conclure : n ». La seule réserve est D-10 : la cause est
couverte par « vient souvent de » et non par le mot « hypothèse ».

### Console et journaux — relevé

**Journal de l'API pendant toute la recette (10 h 00 → 10 h 45) : 0 erreur, 0 critique.**
Les 31 erreurs de la journée sont toutes antérieures, et toutes dues au défaut
`naja7i:etat` corrigé en cours de route.

Console du navigateur, sur 117 écrans :

| Occurrences | Message | Lecture |
|---|---|---|
| 16 | `401 /api/v1/me` | Le front interroge `me` avant d'avoir sa session. Sans conséquence visible, mais c'est une **erreur rouge dans la console d'un utilisateur sur une page publique**. D-14 |
| 21 | `net::ERR_ABORTED /sanctum/csrf-cookie` | Requête annulée à chaque navigation. Sans effet observé. D-14 |
| 11 | `[Vue warn] onScopeDispose() … no active effect scope` | Sur les écrans de passation. D-15 |
| 6+2 | `[Vue Router warn] No match for /robots.txt` | Conséquence de D-08 |
| 4 | `[nuxt] useAsyncData … must return a value` | Sur `/fr/app`. Le composant retourne `null` quand il n'y a pas d'épreuve — exactement le cas D-01 |
| 2 | `iframe … allow-scripts and allow-same-origin` | Outil de développement Nuxt, absent en production |
| 6 / 4 / 1 | 403, 404, 422 | **Réponses voulues du produit**, provoquées par mes propres essais |

---

## Les défauts

### 🔴 Bloquants

**D-01 — Un compte neuf n'a aucun chemin pour commencer.**
`/fr/app`, connecté, compte sans tentative. La page affiche « Votre préparation »
et un encart « Vous n'avez pas encore passé de diagnostic. » **Aucun lien, aucun
bouton.** Cause lue dans `app/pages/app/index.vue` : l'épreuve suivie est dérivée
de `GET me/attempts` (série en cours, sinon dernière passée) ; sans tentative,
`epreuve` vaut `null` et tout le corps de la page est dans le `v-else`. Le
tableau de bord se remplit correctement **dès qu'une tentative existe** — j'en ai
créé une par URL directe, et tout est apparu. C'est le même défaut que celui que
le pilote a signalé côté back-office, transposé au candidat : un écran qui
mesure, offert à quelqu'un qui cherche une porte.

**D-02 — Le rôle prévu pour valider les commandes ne peut pas entrer dans le back-office.**
`User::canAccessPanel()` autorise sur `questions.view` — une permission
**éditoriale**. Le rôle `finance` porte `orders.view`, `orders.validate`,
`refunds.issue`, et **pas** `questions.view` : il est renvoyé à la page de
connexion sur toutes les pages. Conséquence : le seul opérateur capable de
valider une commande est un `super_admin`, ou quelqu'un à qui l'on aurait donné
une permission éditoriale dont il n'a pas besoin. S'ajoute le fait que **le semis
ne crée aucun compte d'administration** : sur base neuve, personne ne peut ouvrir
Coupons ni Commandes.

**D-04 — Les adresses e-mail des candidats sont exposées aux relecteurs.**
`/admin/questions`, panneau des filtres, filtre « auteur »
(`tableFiltersForm.author_id`). Il liste **tous les comptes du tenant** :
`recette.a@naja7i.test`, `recette.b@naja7i.test`, `recette.finance@…`,
`recette.admin@…`, et le compte que je venais de créer par le formulaire public.
Aucun candidat n'a jamais rédigé de question. Le compte `editorial.relecteur` n'a
que `questions.view`, `questions.review`, `catalogue.view` — ni `members.view`,
ni `users.support`. C'est un annuaire de données personnelles servi à qui n'a pas
la permission qui les gouverne.

**D-05 — Une clé de traduction fuit sur `/admin/plans`.**
`filament-tables::table.columns.icon.boolean.true`, trois fois, dans la colonne
booléenne du tableau des offres. Même famille que la fuite déjà corrigée sur le
tableau de bord — et le contrôle écrit alors ne visitait que `/admin` : la
garantie était plus étroite que la famille du défaut.

**D-06 — Les lignes de l'ordonnance appellent le clic et n'y répondent pas.**
`/fr/app/ordonnance/CRMEF-FR-SPEC-2025`. Chaque ligne se termine par « Reprendre :
Grammaire », rendu **en orange, la couleur des liens de la page**. Le DOM ne
contient, hors en-tête, **aucun `<a>` ni `<button>`**. Même chose pour « Vérifier
sur une autre question » sur la correction, qui devait mener à la question
miroir. L'étape 6 du guide de visite est impraticable.

### ⚠️ Gênants

**D-03 — Le tableau de bord du back-office répond sur la mauvaise épreuve.**
`/admin`. Filtre par défaut « Épreuve : Didactique de la langue française » —
sans contenu ni candidat — d'où « Aucun trou. Chaque couple attendu par un
candidat est servi par au moins deux questions. » La banque semée est sur
« Spécialité — Langue française ». Le premier écran du back-office affirme donc
sereinement qu'il n'y a rien à faire, en regardant ailleurs.

**D-07 — Le bloc d'annonces d'une fiche spécialité n'est pas filtré par la spécialité.**
`/fr/concours/famille/crmef/langue-amazighe`, `…/langue-arabe`,
`…/langue-francaise` : les **mêmes trois annonces**, dont « Professeur de
l'enseignement secondaire qualifiant — Français » sur la page « Langue amazighe ».

**D-08 — Le site ne sert aucun `robots.txt`.**
`http://localhost:3000/robots.txt` → 404. La page `/fr/robot` déclare pourtant un
user-agent et promet : « Une règle ajoutée à votre robots.txt est également
respectée dès le passage suivant. » Une plateforme qui exploite un robot et
publie une page de transparence doit servir le sien.

**D-09 — On peut traverser un diagnostic sans répondre.**
`/fr/app/tentative/{uuid}`, question 1, aucune réponse : « Suivante » est actif et
fait avancer. « Précédente » est bien désactivé. La série ne peut pas être
*terminée* vide (le bouton de fin n'existe qu'à la dernière question) — mais rien
n'empêche d'arriver à la dernière question sans avoir rien répondu.

**D-10 — Le mot « hypothèse » n'apparaît nulle part sur les causes.**
Zéro occurrence sur les 117 écrans. La couverture épistémique existe, sous une
autre forme : « Cette erreur vient **souvent** de : Piège de formulation », et
l'ordonnance dit « Domaine jamais évalué — un angle mort à découvrir, **pas une
lacune démontrée** ». À arbitrer : la formule actuelle suffit-elle, ou le mot
doit-il être là.

**D-13 — La page 403 ne nomme rien.**
`/admin/orders` en `editorial.auteur` : le corps se réduit à « 403 FORBIDDEN ».
Le refus est correct (et c'est bien 403, pas 500 — la règle est tenue), mais
l'utilisateur n'apprend ni quelle permission manque, ni à qui la demander.

### · Cosmétiques

**D-11 — Le chronomètre de l'examen blanc affiche `239:57`.**
`/fr/app/tentative/{uuid}` en simulation. Pour 4 heures, un candidat lit
« 239:57 » et doit diviser. `3 h 59` se lit sans calcul.

**D-14 — `401 /api/v1/me` et `ERR_ABORTED /sanctum/csrf-cookie` en console.**
16 et 21 occurrences. Aucun effet visible, y compris sur les pages publiques
`/fr/inscription`. Une erreur rouge dans la console d'un visiteur non connecté
reste un bruit qui masquera la prochaine vraie erreur.

**D-15 — `[Vue warn] onScopeDispose()` sur les écrans de passation.**
11 occurrences, toujours sur `/fr/app/tentative/…`.

**D-12 — Deux chaînes d'interface restent en français en arabe.**
`/ar/app/ordonnance` : la notice « Naja7i mesure ce que vous avez démontré… » et
le libellé « Reprendre : X ».

---

## Ce que la recette a coûté au script de démonstration

Le script devait produire une base neuve. Il ne l'avait **jamais fait**. Quatre
défauts, tous corrigés en cours de route parce qu'ils bloquaient la recette
elle-même, et tous invisibles à la relecture :

1. `createdb` / `dropdb` / `psql` appelés sur l'hôte alors que PostgreSQL est
   dans Docker. Le contrôle d'existence portait un `2>/dev/null` : `psql`
   introuvable rendait une sortie vide, la base existante n'était pas détectée,
   et la suppression était **sautée en silence**.
2. Le nom du conteneur déduit sans mise en minuscules — `Naja7i_backend_front`
   au lieu de `naja7i_backend_front`.
3. L'échec de la création de base s'annonçait sous le titre « arrêt de l'API et
   du front », parce que `arreter()` avait écrasé la variable d'étape.
4. La sonde « PostgreSQL répond » appelait `naja7i:etat`, qui **compte des
   tables** — inexistantes avant les migrations. Elle annonçait « PostgreSQL n'a
   pas répondu après 30 secondes » alors que PostgreSQL répondait parfaitement.

Et un défaut de la commande elle-même : `naja7i:etat` explosait sur
`relation "filieres" does not exist`. Une table absente n'est pas une panne,
c'est l'état d'une base neuve — la commande le distingue désormais.

---

## En résumé

Ce produit fait remarquablement bien la chose difficile : la correction
expliquée, la mesure honnête, le refus de prédire, le mur payant qui voile la
cause sans amputer la question, l'isolation entre candidats, l'arabe complet.
614 tests verts, zéro erreur API sur 117 écrans, aucune 500.

Il échoue sur la chose facile : **ouvrir la porte**. Un candidat qui s'inscrit
demain voit un écran vide et repart. Corriger D-01 et D-06 — deux liens — rend
tout le reste atteignable.
