# PAS-1 — Instructions d'exécution pour Claude Code

**Dépôt cible :** `optimgov/Naja7i_backend_front` (vide au départ)
**Objet :** initialiser le backend front-office Naja7i — Laravel API-only, fondations multi-tenant + RBAC.
**Fichiers joints :** cet overlay (`pas1/`) contient migrations, modèles, middleware, tests, ADR et docker-compose. Il s'applique PAR-DESSUS un projet Laravel fraîchement créé.

## Étapes, dans l'ordre

1. **Scaffolding** — à la racine du dépôt cloné (vide) :
   ```bash
   composer create-project laravel/laravel:^12.0 .
   ```

2. **Supprimer la migration users par défaut** (remplacée par la nôtre) :
   ```bash
   rm database/migrations/0001_01_01_000000_create_users_table.php
   ```
   Conserver les migrations cache et jobs livrées par Laravel.

3. **Copier l'overlay** dans le projet en respectant l'arborescence :
   - `database/migrations/*` → `database/migrations/`
   - `app/Models/*`, `app/Tenancy/*`, `app/Http/Middleware/*` → mêmes chemins
   - `tests/Feature/TenantIsolationTest.php` → `tests/Feature/`
   - `docs/`, `docker-compose.yml`, `README.md` → racine
   Écraser `app/Models/User.php` livré par défaut.

4. **Configurer l'environnement** — dans `.env` :
   ```
   DB_CONNECTION=pgsql
   DB_HOST=127.0.0.1
   DB_PORT=5432
   DB_DATABASE=naja7i
   DB_USERNAME=naja7i
   DB_PASSWORD=naja7i_dev
   SESSION_DRIVER=redis
   CACHE_STORE=redis
   QUEUE_CONNECTION=redis
   REDIS_HOST=127.0.0.1
   ```
   Puis `docker compose up -d` et attendre le healthcheck postgres.

5. **Tests sur PostgreSQL, pas SQLite** — dans `phpunit.xml`, remplacer les
   variables DB par une base dédiée `naja7i_test` (la créer :
   `docker compose exec postgres createdb -U naja7i naja7i_test`).
   Les migrations utilisent citext, enums et index partiels PostgreSQL :
   SQLite ne peut pas les exécuter. C'est un choix délibéré (ADR-0002) —
   ne pas « corriger » en rendant les migrations agnostiques.

6. **Enregistrer le middleware** — dans `bootstrap/app.php` :
   ```php
   use App\Http\Middleware\ResolveTenant;

   ->withMiddleware(function (Middleware $middleware) {
       $middleware->api(append: [ResolveTenant::class]);
   })
   ```

7. **Vérifier** :
   ```bash
   php artisan migrate          # 4 migrations Pas-1 + cache/jobs Laravel
   php artisan test             # TenantIsolationTest : 6 tests verts
   ```
   Vérifier en base : `tenants` contient exactement 1 ligne (platform, id=1),
   `roles` contient 7 lignes.

8. **Commit** :
   ```bash
   git add -A
   git commit -m "PAS-1: fondations Laravel API-only — tenancy (tenant plateforme, scope BelongsToTenant) + RBAC (7 rôles, memberships) + tests d'isolation + ADR 0001-0003"
   git push origin main
   ```

## Règles à respecter dans TOUTE la suite du développement

- **404, jamais 403** pour une ressource d'un autre tenant.
- Toute nouvelle table de la colonne « isolée » (matrice v1.3 §1.4) porte
  `tenant_id` + trait `BelongsToTenant` + un cas dans `TenantIsolationTest`.
- Les tables du catalogue ne portent JAMAIS `tenant_id`.
- L'`id` bigint interne n'est jamais exposé ; seul l'`uuid` (UUIDv7) sort.
- Aucun contrôle d'accès côté frontend ne fait foi ; policies serveur uniquement.

## Ce que ce Pas ne fait PAS (volontairement)

Pas d'endpoints HTTP, pas de Sanctum, pas d'OTP, pas d'identities sociales,
pas de consents, pas de profil candidat. C'est le Pas 2. On vérifie d'abord
que les fondations tiennent : migrations propres, isolation prouvée par tests.
