# DECISIONS — choix pris en autonomie par Claude Code

Journal des décisions prises sans arbitrage préalable d'OptimGov pendant
l'exécution des lots. Règle appliquée : à chaque choix, retenir l'option qui
respecte le plus fidèlement les instructions du lot, les fiches de règle et les
ADR du dépôt (`docs/METHODE.md` §2).

Ce journal n'est pas une source d'autorité : il trace ce qui a été décidé
faute d'arbitrage, pour qu'OptimGov puisse le revoir. Une décision qui relève
du métier ou du périmètre n'y a pas sa place — elle remonte (`METHODE` §4).

## PAS-1 — fondations

## D-01 — Runtime Docker : Colima au lieu de Docker Desktop

**Contexte.** La machine ne disposait ni de PHP, ni de Composer, ni d'un runtime
Docker (ni Docker Desktop, ni CLI). L'étape 4 de PAS-1 exige `docker compose up -d`.

**Décision.** Installation via Homebrew de `php`, `composer`, `colima`, `docker`,
`docker-compose`. Colima fournit le daemon Docker sans mot de passe administrateur
(Docker Desktop en `--cask` aurait exigé une élévation de privilèges interactive
que l'exécution non interactive ne permet pas).

**Conséquence.** `docker-compose.yml` est utilisé tel quel, sans modification.
Démarrage du VM : `colima start --cpu 2 --memory 4 --disk 30`.
Pour relancer l'environnement après un reboot : `colima start` puis
`docker compose up -d`.

## D-02 — Dépôt git dédié `optimgov/Naja7i_backend_front`

**Contexte.** PAS-1 vise le dépôt `optimgov/Naja7i_backend_front` (vide au départ).
Or le dossier `Naja7i_backend_front/` était un sous-dossier du dépôt
`optimgov/najah-prototype` (le prototype frontend v1).

**Décision.** `git init` dans `Naja7i_backend_front/` et création du dépôt
`optimgov/Naja7i_backend_front`, conformément à PAS-1. Le backend est un pool
séparé (`api`) dans l'architecture cible : le mélanger au dépôt du prototype
frontend aurait contredit l'intention du plan.

**Conséquence.** `Naja7i_backend_front/` devient un dépôt indépendant. Le dépôt
parent `najah-prototype` doit l'ignorer pour éviter un dépôt imbriqué —
voir la ligne ajoutée à son `.gitignore`.

## D-03 — Ordre scaffolding / overlay

**Contexte.** PAS-1 décrit un dépôt vide, puis `composer create-project`, puis la
copie de l'overlay. Ici l'overlay était déjà déposé dans le dossier, ce qui
empêchait `composer create-project` (qui exige un répertoire vide).

**Décision.** Overlay mis de côté, `composer create-project laravel/laravel:^12.0 .`
exécuté sur répertoire vide, suppression de
`database/migrations/0001_01_01_000000_create_users_table.php` (étape 2), puis
overlay recopié par-dessus — donc exactement la séquence prescrite par PAS-1,
avec écrasement de `app/Models/User.php` et de `README.md` livrés par Laravel.

**Conséquence.** L'overlay a été appliqué tel quel, aux corrections
documentées en D-05, D-06 et D-07 près.

## D-04 — Client Redis : `predis` au lieu de `phpredis`

**Contexte.** L'étape 4 impose `SESSION_DRIVER=redis`, `CACHE_STORE=redis`,
`QUEUE_CONNECTION=redis`. L'installation PHP 8.5 via Homebrew ne fournit pas
l'extension C `phpredis`, valeur par défaut de `REDIS_CLIENT` dans Laravel.

**Décision.** `composer require predis/predis` et `REDIS_CLIENT=predis`.

**Conséquence.** Les trois variables de PAS-1 sont respectées à la lettre et
fonctionnent sans extension native. Passer à `phpredis` plus tard (meilleures
performances en production) ne demande que de changer cette variable, une fois
l'extension installée sur la cible.

## D-05 — Colonne `uuid` ajoutée à `memberships`

**Contexte.** `TenantIsolationTest::test_une_ressource_d_un_autre_tenant_est_introuvable`
interroge `Membership::where('uuid', …)`, mais la migration
`0001_01_01_000140_create_roles_and_memberships` ne créait pas de colonne
`uuid` sur `memberships`. Le test échouait en
`SQLSTATE[42703] column "uuid" does not exist` — 5 tests verts sur 6, alors que
PAS-1 annonce 6 tests verts.

**Décision.** Ajout de `$table->uuid('uuid')->unique();` sur `memberships` et du
trait `HasPublicUuid` sur `App\Models\Membership`, plutôt que d'affaiblir
l'assertion du test.

**Justification.** La règle « l'`id` bigint interne n'est jamais exposé ; seul
l'`uuid` (UUIDv7) sort » est générale (PAS-1 § Règles, README point 4) et
`tenants` comme `users` la respectent déjà. C'était `memberships` qui faisait
exception, pas le test qui était en trop. Supprimer l'assertion aurait retiré une
garantie d'isolation : une ressource d'un autre tenant doit être introuvable
aussi bien par son identifiant public que par son id interne.

## D-06 — `$dropTypes = true` sur `Tests\TestCase`

**Contexte.** `RefreshDatabase` lance `migrate:fresh`, qui supprime les tables
mais pas les types ENUM PostgreSQL. La suite passait au premier lancement puis
échouait à tous les suivants sur `type "app_locale" already exists`.

**Décision.** `protected $dropTypes = true;` sur `Tests\TestCase` — propriété lue
par `RefreshDatabase` pour passer `--drop-types` à `migrate:fresh`.

**Justification.** Correction dans la couche de test, jamais dans les migrations :
PAS-1 interdit explicitement de rendre les migrations agnostiques. Les `CREATE TYPE`
restent inchangés. Idempotence vérifiée (deux exécutions consécutives, 8/8 verts).

## D-07 — `UserFactory` alignée sur la table `users` du Pas 1

**Contexte.** La factory livrée par Laravel produit un attribut `name`, colonne
qui n'existe pas dans la table `users` de l'overlay (le compte ne porte que
e-mail, téléphone, locale, statut). Toute utilisation de la factory aurait
échoué en `column "name" does not exist`.

**Décision.** Retrait de `name`, ajout de `locale => 'fr'`. Aucun test du Pas 1
n'utilise la factory ; la corriger évite un piège au Pas 2.

**Note.** L'identité affichable du candidat relève du profil (Pas ultérieur),
pas de la table `users`.

## Fiches de règle

## D-08 — Index des fiches aligné sur le gel de F03

**Contexte.** La fiche `F03-autopsie-de-l-erreur.md` est passée en statut
« validée — v1.1, 8 août 2026 ». `docs/regles/README.md` l'annonçait encore
comme « brouillon — exemple de méthode », et F03 figurait toujours dans le
tableau « Fonctions en attente de fiche ».

**Décision.** Statut de la ligne mis à jour, et la ligne F03 retirée du tableau
des fonctions en attente.

**Justification.** L'étape 4 du cycle d'une fiche (`METHODE` §3) est le gel :
« la fiche passe en statut validée avec sa date, elle fait autorité à partir de
là ». Un index qui contredit la fiche qu'il indexe est exactement le défaut que
`METHODE` §1 décrit — une décision prise un mardi qui disparaît le jeudi. Aucun
contenu de la fiche n'a été touché : le statut est constaté, pas décidé ici.

## D-09 — Les deux points ouverts de F03 versés à la dette

**Contexte.** F03 est validée mais porte deux réserves explicitement
non bloquantes : les huit codes de cause ne sont pas confirmés par un
responsable pédagogique, et les formulations candidat FR/AR restent à
finaliser. Rien dans le dépôt ne les suivait.

**Décision.** Création de DET-16 (codes de cause, échéance « avant le premier
étiquetage en volume, PAS-6 ») et DET-17 (formulations, échéance « avant
ouverture publique »).

**Justification.** `METHODE` §5 : les constats non bloquants vont dans
`docs/DETTE.md` avec une échéance, et « une entrée de dette sans échéance n'est
pas une dette, c'est un oubli ». Les deux échéances sont dictées par le coût de
report, pas par une préférence : réétiqueter des questions coûte proportionnel-
lement au volume déjà produit (donc avant PAS-6), tandis qu'un texte candidat
provisoire ne gêne que la mise en ligne. Le contenu des réserves n'est pas
réinterprété — il est repris de la fiche, qui fait autorité.

## D-10 — Aucune implémentation de F03 engagée à ce stade

**Contexte.** F03 validée décrit des conséquences techniques précises : capacité
`corrections.cause` avec quota, compteur cumulatif par candidat, plafond
paramétrable en configuration de concours. La tentation est d'enchaîner.

**Décision.** Rien n'est codé. La fiche est gelée, l'index et la dette sont
alignés, on s'arrête là.

**Justification.** F03 dépend de la banque de questions à distracteurs étiquetés
(PAS-6, `docs/PAS.md` : « à venir »), et les drapeaux `eligibleForDiagnostic` /
`eligibleForSimulation` vivent dans le référentiel. Coder la fonction avant son
pas contredirait `METHODE` §3 — « la fiche s'écrit au moment où la fonction
sert » — et produirait du code sans le test sur PostgreSQL réel qu'exige le
cycle de livraison (§5). Le pas en cours est un pas de documentation.
