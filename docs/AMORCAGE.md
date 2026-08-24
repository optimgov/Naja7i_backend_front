# Amorçage d'une base neuve

**Objet :** ce qu'il faut exécuter, dans quel ordre, sur une préproduction ou
une production fraîchement déployée — et surtout **ce qu'il ne faut jamais
rejouer**.

**Pourquoi ce document existe.** Déployer du code n'est pas poser des données :
les migrations passent au déploiement, les semis non, et c'est délibéré. Une
machine fraîchement déployée porte donc le schéma et rien d'autre — pas de
catalogue, pas d'offres, et **aucun compte capable d'entrer au back-office**.
Ce n'est pas un défaut : c'est l'état normal d'une base neuve, et il se
franchit par les gestes ci-dessous.

---

## 0. Avant tout — une sauvegarde, et savoir où l'on est

**Aucun geste de ce document ne se lance sans sauvegarde prise et vérifiée.**
Tous écrivent en base ; deux d'entre eux ne se défont pas.

**Chaque commande porte `--env` explicite.** C'est la règle du dépôt, et elle
n'a jamais autant compté qu'ici : ces gestes créent un accès d'administration
et posent le référentiel. Une commande qui choisit son environnement toute
seule finit un jour par choisir le mauvais.

> Les commandes ci-dessous sont les commandes **artisan**. Sur une machine
> déployée, l'application vit dans un conteneur : le préfixe d'exécution
> (`docker compose exec …` ou l'outil d'exploitation) appartient à la session
> infra et n'est pas repris ici — l'inventer produirait une ligne fausse.

---

## 1. Compter avant d'agir

**Le seul contrôle qui protège vraiment.** Les deux semis de référentiel ne
sont pas rejouables (voir §5) ; la seule question qui compte est donc : *cette
base est-elle vierge ?*

```
php artisan tinker --env=staging
>>> [\App\Models\Filiere::count(), \App\Models\Exam::count(), \App\Models\Plan::count()]
```

- **`[0, 0, 0]`** → base vierge, poursuivez au §2.
- **Tout autre résultat** → **ne lancez aucun semis.** La base porte déjà
  quelque chose. Demandez avant de continuer : ce qui manque est peut-être
  seulement l'administrateur (§3), qui, lui, se lance sans risque.

---

## 2. Le référentiel, dans cet ordre, et une seule fois

```
php artisan db:seed --class=Database\\Seeders\\CatalogueSeeder  --env=staging
php artisan db:seed --class=Database\\Seeders\\Crmef2025Seeder  --env=staging
php artisan db:seed --class=Database\\Seeders\\PlansSeeder      --env=staging
```

**L'ordre n'est pas indifférent.** `Crmef2025Seeder` corrige et complète ce que
`CatalogueSeeder` a posé — parcours, épreuves séparées, matrices de domaines —
et `PlansSeeder` rattache les offres à la catégorie de public `crmef`, que le
premier crée. Lancé seul sur une base vierge, chacun des deux derniers échoue.

**Ce que cela pose :** filières, familles de concours, spécialités, épreuves
CRMEF 2025 avec leurs matrices et leurs poids, et les quatre offres du
catalogue (essai gratuit + Entrée / Préparation / Session complète).

---

## 3. Le premier administrateur

**Le cercle qu'il faut casser :** entrer au back-office exige au moins une
permission, donc un rôle ; distribuer un rôle exige d'être entré. Les
invitations de personnel ne résolvent rien — les émettre demande déjà un compte
autorisé. Cette commande est le **seul** chemin d'entrée initial.

```
php artisan naja7i:creer-un-administrateur \
    --email=vous@exemple.ma \
    --role=super_admin \
    --env=staging \
    --dry-run
```

Retirez `--dry-run` quand la sortie vous convient.

**Elle n'accepte aucun mot de passe.** Un secret passé en argument survit dans
l'historique du shell, dans la table des processus, et dans tout journal qui
capture la ligne de commande — longtemps après que le compte en a changé. La
commande imprime donc un **lien à usage unique**, valable 24 heures par défaut
(`STAFF_INVITATION_EXPIRE_HOURS`), qui n'est **ni envoyé par courriel ni
journalisé**.

> **Le lien n'est affiché qu'une fois.** Copiez-le avant de fermer le terminal.
> S'il est perdu ou expiré, il n'y a pas de commande pour le réémettre : voir
> §6.

**Rôles disponibles** — `--role` est obligatoire et refusé s'il est inconnu,
avec la liste en clair :

| Code | Ce qu'il ouvre |
|---|---|
| `super_admin` | toutes les permissions |
| `expert_pedagogue` | rédaction, qualification, révision, validation, publication et retrait logique des questions ; catalogue et taxonomie |
| `support` | lecture et réponse aux réclamations ; ses anciens droits restent provisoirement présents pendant l'étape A compatible |
| `finance` | commandes, offres, coupons, droit transitoire |

Le rôle `candidat` est **refusé** : il ne porte aucune permission de
back-office, et le compte créé ne pourrait pas entrer.

Les anciens codes `auteur`, `reviseur` et `editeur` restent en base uniquement
pour préserver l'historique des appartenances. Ils sont inactifs, absents de la
liste et ne peuvent plus être attribués.

`super_admin` porte toutes les permissions, mais son attribution obéit à une
règle d'anti-délégation supplémentaire : seul un compte qui porte déjà ce rôle
peut l'attribuer. Le simple cumul de toutes ses permissions ne suffit pas.

**Rejouée sur un compte qui existe déjà**, la commande n'écrase rien — ni rôle,
ni mot de passe, ni invitation. Elle dit ce qui existe et s'arrête.

### Messagerie v1.1 — étape A compatible

La migration `000820` ajoute la messagerie interne et les permissions
`complaints.view` / `complaints.reply` à `expert_pedagogue`, `support` et
`super_admin`. `finance` n'y accède pas. Aucun semis, import de corpus ou geste
d'allumage n'est requis : la migration suffit à ouvrir la surface vide.

**Le support n'est pas encore au périmètre cible.** Ses anciens pouvoirs sont
conservés intentionnellement pendant cette étape A. Ils ne seront retirés que
par une migration ultérieure, après une recette croisée réelle des parcours
candidat, expert, support, super-administrateur et finance. Ne corrigez pas cet
écart à la main sur une base : cela rendrait les environnements différents et
court-circuiterait la recette qui doit autoriser l'étape B.

---

## 4. Les trois étages du contenu — ce que chacun rend visible

**Le point qui surprend, et qui n'est pas un défaut :** après les semis du §2,
« Concours » et « Se préparer » montrent enfin quelque chose — mais **un
diagnostic ne sert toujours aucune question**. Les semis posent le
RÉFÉRENTIEL ; ils ne créent aucune question, et c'est mesuré, pas supposé.

| Étage | Ce qu'on exécute | Ce qui devient visible |
|---|---|---|
| **1 — le référentiel** | `db:seed` × 3 (§2) | Concours, épreuves, parcours, arbres de compétences, offres · **une seule fois, base vierge** |
| **2 — le corpus** | `crmef:importer-annales` | Des **brouillons** dans le back-office de rédaction |
| **3 — la publication** | *la chaîne éditoriale* | Des questions réellement servies à un candidat |

### Étage 2 — importer les annales

> ⚠️ **Correction du 24 août 2026 — la version précédente de ce paragraphe
> était fausse.** Elle affirmait que le corpus « voyage avec le dépôt, donc
> présent dans l'image ». L'inférence « versionné donc embarqué » ne tient pas :
> `.dockerignore` exclut `docs` **et** `*.md`. Le corpus est bien versionné,
> mais il **n'est pas dans l'image**, et c'est délibéré — une image de
> production n'a pas à porter la documentation.

**Sur une machine déployée, les deux fichiers doivent donc être portés jusqu'au
conteneur avant l'import**, et la commande ne sait pas viser ailleurs que
`base_path('docs/corpus/…')` : elle code ses chemins en dur (**DET-100**). Si
vous ne le faites pas, elle refuse proprement, en nommant le fichier manquant :

```
Introuvable ou illisible : /app/docs/corpus/CRMEF-extraction-20260815.md
```

Le geste manuel — copier les deux fichiers dans le conteneur, importer, effacer
la copie — a été fait une fois en M-019 et fonctionne. Notez quelque part
**quelle version du corpus** vous avez portée : rien dans la base ne
l'enregistre aujourd'hui, et c'est la moitié la plus coûteuse de DET-100.

```
php artisan crmef:importer-annales --simulation --env=staging
php artisan crmef:importer-annales --env=staging
```

`--simulation` compte sans rien écrire. Le bloc par défaut est
`2025_SCED_college_qualifiant`, et c'est **le seul importable aujourd'hui**.

> **`--tous` est refusé, et c'est délibéré (DET-69, DET-61).** Sur les 213
> questions classées, **53 seulement** pendent d'une épreuve modélisée — celles
> du bloc par défaut, rattachées à `CRMEF-SE-2025`. Les 160 autres viennent des
> voies A (primaire) et C (secondaire 2e grade), que le dépôt ne modélise pas :
> autre intitulé, autre autorité émettrice, autre barème, autre nombre
> d'options. Elles ne s'inventent pas un nœud et ne s'importent pas sous une
> épreuve qui n'est pas la leur. **N'attendez donc pas 1 413 questions : cet
> import en pose une cinquantaine.**

### Étage 3 — la publication n'est pas un geste d'exploitation

Les questions importées entrent en **`status = draft`**, et un brouillon ne se
sert jamais à un candidat. Entre l'import et un diagnostic qui rend de vraies
questions, il y a la chaîne éditoriale — qualification du domaine, corrigé
établi, justification de chaque option, cause de chaque distracteur, relecture,
validation et publication. Un même expert pédagogue peut accomplir ces actes,
qui restent tous datés et attribués. **C'est le travail des experts, et c'est le
jalon 2.**

**Il n'existe aucun raccourci de publication en masse, et il ne faut pas en
fabriquer un.** Publier du contenu non qualifié, même en préproduction, ferait
mesurer un produit qui ment sur la qualité de sa banque : la carte de maîtrise
s'appuierait sur des causes que personne n'a établies, et les corrections
citeraient des justifications inventées. C'est précisément ce que toute la
chaîne existe pour empêcher.

Si un raccourci devient nécessaire pour éprouver les écrans, ce sera **une
décision explicite**, et les questions concernées devront porter une marque
visible — sans quoi personne ne saura, six mois plus tard, ce qui a été relu.

---

## 5. Ce qu'il ne faut pas rejouer, et ce qui se rejoue sans risque

| Semis | Rejouable ? | Comportement mesuré |
|---|---|---|
| `CatalogueSeeder` | **Non** | Échoue sur l'unicité de `filieres.slug`, **transaction annulée, rien écrit** |
| `Crmef2025Seeder` | **Non** | Échoue sur l'unicité de `sources.code`, **transaction annulée, rien écrit** |
| `PlansSeeder` | **Oui** | `updateOrCreate` : compositions et prix rétablis, aucune ligne dupliquée |

**Le refus est un filet, pas une permission.** Les deux premiers ne doublent
pas le catalogue — ils s'arrêtent net et laissent la base intacte, parce que
leurs `run()` sont enveloppés dans une transaction et que les codes du
référentiel sont uniques. C'est plus sûr qu'on ne le croyait, **et cela ne
change pas la consigne** : comptez au §1 avant de lancer quoi que ce soit. Un
index protège contre le doublon ; il ne protège pas contre un semis qu'on
lancerait sur la mauvaise machine.

*Ces trois comportements sont tenus par `tests/Feature/BackOffice/SemisDAmorcageTest.php` :
si l'un change, ce document devient faux, et le test rougit avant la machine.*

**`PlansSeeder` mérite une note à part.** Le rejouer **recompose les offres
telles que le code les décrit** — donc écrase toute modification faite à
l'écran d'administration depuis. Sur une base où le propriétaire a ajusté un
prix ou une composition, ce n'est pas un geste anodin : chaque modification
crée une version, rien n'est perdu, mais l'offre courante redevient celle du
dépôt.

---

## 6. Ce que ce document ne couvre pas

- **Le lien d'administrateur perdu ou expiré.** Il n'existe aucune commande
  pour le réémettre. Aujourd'hui, le contournement est de créer un second
  compte d'amorçage avec une autre adresse, puis d'inviter normalement depuis
  le back-office. Une commande de réémission serait un lot à part — et elle
  devrait, comme celle-ci, refuser tout mot de passe en argument.
- **Le rattrapage du palier gratuit** (`naja7i:rattraper-le-gratuit`) et **la
  pose du droit transitoire** (`naja7i:poser-le-droit-transitoire`). Ce sont des
  gestes d'**allumage**, pas d'amorçage : ils appartiennent au jalon 1.6 et ne se
  lancent que sur ordre explicite du propriétaire, après la recette.
- **La sauvegarde et sa vérification**, qui appartiennent à l'exploitation.
