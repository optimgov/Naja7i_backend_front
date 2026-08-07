# DECISIONS — exécution de PAS-1.1

Journal des décisions prises en autonomie pendant l'exécution de
`PAS-1.1_INSTRUCTIONS_CLAUDE_CODE.md`, sous le mandat du tableau de décisions
pré-arbitrées en tête de ce fichier. Suite de `DECISIONS.md` (PAS-1, D-01 à D-07).

## D-08 — Runtime Docker : réinstallation de Lima et Colima en arm64 natif

**Contexte.** `colima start` échouait en
`limactl is running under rosetta, please reinstall lima with native arch`.
La machine est arm64, mais le Homebrew installé est celui d'Intel (`/usr/local`) :
`limactl` et `colima` étaient donc des binaires x86_64 exécutés via Rosetta.
Lima 2.2.0 refuse désormais cette configuration, et la VM créée au PAS-1 portait
`arch: x86_64` — qui exigeait maintenant une émulation QEMU absente.
Aucun contournement par variable d'environnement n'existe (vérifié dans le binaire).

**Décision.** Installation des binaires officiels `Darwin-arm64` de Lima 2.2.0 et
Colima 0.10.3 par-dessus ceux du Cellar Homebrew (mêmes versions, seule
l'architecture change), puis recréation de la VM :
`rm -rf ~/.colima && colima start --cpu 2 --memory 4 --disk 30`.
Les binaires x86_64 remplacés incluent le guest agent Linux, qui est spécifique
à l'architecture — remplacer le seul `limactl` n'aurait pas suffi.

**Perte de données.** La suppression de la VM détruit le volume Docker `pgdata`,
donc la base de développement. Aucune donnée réelle n'y vivait : elle ne contenait
que le résultat des migrations du PAS-1, et l'étape 6 de ce pas exécute de toute
façon `php artisan migrate:fresh`. Aucun dépôt, aucun fichier du projet n'a été
touché.

**Conséquence.** `brew upgrade lima` ou `brew reinstall lima colima` réinstallera
des binaires x86_64 et cassera à nouveau le démarrage. Le correctif durable est
un Homebrew natif dans `/opt/homebrew` — non entrepris ici, hors périmètre d'un
correctif de revue backend. À traiter au premier changement de poste de travail.

## D-09 — `.github/workflows/ci.yml` rédigé et non copié

**Contexte.** L'étape 2 liste `.github/workflows/ci.yml` parmi les fichiers de
l'overlay, mais le fichier n'était pas présent dans le dépôt et l'étape 9 exige
une CI verte : il n'y avait rien à faire passer au vert.

**Décision.** Workflow rédigé à partir des contraintes déjà écrites ailleurs dans
le projet plutôt qu'inventées : service `postgres:16` avec exactement les
identifiants attendus par `phpunit.xml` (base `naja7i_test`, rôle `naja7i`),
`healthcheck` calqué sur `docker-compose.yml`, `pint --test` puis `php artisan test`.

**Justification du service PostgreSQL plutôt qu'un conteneur applicatif.** Le rôle
`naja7i` créé par l'image est superutilisateur : les migrations peuvent exécuter
`CREATE EXTENSION citext` et `pgcrypto`. Aucun service Redis n'est démarré :
`phpunit.xml` force `array`/`sync` pour le cache, la session et la file.

**Aucun repli SQLite**, conformément à la contrainte absolue du pas : une CI verte
sur SQLite n'exécuterait ni les types ENUM, ni l'index partiel, ni le trigger
plpgsql — c'est-à-dire précisément ce que PAS-1.1 protège.

## D-10 — PHP 8.4 en CI alors que le poste de développement est en 8.5

**Contexte.** `composer.json` exige `php: ^8.2`. Le poste tourne en 8.5.9 (Homebrew).

**Décision.** `php-version: '8.4'` en CI.

**Justification.** 8.4 est la version stable la mieux couverte par
`shivammathur/setup-php` pour les extensions requises (`pdo_pgsql`, `intl`), et
reste dans la contrainte déclarée. La CI valide donc le plancher réaliste plutôt
que la version la plus récente installée sur une seule machine. À réexaminer
quand une matrice de versions deviendra utile (PAS-2 ou plus tard).

## D-11 — `fill()` durci sur `BelongsToTenant` : un tenant étranger est refusé, pas ignoré

**Contexte.** `TenantWriteIsolationTest::test_creer_avec_un_tenant_etranger_est_refuse`
était le seul test rouge après application de l'overlay : la création
n'échouait pas et une ligne était bel et bien écrite.

**Analyse.** `Membership` déclare `$fillable = ['user_id', 'role_id']`, et le trait
ajoute `tenant_id` à `$guarded`. Une clé `tenant_id` passée à `Model::create()`
était donc **écartée silencieusement par l'assignation de masse**, avant tout
événement de modèle. À l'événement `creating`, l'attribut avait déjà disparu :
le hook voyait `null`, posait le tenant courant, et la ligne partait en base.
L'appelant croyait écrire chez un autre tenant, la base écrivait chez lui —
sans exception, sans journal, sans trace. C'est exactement le mode de
défaillance que l'ADR-0006 prétend fermer (« un tenant_id étranger n'est plus
accepté parce que déjà renseigné : il est refusé »).

**Décision.** Surcharge de `fill()` dans le trait `BelongsToTenant` : si
`tenant_id` figure dans les attributs et diffère du contexte courant, on lève
`CrossTenantWriteException` ; s'il lui est identique, on l'écarte (le trait le
pose lui-même). Correction dans le **code applicatif**, conformément au mandat —
ni le test ni la migration n'ont été modifiés.

**Portée.** `fill()` est le seul point où l'intention de l'appelant est encore
visible : `newFromBuilder()` hydrate par `setRawAttributes()` et n'est pas
concerné, donc la lecture des modèles existants est inchangée. Le chemin
`$model->update(['tenant_id' => X])` passe désormais par ce refus, en cohérence
avec `TenantAwareBuilder::update()` qui bloque déjà la mise à jour massive.

## D-12 — `TenantIsolationTest` adapté, six cas conservés

Les six cas du PAS-1 sont conservés, y compris
`test_l_echappement_du_scope_est_explicite_et_voit_tout`, réécrit avec
`TenantBypass::run()`. Traductions appliquées : `TenantContext::set()` →
`app(TenantContext::class)->set()`, `clear()` → `forget()`,
`acrossAllTenants()` → `TenantBypass::run($raison, fn () => …withoutGlobalScope('tenant')…)`,
`RuntimeException` → `NoTenantResolvedException`. Un accesseur privé `context()`
a été ajouté, par cohérence avec `TenantWriteIsolationTest`.

## D-13 — `forgetScopedInstances()` conservé

Le piège annoncé ne s'est pas matérialisé : `app()->forgetScopedInstances()`
existe bien dans Laravel 12 et
`test_le_contexte_ne_survit_pas_a_l_execution_precedente` passe tel quel.
Aucun repli sur `forgetInstance(TenantContext::class)` n'a été nécessaire.

## D-14 — Décompte des tests : 26 verts, dont les 24 du pas

`php artisan test` annonce **26 tests verts**. Les 24 attendus par l'étape 6
(6 cas PAS-1 adaptés + 15 cas d'écriture + 3 cas architecturaux) y sont tous,
plus les deux `ExampleTest` livrés par le squelette Laravel (`Tests\Unit` et
`Tests\Feature`). Ils sont conservés : les supprimer aurait été une modification
non demandée, et le cas `Feature\ExampleTest` vérifie accessoirement que
l'application démarre encore.
