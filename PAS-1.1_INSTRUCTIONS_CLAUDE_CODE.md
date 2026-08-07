# PAS-1.1 — Instructions d'exécution pour Claude Code

**Dépôt :** `optimgov/Naja7i_backend_front` — état attendu : commit `c0ea420`
**Objet :** correctif de revue externe. Trois bloquants d'isolation, deux tables
Laravel perdues, protection structurelle du tenant plateforme, et la CI.

**Ce PAS-1.1 doit être vert avant que le PAS-2 démarre.**

---

## DÉCISIONS PRÉ-ARBITRÉES — ne pose aucune question sur ces points

Tu as mandat pour trancher seul. Applique la décision, note-la dans
`docs/DECISIONS-PAS-1.1.md`, continue.

| Situation | Décision |
|---|---|
| Colima / Docker arrêté | `colima start` puis `docker compose up -d`, attendre le healthcheck |
| Base `naja7i_test` absente | `docker compose exec -T postgres createdb -U naja7i naja7i_test` |
| Conflit sur un fichier de l'overlay | L'overlay fait foi, écraser sans demander |
| `app/Tenancy/TenantContext.php` existant | L'écraser : la version statique est précisément le défaut corrigé |
| `AppServiceProvider` déjà présent | Ne pas le toucher, enregistrer `TenancyServiceProvider` dans `bootstrap/providers.php` |
| Migration en échec sur un type/trigger déjà existant | `php artisan migrate:fresh` sur la base de dev, jamais ailleurs |
| Test rouge | Corriger le code applicatif, **jamais** le test ni la migration pour le faire passer |
| Pint signale du style | `./vendor/bin/pint` puis recommit |
| PostgreSQL indisponible | S'arrêter et le signaler. **Jamais de repli SQLite** — contrainte absolue |
| Choix de nommage, ordre des méthodes | Trancher toi-même, cohérence avec l'existant |

**Arrête-toi et demande uniquement si :** perte de données possible, dépôt cible
incorrect, ou PostgreSQL réellement indisponible.

---

## Étapes

1. **Vérifier le point de départ** : `git log --oneline` doit montrer `c0ea420`.
   `colima start` si besoin, puis `docker compose up -d`.

2. **Appliquer l'overlay** (écraser sans hésiter) :
   - `app/Tenancy/` — `TenantContext.php` (réécrit), `TenantAwareBuilder.php`,
     `TenantBypass.php`, `InteractsWithTenant.php`, `Exceptions/` (3 fichiers)
   - `app/Models/Concerns/BelongsToTenant.php` et `HasPublicUuid.php` (réécrits)
   - `app/Models/Membership.php` et `User.php` (réécrits)
   - `app/Http/Middleware/ResolveTenant.php` (réécrit)
   - `app/Providers/TenancyServiceProvider.php` (nouveau)
   - `database/migrations/0001_01_01_000110_*` et `0001_01_01_000160_*`
   - `tests/Feature/TenantWriteIsolationTest.php` et `TenancyArchitectureTest.php`
   - `.github/workflows/ci.yml`, `docs/adr/ADR-0006-*.md`, `docs/DETTE.md`, `docs/PAS.md`

3. **Enregistrer le provider** dans `bootstrap/providers.php` :
   ```php
   return [
       App\Providers\AppServiceProvider::class,
       App\Providers\TenancyServiceProvider::class,
   ];
   ```

4. **Adapter le test existant.** `tests/Feature/TenantIsolationTest.php` (PAS-1)
   utilise l'ancienne API statique. Le mettre à jour, sans en supprimer aucun cas :
   - `TenantContext::set($t)` → `app(TenantContext::class)->set($t)`
   - `TenantContext::clear()` → `app(TenantContext::class)->forget()`
   - `Membership::acrossAllTenants('...')` → `TenantBypass::run('raison de dix caractères minimum', fn () => Membership::withoutGlobalScope('tenant')->...)`
   - `RuntimeException` attendue → `NoTenantResolvedException`
   - Le cas `test_l_echappement_du_scope_est_explicite_et_voit_tout` doit rester,
     réécrit avec `TenantBypass`.

5. **Vérifier qu'aucune trace de l'ancienne API ne subsiste** :
   ```bash
   grep -rn "acrossAllTenants\|TenantContext::" app/ tests/ --include="*.php"
   ```
   Ne doivent rester que des appels `app(TenantContext::class)` et les
   constantes (`TenantContext::PLATFORM_TENANT_ID`).

6. **Migrer et tester** :
   ```bash
   php artisan migrate:fresh
   php artisan test
   ```
   Attendu : 6 tests PAS-1 adaptés + 15 tests d'écriture + 3 tests
   architecturaux. **24 verts, 0 rouge.**

7. **Style** : `./vendor/bin/pint`

8. **Commit et push** :
   ```bash
   git add -A
   git commit -m "PAS-1.1: correctif de revue — isolation des écritures, TenantContext scoped, TenantBypass journalisé + test architectural, password_reset_tokens/sessions restaurées, UUIDv7 plateforme, trigger de protection, CI PostgreSQL"
   git push origin main
   ```

9. **Vérifier que la CI passe au vert** sur GitHub. Si elle échoue, corriger et
   repousser — un PAS-1.1 dont la CI est rouge n'est pas livré.

---

## Pièges spécifiques à ce pas

- **`app()->forgetScopedInstances()`** est utilisé dans un test pour simuler la
  fin d'un cycle. Si la méthode n'existe pas dans la version de Laravel
  installée, la remplacer par `app()->forgetInstance(TenantContext::class)` —
  et le noter dans le fichier de décisions.
- **Le trigger plpgsql** fait échouer les suppressions du tenant plateforme avec
  une `QueryException`. C'est le comportement voulu, testé. Ne pas « assouplir »
  le trigger si un test de nettoyage échoue : c'est le test de nettoyage qui doit
  éviter de toucher le tenant plateforme.
- **Le test architectural lit `app/`.** Si tu ajoutes une classe légitime qui a
  besoin du bypass, ajoute-la à `ALLOWED_FILES` — mais demande-toi d'abord si
  `TenantBypass::run()` ne suffit pas. Dans 95 % des cas, si.
- **Ne pas réintroduire de cache statique** pour éviter la requête de résolution
  du tenant à chaque appel (DET-02). Ce serait recréer exactement le bloquant
  qu'on vient de corriger.
