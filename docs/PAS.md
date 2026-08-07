# Journal des pas de construction

| Pas | Contenu | Statut |
|---|---|---|
| 1 | Fondations : Laravel API-only, extensions PG, tenants, users globaux, roles ×7, memberships, scope tenant, 6 tests d'isolation en lecture, ADR 0001-0003 | livré · `c0ea420` |
| 1.1 | **Correctif de revue** : isolation des écritures, `TenantContext` scoped, `TenantBypass` + test architectural, `password_reset_tokens` et `sessions` restaurées, UUIDv7 plateforme, trigger de protection, CI PostgreSQL, ADR-0006 | livré 7 août 2026 |
| 2 | Authentification candidat : Sanctum cookies BFF, événements juridiques version-aware, inscription FR/AR, connexion, tests HTTP | révision en cours (2 arbitrages en attente) |
| 3 | Vérification e-mail et téléphone, mot de passe oublié, Google/Facebook | à venir |
